<?php

namespace App\Jobs;

use App\Events\BackupProgress;
use App\Models\AuditLog;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use PDO;

class BackupUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    private int $totalFiles = 0;

    private int $totalBytes = 0;

    public function __construct(
        public int $backupRunId,
    ) {}

    public function handle(GoogleDriveService $driveService): void
    {
        $run = BackupRun::findOrFail($this->backupRunId);
        $run->update(['status' => 'uploading']);

        $settings = BackupSetting::instance();

        if (! $settings->isConnected() || ! $settings->drive_folder_id) {
            Log::error('BackupUploadJob: Google Drive not configured');
            $run->update([
                'status' => 'failed',
                'error_message' => __('Google Drive is not connected or no folder selected.'),
                'finished_at' => now(),
            ]);
            BackupProgress::dispatch($run->id, 0, __('Google Drive is not connected or no folder selected.'), 'failed');

            return;
        }

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
            $this->backupWebsites($driveService, $dateFolderId, $tempBase, $run);

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
                'user_id' => $run->triggered_by,
                'action' => 'backup_upload_completed',
                'summary' => "Backup completed: {$run->type}, {$this->totalFiles} files uploaded",
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
                'type' => $run->type,
            ]);

            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            BackupProgress::dispatch($run->id, $run->progress_percent, $e->getMessage(), 'failed');

            AuditLog::create([
                'user_id' => $run->triggered_by,
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
