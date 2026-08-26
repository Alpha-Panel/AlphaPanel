<?php

namespace App\Services;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\FtpUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FtpUserService
{
    private const DUMMY_PASSWORD = '12345';

    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Create an FTP user for a domain and sync the users.env file.
     *
     * ProFTPD authenticates via MySQL (password column).
     * The users.env file is only used for system user creation in PHP containers.
     */
    public function addUser(Domain $domain, string $username, string $password): FtpUser
    {
        $uid = $this->getNextUid();

        $ftpUser = FtpUser::create([
            'domain_id' => $domain->id,
            'username' => $username,
            'homedir' => $domain->getBasePath(),
            'encrypted_password' => $password,
            'password' => $this->hashPasswordForFtp($password),
            'uid' => $uid,
            'gid' => $uid,
            'shell' => '/bin/bash',
        ]);

        $this->syncUsersEnv(targetUsername: $ftpUser->username, oldUsername: null);
        $this->restartPhpContainers();

        return $ftpUser;
    }

    /**
     * Update an FTP user's password and/or username.
     *
     * Password changes update MySQL only — ProFTPD reads live, no restart needed.
     * Username changes require PHP container restart for system user rename.
     */
    public function updateUser(FtpUser $ftpUser, ?string $password = null, ?string $username = null): void
    {
        $oldUsername = $ftpUser->username;
        $usernameChanged = $username && $username !== $oldUsername;

        if ($usernameChanged) {
            $ftpUser->update(['username' => $username]);
        }

        if ($password) {
            $ftpUser->update([
                'encrypted_password' => $password,
                'password' => $this->hashPasswordForFtp($password),
            ]);
        }

        $this->syncUsersEnv(
            targetUsername: $ftpUser->username,
            oldUsername: $usernameChanged ? $oldUsername : null,
        );

        if ($usernameChanged) {
            $this->restartPhpContainers();
        }
    }

    /**
     * Update FTP user's homedir (used during domain rename).
     */
    public function updateHomedir(FtpUser $ftpUser, string $newPath): void
    {
        $ftpUser->update(['homedir' => $newPath]);
        $this->syncUsersEnv();
        $this->restartPhpContainers();
    }

    /**
     * Remove an FTP user and sync the users.env file.
     */
    public function removeUser(FtpUser $ftpUser): void
    {
        $username = $ftpUser->username;
        $uid = $ftpUser->uid !== null ? (int) $ftpUser->uid : null;

        $ftpUser->delete();
        $this->syncUsersEnv();
        $this->removeSystemUser($username, $uid);
    }

    /**
     * Fix file ownership (chown) for a domain's base directory.
     *
     * Runs inside the PHP container that serves the domain. The .user.ini is
     * immutable, so the flag is cleared before the bulk chown and restored
     * (root-owned, 444, +i) afterwards so the site owner cannot tamper with it.
     *
     * @return string Audit summary of the command that ran.
     *
     * @throws \RuntimeException When the domain has no FTP user, or the chown fails.
     */
    public function fixPermissions(Domain $domain): string
    {
        $domain->loadMissing('ftpUser');

        if (! $domain->ftpUser) {
            throw new \RuntimeException('No FTP user exists for this domain.');
        }

        $username = $domain->ftpUser->username;
        $basePath = escapeshellarg($domain->getBasePath());
        $userIniPath = escapeshellarg("{$domain->getWebRootPath()}/.user.ini");

        $container = $domain->type === DomainType::ApacheReverseProxy
            ? 'php-code-server'
            : 'frankenphp';

        // Unlock .user.ini before bulk chown (immutable flag prevents ownership change)
        $this->portainer->execInContainer(
            $container,
            ['sh', '-c', "chattr -i {$userIniPath} 2>/dev/null || true"],
        );

        $result = $this->portainer->execInContainer(
            $container,
            ['sh', '-c', 'chown '.escapeshellarg($username).":www-data -R {$basePath}"],
            300,
        );

        if (! $result->isSuccessful()) {
            $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);

            throw new \RuntimeException($error !== '' ? $error : 'Unknown error.');
        }

        // Relock .user.ini — root-owned and immutable so site owner cannot tamper
        $this->portainer->execInContainer(
            $container,
            ['sh', '-c', "chown root:root {$userIniPath} && chmod 444 {$userIniPath} && chattr +i {$userIniPath} 2>/dev/null || true"],
        );

        return "chown {$username}:www-data -R on {$domain->getBasePath()}";
    }

    /**
     * Generate a ProFTPD-compatible SHA256 password hash.
     *
     * Format: {sha256} + base64(raw_sha256_bytes)
     */
    public function hashPasswordForFtp(string $password): string
    {
        return '{sha256}'.base64_encode(hex2bin(hash('sha256', $password)));
    }

    /**
     * Parse the current users.env file into an array of entries.
     *
     * @return array<int, array{username: string, password: string, path: string, uid: string}>
     */
    public function parseUsersEnv(): array
    {
        $path = config('panel.ftp_users_env_path');

        if (! File::exists($path)) {
            return [];
        }

        $content = File::get($path);

        preg_match('/USERS="(.*)"/s', $content, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $raw = $matches[1];
        $raw = str_replace("\\\n", '', $raw);
        $raw = trim($raw);

        $entries = preg_split('/\s+/', $raw);
        $users = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (empty($entry)) {
                continue;
            }

            $parts = explode('|', $entry);
            if (count($parts) === 4) {
                $users[] = [
                    'username' => $parts[0],
                    'password' => $parts[1],
                    'path' => $parts[2],
                    'uid' => $parts[3],
                ];
            }
        }

        return $users;
    }

    /**
     * Sync the users.env file preserving existing entries not managed by the panel.
     *
     * DB-managed entries use a dummy password since ProFTPD authenticates via MySQL.
     * The users.env file is only used for system user creation in PHP containers.
     */
    public function syncUsersEnv(
        ?string $targetUsername = null,
        ?string $oldUsername = null,
    ): void {
        $existingEntries = $this->parseUsersEnv();

        $fileMap = [];
        foreach ($existingEntries as $entry) {
            $fileMap[$entry['username']] = $entry;
        }

        if ($oldUsername && $targetUsername && isset($fileMap[$oldUsername])) {
            $renamed = $fileMap[$oldUsername];
            $renamed['username'] = $targetUsername;
            unset($fileMap[$oldUsername]);
            $fileMap[$targetUsername] = $renamed;
        }

        $dbUsers = FtpUser::all();
        $dbUsernames = $dbUsers->pluck('username')->all();

        $merged = [];

        foreach ($fileMap as $username => $entry) {
            if (! in_array($username, $dbUsernames)) {
                $merged[$username] = "{$entry['username']}|{$entry['password']}|{$entry['path']}|{$entry['uid']}";
            }
        }

        foreach ($dbUsers as $ftpUser) {
            $merged[$ftpUser->username] = "{$ftpUser->username}|".self::DUMMY_PASSWORD."|{$ftpUser->homedir}|{$ftpUser->uid}";
        }

        $lines = array_values($merged);

        if (empty($lines)) {
            $content = 'USERS=""'."\n";
        } else {
            $first = array_shift($lines);
            $content = 'USERS="'.$first;

            foreach ($lines as $line) {
                $content .= " \\\n       {$line}";
            }

            $content .= ' "'."\n";
        }

        $path = config('panel.ftp_users_env_path');
        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $content);
        Log::info('FTP users.env synced: '.count($merged).' total entries ('.count($dbUsernames).' managed by panel).');
    }

    /**
     * Get the next available UID by checking both DB and the users.env file.
     */
    public function getNextUid(): int
    {
        $dbMax = FtpUser::max('uid') ?? 0;

        $fileMax = 0;
        foreach ($this->parseUsersEnv() as $entry) {
            $uid = (int) $entry['uid'];
            if ($uid > $fileMax) {
                $fileMax = $uid;
            }
        }

        $max = max($dbMax, $fileMax);

        return $max >= 1002 ? $max + 1 : 1002;
    }

    /**
     * Restart only the PHP containers (not FTP — ProFTPD reads from MySQL).
     */
    public function restartPhpContainers(): void
    {
        foreach ($this->phpContainerNames() as $container) {
            try {
                $this->portainer->restartContainer($container);
            } catch (\Exception $e) {
                Log::error("Failed to restart {$container} container: {$e->getMessage()}");
            }
        }
    }

    /**
     * Drop the Linux account of a removed FTP user inside the PHP containers.
     *
     * The container entrypoints only ever *create* accounts from users.env, so a
     * deleted user lingers in /etc/passwd until the container is rebuilt. That is
     * why this used to run `docker compose rm -f -s -v` plus
     * `up -d --force-recreate`, which also tore down `frankenphp` — the public web
     * server for every hosted site — for the ~5 minutes its two 180s timeouts
     * took. Dropping the account in place reaches the same end state with no
     * downtime for the other domains.
     *
     * The UID is removed as well as the name. Usernames created before
     * normalisation existed can be uppercase, start with a digit, or exceed 32
     * characters, and getNextUid() recycles a freed UID immediately — a leftover
     * account therefore collides with the next FTP user and makes
     * php-code-server's entrypoint (`useradd -u` under `set -e`) abort on restart.
     */
    public function removeSystemUser(string $username, ?int $uid = null): void
    {
        $username = trim($username);

        if ($username === '' && $uid === null) {
            Log::warning('Refusing to drop system user: neither a username nor a uid was given.');

            return;
        }

        $label = $username !== '' ? $username : "uid {$uid}";
        $commands = [];

        if ($username !== '') {
            $name = escapeshellarg($username);
            $commands[] = 'id '.$name.' >/dev/null 2>&1 && { deluser '.$name.' >/dev/null 2>&1 || userdel -f '.$name.' >/dev/null 2>&1; }';
            $commands[] = 'delgroup '.$name.' >/dev/null 2>&1 || groupdel '.$name.' >/dev/null 2>&1 || true';
        }

        if ($uid !== null) {
            $id = escapeshellarg((string) $uid);
            // Single-quoted PHP strings: $stale and $(...) reach the shell verbatim.
            $commands[] = 'stale=$(getent passwd '.$id.' | cut -d: -f1); [ -n "$stale" ] && { deluser "$stale" >/dev/null 2>&1 || userdel -f "$stale" >/dev/null 2>&1; }';
            $commands[] = 'staleg=$(getent group '.$id.' | cut -d: -f1); [ -n "$staleg" ] && { delgroup "$staleg" >/dev/null 2>&1 || groupdel "$staleg" >/dev/null 2>&1; }';
            $commands[] = 'getent passwd '.$id.' >/dev/null 2>&1 && echo REMAINS || echo GONE';
        } elseif ($username !== '') {
            $commands[] = 'id '.escapeshellarg($username).' >/dev/null 2>&1 && echo REMAINS || echo GONE';
        }

        $script = implode('; ', $commands).'; exit 0';

        foreach ($this->phpContainerNames() as $container) {
            try {
                $result = $this->portainer->execInContainer($container, ['sh', '-c', $script]);
            } catch (\Throwable $e) {
                Log::warning("Failed to drop system user {$label} from {$container}: {$e->getMessage()}");

                continue;
            }

            if (str_contains($result->output, 'REMAINS')) {
                Log::warning("System user {$label} still present in {$container} after removal attempt.");

                continue;
            }

            Log::info("Dropped system user {$label} from {$container}.");
        }
    }

    /**
     * @return array<int, string>
     */
    private function phpContainerNames(): array
    {
        return array_values(array_unique(array_filter([
            trim((string) config('panel.php_code_server_container', 'php-code-server')),
            'frankenphp',
        ])));
    }
}
