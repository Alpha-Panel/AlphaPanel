<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\FtpUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class FtpUserService
{
    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Create an FTP user for a domain and sync the users.env file.
     */
    public function addUser(Domain $domain, string $username, string $password): FtpUser
    {
        $ftpUser = FtpUser::create([
            'domain_id' => $domain->id,
            'username' => $username,
            'home_path' => $domain->getBasePath(),
            'encrypted_password' => $password,
            'uid' => $this->getNextUid(),
        ]);

        $this->syncUsersEnv($ftpUser->username, $password);
        $this->restartFtpContainers();

        return $ftpUser;
    }

    /**
     * Update an FTP user's password and/or username.
     */
    public function updateUser(FtpUser $ftpUser, ?string $password = null, ?string $username = null): void
    {
        $oldUsername = $ftpUser->username;

        if ($username && $username !== $oldUsername) {
            $ftpUser->update(['username' => $username]);
        }

        if ($password) {
            $ftpUser->update(['encrypted_password' => $password]);
        }

        $this->syncUsersEnv(
            targetUsername: $ftpUser->username,
            targetPassword: $password,
            oldUsername: ($username && $username !== $oldUsername) ? $oldUsername : null,
        );
        $this->restartFtpContainers();
    }

    /**
     * Update FTP user's home path (used during domain rename).
     */
    public function updateHomePath(FtpUser $ftpUser, string $newPath): void
    {
        $ftpUser->update(['home_path' => $newPath]);
        $this->syncUsersEnv();
        $this->restartFtpContainers();
    }

    /**
     * Remove an FTP user and sync the users.env file.
     */
    public function removeUser(FtpUser $ftpUser): void
    {
        $ftpUser->delete();
        $this->syncUsersEnv();
        $this->recreateFtpContainers();
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
     * Merges file-based entries with DB records: file entries are kept as-is,
     * DB entries are added or updated on top. Only DB-managed entries get modified.
     */
    public function syncUsersEnv(
        ?string $targetUsername = null,
        ?string $targetPassword = null,
        ?string $oldUsername = null,
    ): void {
        $existingEntries = $this->parseUsersEnv();

        // Build a map keyed by username from the existing file
        $fileMap = [];
        foreach ($existingEntries as $entry) {
            $fileMap[$entry['username']] = $entry;
        }

        // Handle username rename in the file
        if ($oldUsername && $targetUsername && isset($fileMap[$oldUsername])) {
            $renamed = $fileMap[$oldUsername];
            $renamed['username'] = $targetUsername;
            unset($fileMap[$oldUsername]);
            $fileMap[$targetUsername] = $renamed;
        }

        // Apply new password if provided
        if ($targetUsername && $targetPassword && isset($fileMap[$targetUsername])) {
            $fileMap[$targetUsername]['password'] = $targetPassword;
        }

        // Get all DB-managed usernames
        $dbUsers = FtpUser::all();
        $dbUsernames = $dbUsers->pluck('username')->all();

        // Merge: start with existing file entries (preserving non-DB users),
        // then add/update DB-managed entries
        $merged = [];

        // Keep all file entries that are NOT managed by DB (external users)
        foreach ($fileMap as $username => $entry) {
            if (! in_array($username, $dbUsernames)) {
                $merged[$username] = "{$entry['username']}|{$entry['password']}|{$entry['path']}|{$entry['uid']}";
            }
        }

        // Add/update DB-managed entries
        foreach ($dbUsers as $ftpUser) {
            $password = $fileMap[$ftpUser->username]['password'] ?? 'CHANGE_ME';

            if ($targetUsername === $ftpUser->username && $targetPassword) {
                $password = $targetPassword;
            }

            $merged[$ftpUser->username] = "{$ftpUser->username}|{$password}|{$ftpUser->home_path}|{$ftpUser->uid}";
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
     * Restart the FTP and php-code-server containers.
     */
    public function restartFtpContainers(): void
    {
        try {
            $this->portainer->restartContainer(config('panel.ftp_container', 'ftp-server'));
        } catch (\Exception $e) {
            Log::error("Failed to restart FTP container: {$e->getMessage()}");
        }

        try {
            $this->portainer->restartContainer(config('panel.php_code_server_container', 'php-code-server'));
        } catch (\Exception $e) {
            Log::error("Failed to restart php-code-server container: {$e->getMessage()}");
        }
    }

    /**
     * Recreate FTP and php-code-server containers using docker compose.
     *
     * This is the service-scoped equivalent of:
     * - down -v
     * - up -d --force-recreate
     */
    public function recreateFtpContainers(): void
    {
        $composeProjectRoot = (string) config('panel.compose_project_root', '');
        $services = $this->getComposeServices();

        if ($services === []) {
            Log::warning('Skipping compose recreate for FTP containers: no service names configured.');

            return;
        }

        if ($composeProjectRoot === '' || ! File::isDirectory($composeProjectRoot)) {
            Log::warning("Compose project root not found ({$composeProjectRoot}). Falling back to container restart.");
            $this->restartFtpContainers();

            return;
        }

        try {
            $removeResult = Process::path($composeProjectRoot)
                ->timeout(180)
                ->run(['docker', 'compose', 'rm', '-f', '-s', '-v', ...$services]);

            if ($removeResult->failed()) {
                Log::warning(
                    'docker compose rm failed for FTP stack recreate: '.
                    trim($removeResult->errorOutput() ?: $removeResult->output())
                );
            }

            $upResult = Process::path($composeProjectRoot)
                ->timeout(180)
                ->run(['docker', 'compose', 'up', '-d', '--force-recreate', ...$services]);

            if ($upResult->failed()) {
                Log::error(
                    'docker compose up failed for FTP stack recreate: '.
                    trim($upResult->errorOutput() ?: $upResult->output())
                );
                $this->restartFtpContainers();

                return;
            }

            Log::info('Recreated FTP-related containers via docker compose: '.implode(', ', $services));
        } catch (\Throwable $e) {
            Log::error("Failed to recreate FTP-related containers via compose: {$e->getMessage()}");
            $this->restartFtpContainers();
        }
    }

    /**
     * @return array<int, string>
     */
    private function getComposeServices(): array
    {
        $services = [
            trim((string) config('panel.ftp_container', 'ftp-server')),
            trim((string) config('panel.php_code_server_container', 'php-code-server')),
        ];

        return array_values(array_unique(array_filter($services)));
    }
}
