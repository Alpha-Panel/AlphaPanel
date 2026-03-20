<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\FtpUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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
        $ftpUser->delete();
        $this->syncUsersEnv();
        $this->recreatePhpContainers();
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
        $containers = [
            config('panel.php_code_server_container', 'php-code-server'),
            'frankenphp',
        ];

        foreach ($containers as $container) {
            try {
                $this->portainer->restartContainer($container);
            } catch (\Exception $e) {
                Log::error("Failed to restart {$container} container: {$e->getMessage()}");
            }
        }
    }

    /**
     * Recreate PHP containers using docker compose (for user removal).
     */
    public function recreatePhpContainers(): void
    {
        $composeProjectRoot = (string) config('panel.compose_project_root', '');
        $services = $this->getPhpComposeServices();

        if ($services === []) {
            Log::warning('Skipping compose recreate for PHP containers: no service names configured.');

            return;
        }

        if ($composeProjectRoot === '' || ! File::isDirectory($composeProjectRoot)) {
            Log::warning("Compose project root not found ({$composeProjectRoot}). Falling back to container restart.");
            $this->restartPhpContainers();

            return;
        }

        try {
            $removeResult = Process::path($composeProjectRoot)
                ->timeout(180)
                ->run(['docker', 'compose', 'rm', '-f', '-s', '-v', ...$services]);

            if ($removeResult->failed()) {
                Log::warning(
                    'docker compose rm failed for PHP container recreate: '.
                    trim($removeResult->errorOutput() ?: $removeResult->output())
                );
            }

            $upResult = Process::path($composeProjectRoot)
                ->timeout(180)
                ->run(['docker', 'compose', 'up', '-d', '--force-recreate', ...$services]);

            if ($upResult->failed()) {
                Log::error(
                    'docker compose up failed for PHP container recreate: '.
                    trim($upResult->errorOutput() ?: $upResult->output())
                );
                $this->restartPhpContainers();

                return;
            }

            Log::info('Recreated PHP containers via docker compose: '.implode(', ', $services));
        } catch (\Throwable $e) {
            Log::error("Failed to recreate PHP containers via compose: {$e->getMessage()}");
            $this->restartPhpContainers();
        }
    }

    /**
     * @return array<int, string>
     */
    private function getPhpComposeServices(): array
    {
        $services = [
            trim((string) config('panel.php_code_server_container', 'php-code-server')),
            'frankenphp',
        ];

        return array_values(array_unique(array_filter($services)));
    }
}
