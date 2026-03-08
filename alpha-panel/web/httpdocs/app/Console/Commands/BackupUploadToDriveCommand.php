<?php

namespace App\Console\Commands;

use App\Events\BackupProgress;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupUploadToDriveCommand extends Command
{
    protected $signature = 'backup:upload-to-drive
                            {local_path : Local directory or file to upload}
                            {--upload-path= : Subfolder path on Google Drive}
                            {--remove-old-backups : Remove backups older than retention period}
                            {--type=manual : Backup type (web, mysql, manual)}
                            {--cleanup : Remove local files after upload}';

    protected $description = 'Upload local backup files to Google Drive';

    public function handle(GoogleDriveService $driveService): int
    {
        $settings = BackupSetting::instance();

        if (! $settings->isConnected()) {
            $this->error('Google Drive is not connected. Connect via the panel UI first.');

            return self::FAILURE;
        }

        if (! $settings->drive_folder_id) {
            $this->error('No Drive folder selected. Configure via the panel UI.');

            return self::FAILURE;
        }

        $localPath = $this->argument('local_path');
        $uploadPath = $this->option('upload-path');
        $removeOld = $this->option('remove-old-backups');
        $type = $this->option('type');
        $cleanup = $this->option('cleanup');

        // Handle --remove-old-backups (can run standalone)
        if ($removeOld) {
            return $this->handleRemoveOldBackups($driveService, $settings);
        }

        if (! is_dir($localPath) && ! is_file($localPath)) {
            $this->error("Path does not exist: {$localPath}");

            return self::FAILURE;
        }

        $run = BackupRun::create([
            'type' => $type,
            'status' => 'uploading',
            'started_at' => now(),
        ]);

        try {
            $driveService->refreshTokenIfNeeded();

            $targetFolderId = $settings->drive_folder_id;

            if ($uploadPath) {
                $this->info("Creating folder path: {$uploadPath}");
                $targetFolderId = $driveService->findOrCreateFolderPath($uploadPath, $settings->drive_folder_id);
            }

            $this->uploadPath($driveService, $localPath, $targetFolderId, $run);

            $run->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'finished_at' => now(),
            ]);

            $settings->update(['last_backup_at' => now()]);

            BackupProgress::dispatch($run->id, 100, 'Upload complete', 'completed');

            $this->info('Upload complete.');

            if ($cleanup && is_dir($localPath)) {
                $this->removeDirectory($localPath);
                $this->info("Cleaned up local directory: {$localPath}");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('Backup upload failed', [
                'error' => $e->getMessage(),
                'local_path' => $localPath,
                'type' => $type,
            ]);

            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            BackupProgress::dispatch($run->id, $run->progress_percent, $e->getMessage(), 'failed');

            $this->error("Upload failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function uploadPath(GoogleDriveService $driveService, string $localPath, string $folderId, BackupRun $run): void
    {
        if (is_file($localPath)) {
            $this->uploadSingleFile($driveService, $localPath, $folderId, $run);

            return;
        }

        $files = glob(rtrim($localPath, '/').'/*');
        $totalFiles = count(array_filter($files, 'is_file'));
        $uploadedFiles = 0;

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $fileName = basename($file);
            $this->info("Uploading: {$fileName}");

            $result = $driveService->uploadFile($file, $folderId, function (int $percent, string $name) use ($run, $uploadedFiles, $totalFiles) {
                $overallPercent = $totalFiles > 0
                    ? (int) round((($uploadedFiles / $totalFiles) * 100) + ($percent / $totalFiles))
                    : $percent;

                $run->update(['progress_percent' => min(99, $overallPercent)]);
                BackupProgress::dispatch($run->id, min(99, $overallPercent), "Uploading {$name}: {$percent}%");

                $this->getOutput()->write("\r  Progress: {$percent}%");
            });

            $uploadedFiles++;

            $run->update([
                'file_name' => $fileName,
                'file_size_bytes' => filesize($file),
                'drive_file_id' => $result['id'],
            ]);

            $this->newLine();
            $this->info("  Uploaded: {$fileName} (ID: {$result['id']})");
        }
    }

    private function uploadSingleFile(GoogleDriveService $driveService, string $filePath, string $folderId, BackupRun $run): void
    {
        $fileName = basename($filePath);
        $this->info("Uploading: {$fileName}");

        $result = $driveService->uploadFile($filePath, $folderId, function (int $percent, string $name) use ($run) {
            $run->update(['progress_percent' => min(99, $percent)]);
            BackupProgress::dispatch($run->id, min(99, $percent), "Uploading {$name}: {$percent}%");
            $this->getOutput()->write("\r  Progress: {$percent}%");
        });

        $run->update([
            'file_name' => $fileName,
            'file_size_bytes' => filesize($filePath),
            'drive_file_id' => $result['id'],
        ]);

        $this->newLine();
        $this->info("  Uploaded: {$fileName} (ID: {$result['id']})");
    }

    private function handleRemoveOldBackups(GoogleDriveService $driveService, BackupSetting $settings): int
    {
        try {
            $driveService->refreshTokenIfNeeded();

            $deleted = $driveService->deleteOldBackups(
                $settings->drive_folder_id,
                $settings->backup_retention_days
            );

            $this->info("Deleted {$deleted} old backup(s) from Google Drive.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to remove old backups: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
