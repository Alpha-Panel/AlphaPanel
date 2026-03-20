<?php

namespace App\Jobs;

use App\Events\BackupProgress;
use App\Models\AuditLog;
use App\Models\BackupFileManifest;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\User;
use App\Notifications\BackupNotification;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BackupUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    private int $totalFiles = 0;

    private int $totalBytes = 0;

    public function __construct(
        public string $type = 'manual',
        public ?int $triggeredBy = null,
    ) {}

    public function handle(GoogleDriveService $driveService): void
    {
        $settings = BackupSetting::instance();

        if (! $settings->isConnected() || ! $settings->drive_folder_id) {
            Log::error('BackupUploadJob: Google Drive not configured');

            return;
        }

        $backupMode = $settings->backup_mode ?? 'full';

        $run = BackupRun::create([
            'type' => $this->type,
            'backup_mode' => $backupMode,
            'status' => 'uploading',
            'started_at' => now(),
            'triggered_by' => $this->triggeredBy,
        ]);

        $datetime = now()->format('d-M-Y');
        $tempBase = config('backup.local_backup_base').'/'.$datetime;
        $this->totalFiles = 0;
        $this->totalBytes = 0;
        $backupsUrl = route('backups.index');

        // Notify admins: backup started
        $admins = User::where('admin', true)->get();
        Notification::send($admins, new BackupNotification(
            'info',
            __('Backup Started'),
            __('A backup operation has started.'),
            $backupsUrl,
        ));

        try {
            $driveService->refreshTokenIfNeeded();

            // Create date folder on Drive: <drive_folder>/09-Mar-2026/
            $dateFolderId = $driveService->findOrCreateFolderPath($datetime, $settings->drive_folder_id);

            // Store the date folder ID for later browsing
            $run->update(['drive_file_id' => $dateFolderId]);

            // Phase 1: MySQL backups (0-50%)
            if ($this->isCancelled($run)) {
                return;
            }
            BackupProgress::dispatch($run->id, 2, __('Starting database backups...'), 'uploading');
            $this->backupDatabases($driveService, $dateFolderId, $tempBase, $run);

            // Phase 2: Website backups (50-95%)
            if ($this->isCancelled($run)) {
                return;
            }
            BackupProgress::dispatch($run->id, 50, __('Starting website backups...'), 'uploading');

            if ($backupMode === 'incremental') {
                $this->backupWebsitesIncremental($driveService, $dateFolderId, $run);
            } else {
                $this->backupWebsites($driveService, $dateFolderId, $tempBase, $run);
            }

            // Phase 3: Cleanup old backups on Drive (95-99%)
            if ($this->isCancelled($run)) {
                return;
            }
            BackupProgress::dispatch($run->id, 95, __('Cleaning old backups...'), 'uploading');
            $driveService->deleteOldBackups($settings->drive_folder_id, $settings->backup_retention_days);

            // Done
            $run->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'file_name' => "{$this->totalFiles} files ({$datetime})",
                'file_size_bytes' => $this->totalBytes,
                'finished_at' => now(),
            ]);

            $settings->update(['last_backup_at' => now()]);
            BackupProgress::dispatch($run->id, 100, __('Backup complete'), 'completed');

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'backup_upload_completed',
                'summary' => "Backup completed: {$this->type}, {$this->totalFiles} files uploaded",
            ]);

            // Notify admins: backup completed
            Notification::send($admins, new BackupNotification(
                'success',
                __('Backup Completed'),
                __('Backup completed successfully. :count files uploaded.', ['count' => $this->totalFiles]),
                $backupsUrl,
            ));
        } catch (\Throwable $e) {
            Log::error('BackupUploadJob failed', [
                'error' => $e->getMessage(),
                'type' => $this->type,
            ]);

            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            BackupProgress::dispatch($run->id, $run->progress_percent, $e->getMessage(), 'failed');

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'backup_upload_failed',
                'summary' => "Backup failed: {$e->getMessage()}",
            ]);

            // Notify admins: backup failed
            Notification::send($admins, new BackupNotification(
                'error',
                __('Backup Failed'),
                __('Backup failed: :error', ['error' => $e->getMessage()]),
                $backupsUrl,
            ));
        } finally {
            // Cleanup temp directory
            $this->cleanupDir($tempBase);
        }
    }

    private function backupWebsites(GoogleDriveService $driveService, string $dateFolderId, string $tempBase, BackupRun $run): void
    {
        $vhostsPath = config('backup.vhosts_path', '/var/www/vhosts');
        $exclude = config('backup.vhosts_exclude', []);

        if (! is_dir($vhostsPath)) {
            Log::warning("Vhosts path not found: {$vhostsPath}");

            return;
        }

        // scandir captures hidden (dot) directories too, glob does not
        $dirs = array_filter(
            array_map(fn ($name) => "{$vhostsPath}/{$name}", scandir($vhostsPath) ?: []),
            fn ($path) => is_dir($path) && ! in_array(basename($path), ['.', '..', ...$exclude])
        );

        if (empty($dirs)) {
            return;
        }

        $websitesFolderId = $driveService->findOrCreateFolderPath('websites', $dateFolderId);
        $tempDir = "{$tempBase}/websites";
        @mkdir($tempDir, 0755, true);

        $total = count($dirs);

        foreach ($dirs as $i => $dir) {
            $siteName = basename($dir);
            $archivePath = "{$tempDir}/{$siteName}.tar.gz";

            // Create tar.gz
            $result = Process::timeout(300)->run(
                sprintf('tar -czf %s -C %s %s', escapeshellarg($archivePath), escapeshellarg($vhostsPath), escapeshellarg($siteName))
            );

            if ($result->failed()) {
                Log::warning("Failed to archive {$siteName}: {$result->errorOutput()}");

                continue;
            }

            // Upload to Drive
            $percent = (int) round(2 + (($i + 1) / $total * 48));
            BackupProgress::dispatch($run->id, $percent, __('Uploading :name...', ['name' => "{$siteName}.tar.gz"]), 'uploading');

            $uploadResult = $driveService->uploadFile($archivePath, $websitesFolderId, function (int $chunkPercent) use ($run, $i, $total) {
                $fileProgress = ($i + ($chunkPercent / 100)) / $total;
                $overallPercent = (int) round(2 + ($fileProgress * 48));
                $run->update(['progress_percent' => min(49, $overallPercent)]);
            });

            $this->totalFiles++;
            $this->totalBytes += filesize($archivePath) ?: 0;

            // Remove local archive immediately after upload
            @unlink($archivePath);
        }
    }

    private function backupDatabases(GoogleDriveService $driveService, string $dateFolderId, string $tempBase, BackupRun $run): void
    {
        $mysqlConfig = config('backup.mysql');
        $host = $mysqlConfig['host'];
        $username = $mysqlConfig['username'];
        $password = $mysqlConfig['password'];
        $exclude = $mysqlConfig['exclude'] ?? [];

        // Get database list via PDO (disable SSL verification for Docker-internal connections)
        try {
            $pdo = new PDO("mysql:host={$host}", $username, $password, [
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ]);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("SET SESSION sql_mode = ''");
            $databases = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            Log::warning("Failed to list databases: {$e->getMessage()}");

            return;
        }

        $databases = array_filter($databases, fn ($db) => ! in_array($db, $exclude));
        $databases = array_values($databases);

        if (empty($databases)) {
            return;
        }

        $mysqlFolderId = $driveService->findOrCreateFolderPath('mysql', $dateFolderId);
        $tempDir = "{$tempBase}/mysql";
        @mkdir($tempDir, 0755, true);

        $total = count($databases);

        foreach ($databases as $i => $dbName) {
            $sqlPath = "{$tempDir}/{$dbName}.sql";
            $archivePath = "{$tempDir}/{$dbName}.tar.gz";

            // mysqldump — disable SSL to avoid self-signed cert errors on Docker network
            $dumpCmd = sprintf(
                'mysqldump --host=%s -u%s -p%s --skip-ssl --single-transaction --quick %s > %s',
                escapeshellarg($host),
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($dbName),
                escapeshellarg($sqlPath)
            );

            $result = Process::timeout(300)->run($dumpCmd);

            if ($result->failed() || ! file_exists($sqlPath) || filesize($sqlPath) === 0) {
                Log::warning("Failed to dump {$dbName}: {$result->errorOutput()}");
                @unlink($sqlPath);

                continue;
            }

            // Compress to tar.gz
            $tarResult = Process::timeout(120)->run(
                sprintf('tar -czf %s -C %s %s', escapeshellarg($archivePath), escapeshellarg($tempDir), escapeshellarg("{$dbName}.sql"))
            );

            @unlink($sqlPath); // remove raw .sql immediately

            if ($tarResult->failed()) {
                Log::warning("Failed to compress {$dbName}: {$tarResult->errorOutput()}");

                continue;
            }

            // Upload to Drive
            $percent = (int) round(50 + (($i + 1) / $total * 45));
            BackupProgress::dispatch($run->id, $percent, __('Uploading :name...', ['name' => "{$dbName}.tar.gz"]), 'uploading');

            $uploadResult = $driveService->uploadFile($archivePath, $mysqlFolderId, function (int $chunkPercent) use ($run, $i, $total) {
                $fileProgress = ($i + ($chunkPercent / 100)) / $total;
                $overallPercent = (int) round(50 + ($fileProgress * 45));
                $run->update(['progress_percent' => min(94, $overallPercent)]);
            });

            $this->totalFiles++;
            $this->totalBytes += filesize($archivePath) ?: 0;

            // Remove local archive immediately after upload
            @unlink($archivePath);
        }
    }

    private function backupWebsitesIncremental(GoogleDriveService $driveService, string $dateFolderId, BackupRun $run): void
    {
        $vhostsPath = config('backup.vhosts_path', '/var/www/vhosts');
        $exclude = config('backup.vhosts_exclude', []);

        if (! is_dir($vhostsPath)) {
            Log::warning("Vhosts path not found: {$vhostsPath}");

            return;
        }

        $dirs = array_filter(
            array_map(fn ($name) => "{$vhostsPath}/{$name}", scandir($vhostsPath) ?: []),
            fn ($path) => is_dir($path) && ! in_array(basename($path), ['.', '..', ...$exclude])
        );

        if (empty($dirs)) {
            return;
        }

        $websitesFolderId = $driveService->findOrCreateFolderPath('websites', $dateFolderId);
        $total = count($dirs);

        foreach ($dirs as $i => $dir) {
            if ($this->isCancelled($run)) {
                return;
            }

            $domain = basename($dir);
            $percent = (int) round(50 + (($i + 1) / $total * 45));
            BackupProgress::dispatch($run->id, $percent, __('Scanning :name...', ['name' => $domain]), 'uploading');

            // 1. Scan local files
            $localFiles = $this->scanLocalFiles($dir);

            // 2. Get previous known state from manifests
            $previousState = $this->getLastKnownState($domain);

            // 3. Diff: new, changed, deleted
            $toUpload = [];
            $toDelete = [];

            foreach ($localFiles as $relativePath => $fileInfo) {
                if (! isset($previousState[$relativePath])) {
                    // New file
                    $toUpload[$relativePath] = $fileInfo;
                } elseif (
                    $previousState[$relativePath]['file_size'] !== $fileInfo['size']
                    || $previousState[$relativePath]['file_mtime'] !== $fileInfo['mtime']
                ) {
                    // Changed file
                    $toUpload[$relativePath] = $fileInfo;
                }
            }

            foreach ($previousState as $relativePath => $manifestInfo) {
                if (! isset($localFiles[$relativePath])) {
                    $toDelete[] = $relativePath;
                }
            }

            if (empty($toUpload) && empty($toDelete)) {
                continue;
            }

            // 4. Upload changed/new files
            foreach ($toUpload as $relativePath => $fileInfo) {
                if ($this->isCancelled($run)) {
                    return;
                }

                $drivePath = "{$domain}/{$relativePath}";
                $driveDir = dirname($drivePath);
                $driveDirId = $driveDir !== '.'
                    ? $driveService->findOrCreateFolderPath($driveDir, $websitesFolderId)
                    : $websitesFolderId;

                $uploadResult = $driveService->uploadFile($fileInfo['full_path'], $driveDirId);

                BackupFileManifest::create([
                    'backup_run_id' => $run->id,
                    'domain' => $domain,
                    'relative_path' => $relativePath,
                    'file_size' => $fileInfo['size'],
                    'file_mtime' => $fileInfo['mtime'],
                    'drive_file_id' => $uploadResult['id'],
                    'action' => 'upload',
                    'created_at' => now(),
                ]);

                $this->totalFiles++;
                $this->totalBytes += $fileInfo['size'];
            }

            // 5. Mark deleted files
            foreach ($toDelete as $relativePath) {
                BackupFileManifest::create([
                    'backup_run_id' => $run->id,
                    'domain' => $domain,
                    'relative_path' => $relativePath,
                    'file_size' => 0,
                    'file_mtime' => 0,
                    'drive_file_id' => null,
                    'action' => 'delete',
                    'created_at' => now(),
                ]);
            }

            $run->update(['progress_percent' => min(94, $percent)]);
        }
    }

    /**
     * Scan local files recursively and return an associative array.
     *
     * @return array<string, array{size: int, mtime: int, full_path: string}>
     */
    private function scanLocalFiles(string $directory): array
    {
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $fullPath = $file->getPathname();
                $relativePath = ltrim(str_replace($directory, '', $fullPath), '/\\');
                // Normalize to forward slashes
                $relativePath = str_replace('\\', '/', $relativePath);

                $files[$relativePath] = [
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime(),
                    'full_path' => $fullPath,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to scan directory {$directory}: {$e->getMessage()}");
        }

        return $files;
    }

    /**
     * Get the last known state for a domain from all previous manifest entries.
     * Returns the latest entry per (domain, relative_path), excluding deleted files.
     *
     * @return array<string, array{file_size: int, file_mtime: int, drive_file_id: string|null}>
     */
    private function getLastKnownState(string $domain): array
    {
        $entries = DB::table('backup_file_manifests as m1')
            ->join(DB::raw('(SELECT domain, relative_path, MAX(id) as max_id FROM backup_file_manifests WHERE domain = '.DB::getPdo()->quote($domain).' GROUP BY domain, relative_path) as m2'), 'm1.id', '=', 'm2.max_id')
            ->where('m1.action', 'upload')
            ->select('m1.relative_path', 'm1.file_size', 'm1.file_mtime', 'm1.drive_file_id')
            ->get();

        $state = [];

        foreach ($entries as $entry) {
            $state[$entry->relative_path] = [
                'file_size' => (int) $entry->file_size,
                'file_mtime' => (int) $entry->file_mtime,
                'drive_file_id' => $entry->drive_file_id,
            ];
        }

        return $state;
    }

    private function isCancelled(BackupRun $run): bool
    {
        $run->refresh();

        return $run->status === 'cancelled';
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob("{$dir}/*") as $item) {
            if (is_dir($item)) {
                $this->cleanupDir($item);
            } else {
                @unlink($item);
            }
        }

        @rmdir($dir);
    }
}
