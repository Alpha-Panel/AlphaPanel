<?php

namespace App\Jobs;

use App\Events\RestoreProgress;
use App\Models\AuditLog;
use App\Models\BackupRestoreRun;
use App\Models\User;
use App\Notifications\BackupNotification;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

class BackupRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public int $restoreRunId,
    ) {}

    public function handle(GoogleDriveService $driveService): void
    {
        $restoreRun = BackupRestoreRun::findOrFail($this->restoreRunId);

        $restoreRun->update([
            'status' => 'downloading',
            'started_at' => now(),
        ]);

        $backupsUrl = route('backups.index');
        $admins = User::where('admin', true)->get();

        Notification::send($admins, new BackupNotification(
            'info',
            __('Restore Started'),
            __('Restoring :type ":target"...', ['type' => $restoreRun->restore_type, 'target' => $restoreRun->target]),
            $backupsUrl,
            'bx bx-revision',
        ));

        try {
            $driveService->refreshTokenIfNeeded();

            RestoreProgress::dispatch($restoreRun->id, 5, __('Downloading from Drive...'), 'downloading');

            if ($restoreRun->restore_type === 'database') {
                $this->restoreDatabase($driveService, $restoreRun);
            } elseif ($restoreRun->source_mode === 'incremental') {
                $this->restoreWebsiteFromIncremental($driveService, $restoreRun);
            } else {
                $this->restoreWebsiteFromFull($driveService, $restoreRun);
            }

            $restoreRun->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'finished_at' => now(),
            ]);

            RestoreProgress::dispatch($restoreRun->id, 100, __('Restore complete'), 'completed');

            AuditLog::create([
                'user_id' => $restoreRun->triggered_by,
                'action' => 'backup_restore_completed',
                'summary' => "Restore completed: {$restoreRun->restore_type} — {$restoreRun->target}",
            ]);

            Notification::send($admins, new BackupNotification(
                'success',
                __('Restore Completed'),
                __('Successfully restored :type ":target".', ['type' => $restoreRun->restore_type, 'target' => $restoreRun->target]),
                $backupsUrl,
                'bx bx-revision',
            ));
        } catch (\Throwable $e) {
            Log::error('BackupRestoreJob failed', [
                'error' => $e->getMessage(),
                'restore_run_id' => $restoreRun->id,
            ]);

            $restoreRun->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            RestoreProgress::dispatch($restoreRun->id, $restoreRun->progress_percent, $e->getMessage(), 'failed');

            AuditLog::create([
                'user_id' => $restoreRun->triggered_by,
                'action' => 'backup_restore_failed',
                'summary' => "Restore failed: {$e->getMessage()}",
            ]);

            Notification::send($admins, new BackupNotification(
                'error',
                __('Restore Failed'),
                __('Restore failed: :error', ['error' => $e->getMessage()]),
                $backupsUrl,
                'bx bx-revision',
            ));
        }
    }

    private function restoreDatabase(GoogleDriveService $driveService, BackupRestoreRun $restoreRun): void
    {
        $fileId = $restoreRun->source_drive_file_id;

        if (! $fileId) {
            throw new \RuntimeException('No source Drive file ID specified for database restore.');
        }

        $tempBase = config('backup.local_backup_base').'/restore-temp';
        @mkdir($tempBase, 0755, true);

        $archivePath = "{$tempBase}/{$restoreRun->target}.tar.gz";
        $sqlPath = "{$tempBase}/{$restoreRun->target}.sql";

        try {
            // Download tar.gz
            RestoreProgress::dispatch($restoreRun->id, 20, __('Downloading database backup...'), 'downloading');
            $driveService->downloadFileToPath($fileId, $archivePath);

            // Extract
            $restoreRun->update(['status' => 'restoring', 'progress_percent' => 40]);
            RestoreProgress::dispatch($restoreRun->id, 40, __('Extracting database backup...'), 'restoring');

            $extractResult = Process::timeout(120)->run(
                sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($tempBase))
            );

            if ($extractResult->failed()) {
                throw new \RuntimeException("Failed to extract database archive: {$extractResult->errorOutput()}");
            }

            // Find the SQL file
            if (! file_exists($sqlPath)) {
                // Try to find any .sql file in temp
                $sqlFiles = glob("{$tempBase}/*.sql");
                $sqlPath = $sqlFiles[0] ?? $sqlPath;
            }

            if (! file_exists($sqlPath)) {
                throw new \RuntimeException('SQL file not found in archive.');
            }

            // Import
            RestoreProgress::dispatch($restoreRun->id, 60, __('Importing database...'), 'restoring');

            $mysqlConfig = config('backup.mysql');
            $importCmd = sprintf(
                'mysql --host=%s -u%s -p%s --skip-ssl %s < %s',
                escapeshellarg($mysqlConfig['host']),
                escapeshellarg($mysqlConfig['username']),
                escapeshellarg($mysqlConfig['password']),
                escapeshellarg($restoreRun->target),
                escapeshellarg($sqlPath)
            );

            $importResult = Process::timeout(600)->run($importCmd);

            if ($importResult->failed()) {
                throw new \RuntimeException("MySQL import failed: {$importResult->errorOutput()}");
            }

            RestoreProgress::dispatch($restoreRun->id, 95, __('Database restored successfully'), 'restoring');
        } finally {
            $this->cleanupDir($tempBase);
        }
    }

    private function restoreWebsiteFromFull(GoogleDriveService $driveService, BackupRestoreRun $restoreRun): void
    {
        $fileId = $restoreRun->source_drive_file_id;

        if (! $fileId) {
            throw new \RuntimeException('No source Drive file ID specified for website restore.');
        }

        $vhostsPath = config('backup.vhosts_path', '/var/www/vhosts');
        $sitePath = "{$vhostsPath}/{$restoreRun->target}";
        $preRestoreBase = config('backup.local_backup_base').'/pre-restore';
        $tempBase = config('backup.local_backup_base').'/restore-temp';
        @mkdir($preRestoreBase, 0755, true);
        @mkdir($tempBase, 0755, true);

        $preRestorePath = "{$preRestoreBase}/{$restoreRun->target}.tar.gz";
        $archivePath = "{$tempBase}/{$restoreRun->target}.tar.gz";

        try {
            // Pre-restore backup of current site
            if (is_dir($sitePath)) {
                RestoreProgress::dispatch($restoreRun->id, 10, __('Creating pre-restore backup...'), 'downloading');

                $preResult = Process::timeout(300)->run(
                    sprintf('tar -czf %s -C %s %s', escapeshellarg($preRestorePath), escapeshellarg($vhostsPath), escapeshellarg($restoreRun->target))
                );

                if ($preResult->failed()) {
                    Log::warning("Pre-restore backup failed: {$preResult->errorOutput()}");
                }
            }

            // Download from Drive
            RestoreProgress::dispatch($restoreRun->id, 25, __('Downloading website backup...'), 'downloading');
            $driveService->downloadFileToPath($fileId, $archivePath);

            // Extract to vhosts
            $restoreRun->update(['status' => 'restoring', 'progress_percent' => 50]);
            RestoreProgress::dispatch($restoreRun->id, 50, __('Extracting website files...'), 'restoring');

            $extractResult = Process::timeout(300)->run(
                sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($vhostsPath))
            );

            if ($extractResult->failed()) {
                // Attempt restore from pre-restore backup
                if (file_exists($preRestorePath)) {
                    Log::info('Restoring from pre-restore backup after failure');
                    Process::timeout(300)->run(
                        sprintf('tar -xzf %s -C %s', escapeshellarg($preRestorePath), escapeshellarg($vhostsPath))
                    );
                }

                throw new \RuntimeException("Failed to extract website archive: {$extractResult->errorOutput()}");
            }

            RestoreProgress::dispatch($restoreRun->id, 95, __('Website restored successfully'), 'restoring');
        } finally {
            $this->cleanupDir($tempBase);
        }
    }

    private function restoreWebsiteFromIncremental(GoogleDriveService $driveService, BackupRestoreRun $restoreRun): void
    {
        $vhostsPath = config('backup.vhosts_path', '/var/www/vhosts');
        $sitePath = "{$vhostsPath}/{$restoreRun->target}";
        $preRestoreBase = config('backup.local_backup_base').'/pre-restore';
        @mkdir($preRestoreBase, 0755, true);

        $preRestorePath = "{$preRestoreBase}/{$restoreRun->target}.tar.gz";

        // Pre-restore backup of current site
        if (is_dir($sitePath)) {
            RestoreProgress::dispatch($restoreRun->id, 5, __('Creating pre-restore backup...'), 'downloading');

            $preResult = Process::timeout(300)->run(
                sprintf('tar -czf %s -C %s %s', escapeshellarg($preRestorePath), escapeshellarg($vhostsPath), escapeshellarg($restoreRun->target))
            );

            if ($preResult->failed()) {
                Log::warning("Pre-restore backup failed: {$preResult->errorOutput()}");
            }
        }

        try {
            // Get all manifest entries up to the restore point, take latest per path
            $entries = $this->getIncrementalStateForRestore($restoreRun->target, $restoreRun->source_drive_folder_id);

            $filesToDownload = [];
            $filesToDelete = [];

            foreach ($entries as $entry) {
                if ($entry->action === 'upload' && $entry->drive_file_id) {
                    $filesToDownload[] = $entry;
                } elseif ($entry->action === 'delete') {
                    $filesToDelete[] = $entry->relative_path;
                }
            }

            $total = count($filesToDownload);
            $restoreRun->update(['status' => 'restoring', 'progress_percent' => 20]);

            // Download each file
            foreach ($filesToDownload as $i => $entry) {
                if ($this->isCancelled($restoreRun)) {
                    return;
                }

                $localPath = "{$sitePath}/{$entry->relative_path}";
                $dir = dirname($localPath);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }

                $driveService->downloadFileToPath($entry->drive_file_id, $localPath);

                $percent = (int) round(20 + (($i + 1) / max($total, 1) * 70));
                $restoreRun->update(['progress_percent' => min(90, $percent)]);
                RestoreProgress::dispatch($restoreRun->id, min(90, $percent), __('Restoring :path...', ['path' => $entry->relative_path]), 'restoring');
            }

            // Remove files that should be deleted
            foreach ($filesToDelete as $relativePath) {
                $localPath = "{$sitePath}/{$relativePath}";
                if (file_exists($localPath)) {
                    @unlink($localPath);
                }
            }

            RestoreProgress::dispatch($restoreRun->id, 95, __('Website restored successfully'), 'restoring');
        } catch (\Throwable $e) {
            // Attempt restore from pre-restore backup on failure
            if (file_exists($preRestorePath)) {
                Log::info('Restoring from pre-restore backup after incremental failure');
                Process::timeout(300)->run(
                    sprintf('tar -xzf %s -C %s', escapeshellarg($preRestorePath), escapeshellarg($vhostsPath))
                );
            }

            throw $e;
        }
    }

    /**
     * Get the cumulative incremental state for a domain up to a specific backup run.
     *
     * @return Collection<int, object>
     */
    private function getIncrementalStateForRestore(string $domain, ?string $sourceDriveFolderId): Collection
    {
        $query = DB::table('backup_file_manifests as m1')
            ->join(DB::raw('(SELECT domain, relative_path, MAX(id) as max_id FROM backup_file_manifests WHERE domain = '.DB::getPdo()->quote($domain).' GROUP BY domain, relative_path) as m2'), 'm1.id', '=', 'm2.max_id')
            ->select('m1.relative_path', 'm1.file_size', 'm1.file_mtime', 'm1.drive_file_id', 'm1.action');

        return $query->get();
    }

    private function isCancelled(BackupRestoreRun $restoreRun): bool
    {
        $restoreRun->refresh();

        return $restoreRun->status === 'cancelled';
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
