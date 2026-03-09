<?php

namespace App\Jobs;

use App\Events\BackupProgress;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class BackupUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

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

        $run = BackupRun::create([
            'type' => $this->type,
            'status' => 'uploading',
            'started_at' => now(),
            'triggered_by' => $this->triggeredBy,
        ]);

        try {
            // Phase 1: Create backup archives via shell scripts
            BackupProgress::dispatch($run->id, 5, 'Creating backup archives...', 'uploading');

            $result = Process::timeout(600)
                ->path('/opt/backup')
                ->run('sh web_backup.sh && sh mysql_backup.sh');

            if ($result->failed()) {
                throw new \RuntimeException("Backup script failed (exit {$result->exitCode()}): {$result->errorOutput()}");
            }

            BackupProgress::dispatch($run->id, 30, 'Archives created, starting upload...', 'uploading');

            // Phase 2: Upload to Google Drive
            $driveService->refreshTokenIfNeeded();

            $datetime = now()->format('d-M-Y');
            $backupBase = config('backup.local_backup_base');
            $dateDir = "{$backupBase}/{$datetime}";

            // Read server_name from server_info.sh
            $serverName = 'server';
            $infoResult = Process::timeout(5)
                ->path('/opt/backup')
                ->run('. ./server_info.sh && echo $server_name');

            $name = trim($infoResult->output());
            if ($name !== '') {
                $serverName = $name;
            }

            $uploadedAny = false;
            $totalPercent = 30;

            // Upload websites
            $websitesDir = "{$dateDir}/websites";
            if (is_dir($websitesDir)) {
                $uploadPath = "{$serverName}/{$datetime}/websites";
                $targetFolderId = $driveService->findOrCreateFolderPath($uploadPath, $settings->drive_folder_id);
                $this->uploadDirectory($driveService, $websitesDir, $targetFolderId, $run, 30, 65);
                $uploadedAny = true;
                $totalPercent = 65;
            }

            // Upload mysql
            $mysqlDir = "{$dateDir}/mysql";
            if (is_dir($mysqlDir)) {
                $uploadPath = "{$serverName}/{$datetime}/mysql";
                $targetFolderId = $driveService->findOrCreateFolderPath($uploadPath, $settings->drive_folder_id);
                $this->uploadDirectory($driveService, $mysqlDir, $targetFolderId, $run, 65, 95);
                $uploadedAny = true;
                $totalPercent = 95;
            }

            if (! $uploadedAny) {
                throw new \RuntimeException("No backup files found in {$dateDir}");
            }

            // Phase 3: Clean old backups from Drive
            BackupProgress::dispatch($run->id, 95, 'Cleaning old backups...', 'uploading');
            $driveService->deleteOldBackups($settings->drive_folder_id, $settings->backup_retention_days);

            // Cleanup local files
            $this->cleanupLocalDir($websitesDir);
            $this->cleanupLocalDir($mysqlDir);
            @rmdir($dateDir);

            $run->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'finished_at' => now(),
            ]);

            $settings->update(['last_backup_at' => now()]);

            BackupProgress::dispatch($run->id, 100, 'Backup complete', 'completed');

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'backup_upload_completed',
                'summary' => "Backup completed: {$this->type}",
            ]);
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
        }
    }

    private function cleanupLocalDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob("{$dir}/*") as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    private function uploadDirectory(GoogleDriveService $driveService, string $path, string $folderId, BackupRun $run, int $progressFrom = 0, int $progressTo = 100): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                $this->uploadFile($driveService, $path, $folderId, $run, 1, 1, $progressFrom, $progressTo);
            }

            return;
        }

        $files = glob(rtrim($path, '/').'/*');
        $fileList = array_values(array_filter($files, 'is_file'));
        $totalFiles = count($fileList);

        foreach ($fileList as $i => $file) {
            $this->uploadFile($driveService, $file, $folderId, $run, $i + 1, $totalFiles, $progressFrom, $progressTo);
        }
    }

    private function uploadFile(GoogleDriveService $driveService, string $filePath, string $folderId, BackupRun $run, int $fileIndex, int $totalFiles, int $progressFrom = 0, int $progressTo = 100): void
    {
        $fileName = basename($filePath);
        $range = $progressTo - $progressFrom;

        $result = $driveService->uploadFile($filePath, $folderId, function (int $percent) use ($run, $fileIndex, $totalFiles, $fileName, $progressFrom, $range) {
            $fileProgress = (($fileIndex - 1) + ($percent / 100)) / max(1, $totalFiles);
            $overallPercent = (int) round($progressFrom + ($fileProgress * $range));

            $run->update(['progress_percent' => min(99, $overallPercent)]);
            BackupProgress::dispatch($run->id, min(99, $overallPercent), "Uploading {$fileName}: {$percent}%");
        });

        $run->update([
            'file_name' => $fileName,
            'file_size_bytes' => filesize($filePath),
            'drive_file_id' => $result['id'],
        ]);
    }
}
