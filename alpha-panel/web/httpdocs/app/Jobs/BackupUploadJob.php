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

    private int $itemsDone = 0;

    private int $itemsTotal = 0;

    private string $currentFileName = '';

    private int $currentFilePercent = 0;

    private float $lastBroadcastAt = 0;

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

            // Pre-count items for overall progress
            $vhostsPath = config('backup.vhosts_path', '/var/www/vhosts');
            $exclude = config('backup.vhosts_exclude', []);
            $websiteDirs = [];
            if (is_dir($vhostsPath)) {
                $websiteDirs = array_values(array_filter(
                    array_map(fn ($name) => "{$vhostsPath}/{$name}", scandir($vhostsPath) ?: []),
                    fn ($path) => is_dir($path) && ! in_array(basename($path), ['.', '..', ...$exclude])
                ));
            }

            $mysqlConfig = config('backup.mysql');
            $databases = [];
            try {
                $pdo = new PDO("mysql:host={$mysqlConfig['host']}", $mysqlConfig['username'], $mysqlConfig['password'], [
                    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                ]);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec("SET SESSION sql_mode = ''");
                $allDbs = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
                $databases = array_values(array_filter($allDbs, fn ($db) => ! in_array($db, $mysqlConfig['exclude'] ?? [])));
            } catch (\Throwable $e) {
                Log::warning("Failed to list databases for pre-count: {$e->getMessage()}");
            }

            $this->itemsTotal = count($websiteDirs) + count($databases);
            $this->itemsDone = 0;

            // Create date folder on Drive: <drive_folder>/09-Mar-2026/
            $dateFolderId = $driveService->findOrCreateFolderPath($datetime, $settings->drive_folder_id);

            // Store the date folder ID for later browsing
            $run->update(['drive_file_id' => $dateFolderId]);

            // Phase 1: Website backups
            if ($this->isCancelled($run)) {
                return;
            }
            $this->broadcastProgress($run, __('Starting website backups...'));
            $this->backupWebsites($driveService, $dateFolderId, $tempBase, $run, $websiteDirs);

            // Phase 2: MySQL backups
            if ($this->isCancelled($run)) {
                return;
            }
            $this->broadcastProgress($run, __('Starting database backups...'));
            $this->backupDatabases($driveService, $dateFolderId, $tempBase, $run, $databases);

            // Phase 3: Cleanup old backups on Drive (95-99%)
            if ($this->isCancelled($run)) {
                return;
            }
            $this->broadcastProgress($run, __('Cleaning old backups...'));
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
            BackupProgress::dispatch($run->id, 100, __('Backup complete'), 'completed', '', 0, $this->itemsDone, $this->itemsTotal);

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

    /** @param array<string> $dirs */
    private function backupWebsites(GoogleDriveService $driveService, string $dateFolderId, string $tempBase, BackupRun $run, array $dirs): void
    {
        if (empty($dirs)) {
            return;
        }

        $vhostsPath = config('backup.vhosts_path', '/var/www/vhosts');
        $websitesFolderId = $driveService->findOrCreateFolderPath('websites', $dateFolderId);
        $tempDir = "{$tempBase}/websites";
        @mkdir($tempDir, 0755, true);

        foreach ($dirs as $dir) {
            if ($this->isCancelled($run)) {
                return;
            }

            $siteName = basename($dir);
            $archivePath = "{$tempDir}/{$siteName}.tar.gz";
            $this->currentFileName = "{$siteName}.tar.gz";
            $this->currentFilePercent = 0;

            // Broadcast: preparing
            $this->broadcastProgress($run, __('Preparing :name...', ['name' => "{$siteName}.tar.gz"]));

            // Create tar.gz
            $result = Process::timeout(300)->run(
                sprintf('tar -czf %s -C %s %s', escapeshellarg($archivePath), escapeshellarg($vhostsPath), escapeshellarg($siteName))
            );

            if ($result->failed()) {
                Log::warning("Failed to archive {$siteName}: {$result->errorOutput()}");
                $this->itemsDone++;

                continue;
            }

            // Upload to Drive
            $this->currentFilePercent = 0;
            $this->broadcastProgress($run, __('Uploading :name...', ['name' => "{$siteName}.tar.gz"]));

            $driveService->uploadFile($archivePath, $websitesFolderId, function (int $chunkPercent) use ($run) {
                $this->currentFilePercent = $chunkPercent;
                $this->throttledBroadcast($run, __('Uploading :name...', ['name' => $this->currentFileName]));
            });

            $this->totalFiles++;
            $this->totalBytes += filesize($archivePath) ?: 0;
            $this->itemsDone++;
            $this->currentFilePercent = 100;

            // Remove local archive immediately after upload
            @unlink($archivePath);
        }
    }

    /** @param array<string> $databases */
    private function backupDatabases(GoogleDriveService $driveService, string $dateFolderId, string $tempBase, BackupRun $run, array $databases): void
    {
        if (empty($databases)) {
            return;
        }

        $mysqlConfig = config('backup.mysql');
        $host = $mysqlConfig['host'];
        $username = $mysqlConfig['username'];
        $password = $mysqlConfig['password'];

        $mysqlFolderId = $driveService->findOrCreateFolderPath('mysql', $dateFolderId);
        $tempDir = "{$tempBase}/mysql";
        @mkdir($tempDir, 0755, true);

        foreach ($databases as $dbName) {
            if ($this->isCancelled($run)) {
                return;
            }

            $sqlPath = "{$tempDir}/{$dbName}.sql";
            $archivePath = "{$tempDir}/{$dbName}.tar.gz";
            $this->currentFileName = "{$dbName}.tar.gz";
            $this->currentFilePercent = 0;

            // Broadcast: dumping
            $this->broadcastProgress($run, __('Dumping :name...', ['name' => $dbName]));

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
                $this->itemsDone++;

                continue;
            }

            // Compress to tar.gz
            $this->broadcastProgress($run, __('Compressing :name...', ['name' => "{$dbName}.tar.gz"]));

            $tarResult = Process::timeout(120)->run(
                sprintf('tar -czf %s -C %s %s', escapeshellarg($archivePath), escapeshellarg($tempDir), escapeshellarg("{$dbName}.sql"))
            );

            @unlink($sqlPath); // remove raw .sql immediately

            if ($tarResult->failed()) {
                Log::warning("Failed to compress {$dbName}: {$tarResult->errorOutput()}");
                $this->itemsDone++;

                continue;
            }

            // Upload to Drive
            $this->currentFilePercent = 0;
            $this->broadcastProgress($run, __('Uploading :name...', ['name' => "{$dbName}.tar.gz"]));

            $driveService->uploadFile($archivePath, $mysqlFolderId, function (int $chunkPercent) use ($run, $dbName) {
                $this->currentFilePercent = $chunkPercent;
                $this->throttledBroadcast($run, __('Uploading :name...', ['name' => "{$dbName}.tar.gz"]));
            });

            $this->totalFiles++;
            $this->totalBytes += filesize($archivePath) ?: 0;
            $this->itemsDone++;
            $this->currentFilePercent = 100;

            // Remove local archive immediately after upload
            @unlink($archivePath);
        }
    }

    private function broadcastProgress(BackupRun $run, string $message, string $status = 'uploading'): void
    {
        $overallPercent = $this->calculateOverallPercent();
        $run->update(['progress_percent' => $overallPercent]);

        BackupProgress::dispatch(
            $run->id,
            $overallPercent,
            $message,
            $status,
            $this->currentFileName,
            $this->currentFilePercent,
            $this->itemsDone,
            $this->itemsTotal,
        );

        $this->lastBroadcastAt = microtime(true);
    }

    private function throttledBroadcast(BackupRun $run, string $message): void
    {
        $now = microtime(true);
        if ($this->currentFilePercent === 0 || $this->currentFilePercent === 100 || ($now - $this->lastBroadcastAt) >= 2.0) {
            $this->broadcastProgress($run, $message);
        }
    }

    private function calculateOverallPercent(): int
    {
        if ($this->itemsTotal === 0) {
            return 2;
        }

        // Map items progress into 2-95 range (leaving room for init and cleanup)
        $ratio = $this->itemsDone / $this->itemsTotal;

        return (int) round(2 + ($ratio * 93));
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
