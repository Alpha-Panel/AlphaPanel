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

class BackupUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public string $localPath,
        public string $type = 'manual',
        public ?int $triggeredBy = null,
        public ?string $uploadPath = null,
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

        BackupProgress::dispatch($run->id, 0, 'Starting backup upload...', 'uploading');

        try {
            $driveService->refreshTokenIfNeeded();

            $targetFolderId = $settings->drive_folder_id;

            if ($this->uploadPath) {
                $targetFolderId = $driveService->findOrCreateFolderPath(
                    $this->uploadPath,
                    $settings->drive_folder_id
                );
            }

            $this->uploadDirectory($driveService, $this->localPath, $targetFolderId, $run);

            $run->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'finished_at' => now(),
            ]);

            $settings->update(['last_backup_at' => now()]);

            BackupProgress::dispatch($run->id, 100, 'Upload complete', 'completed');

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'backup_upload_completed',
                'summary' => "Backup upload completed: {$this->type}",
            ]);
        } catch (\Throwable $e) {
            Log::error('BackupUploadJob failed', [
                'error' => $e->getMessage(),
                'type' => $this->type,
                'local_path' => $this->localPath,
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
                'summary' => "Backup upload failed: {$e->getMessage()}",
            ]);
        }
    }

    private function uploadDirectory(GoogleDriveService $driveService, string $path, string $folderId, BackupRun $run): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                $this->uploadFile($driveService, $path, $folderId, $run, 1, 1);
            }

            return;
        }

        $files = glob(rtrim($path, '/').'/*');
        $fileList = array_filter($files, 'is_file');
        $totalFiles = count($fileList);
        $uploadedFiles = 0;

        foreach ($fileList as $file) {
            $uploadedFiles++;
            $this->uploadFile($driveService, $file, $folderId, $run, $uploadedFiles, $totalFiles);
        }
    }

    private function uploadFile(GoogleDriveService $driveService, string $filePath, string $folderId, BackupRun $run, int $fileIndex, int $totalFiles): void
    {
        $fileName = basename($filePath);

        $result = $driveService->uploadFile($filePath, $folderId, function (int $percent) use ($run, $fileIndex, $totalFiles, $fileName) {
            $overallPercent = $totalFiles > 0
                ? (int) round((($fileIndex - 1) / $totalFiles * 100) + ($percent / $totalFiles))
                : $percent;

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
