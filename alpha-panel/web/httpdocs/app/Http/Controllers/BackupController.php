<?php

namespace App\Http\Controllers;

use App\Events\BackupProgress;
use App\Events\RestoreProgress;
use App\Jobs\BackupRestoreJob;
use App\Jobs\BackupUploadJob;
use App\Models\AuditLog;
use App\Models\BackupRestoreRun;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(): Response
    {
        $settings = BackupSetting::instance();
        $recentRuns = BackupRun::query()
            ->with('triggeredByUser:id,name')
            ->latest()
            ->limit(50)
            ->get();

        $recentRestoreRuns = BackupRestoreRun::query()
            ->with('triggeredByUser:id,name')
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('Backups/Index', [
            'settings' => [
                'is_connected' => $settings->isConnected(),
                'connected_email' => $settings->connected_email,
                'drive_folder_id' => $settings->drive_folder_id,
                'drive_folder_name' => $settings->drive_folder_name,
                'is_enabled' => $settings->is_enabled,
                'backup_retention_days' => $settings->backup_retention_days,
                'backup_schedule' => $settings->backup_schedule ?? 'daily',
                'backup_time' => $settings->backup_time ?? '03:00',
                'backup_mode' => $settings->backup_mode ?? 'full',
                'last_backup_at' => $settings->last_backup_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
                'has_credentials' => config('backup.google.client_id') !== null
                    && config('backup.google.client_id') !== '',
            ],
            'recent_runs' => $recentRuns->map(fn (BackupRun $run) => [
                'id' => $run->id,
                'type' => $run->type,
                'backup_mode' => $run->backup_mode ?? 'full',
                'status' => $run->status,
                'file_name' => $run->file_name,
                'file_size' => $run->file_size_bytes,
                'progress_percent' => $run->progress_percent,
                'error_message' => $run->error_message,
                'drive_file_id' => $run->drive_file_id,
                'started_at' => $run->started_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
                'finished_at' => $run->finished_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
                'triggered_by' => $run->triggeredByUser?->name,
            ]),
            'recent_restore_runs' => $recentRestoreRuns->map(fn (BackupRestoreRun $run) => [
                'id' => $run->id,
                'restore_type' => $run->restore_type,
                'source_mode' => $run->source_mode,
                'status' => $run->status,
                'target' => $run->target,
                'error_message' => $run->error_message,
                'progress_percent' => $run->progress_percent,
                'started_at' => $run->started_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
                'finished_at' => $run->finished_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
                'triggered_by' => $run->triggeredByUser?->name,
            ]),
        ]);
    }

    public function connect(Request $request, GoogleDriveService $drive): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($drive->getAuthUrl($state));
    }

    public function callback(Request $request, GoogleDriveService $drive): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_oauth_state');

        if (! $expectedState || $request->input('state') !== $expectedState) {
            return redirect()->route('backups.index')
                ->with('error', __('Invalid OAuth state. Please try again.'));
        }

        if ($request->has('error')) {
            return redirect()->route('backups.index')
                ->with('error', __('Google authorization was denied: :error', ['error' => $request->input('error')]));
        }

        $code = $request->input('code');

        if (! $code) {
            return redirect()->route('backups.index')
                ->with('error', __('No authorization code received.'));
        }

        try {
            $tokenData = $drive->exchangeCode($code);
        } catch (\Throwable $e) {
            return redirect()->route('backups.index')
                ->with('error', __('Failed to exchange authorization code: :message', ['message' => $e->getMessage()]));
        }

        $settings = BackupSetting::instance();
        $settings->update([
            'google_access_token' => $tokenData['access_token'],
            'google_refresh_token' => $tokenData['refresh_token'],
            'google_token_expires_at' => now()->addSeconds($tokenData['expires_in']),
            'connected_email' => $tokenData['email'],
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_google_connected',
            'summary' => 'Connected Google Drive: '.($tokenData['email'] ?? 'unknown'),
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Google Drive connected successfully.'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $settings = BackupSetting::instance();
        $previousEmail = $settings->connected_email;

        $settings->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
            'connected_email' => null,
            'drive_folder_id' => null,
            'drive_folder_name' => null,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_google_disconnected',
            'summary' => 'Disconnected Google Drive: '.($previousEmail ?? 'unknown'),
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Google Drive disconnected.'));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'backup_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'backup_schedule' => ['required', 'in:daily,every_2_days,every_3_days,weekly,every_2_weeks,monthly'],
            'backup_time' => ['required', 'date_format:H:i'],
            'backup_mode' => ['required', 'in:full,incremental'],
        ]);

        $settings = BackupSetting::instance();
        $settings->update($validated);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_settings_updated',
            'summary' => "Backup settings updated: enabled={$validated['is_enabled']}, schedule={$validated['backup_schedule']} at {$validated['backup_time']}, retention={$validated['backup_retention_days']}d, mode={$validated['backup_mode']}",
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Backup settings updated.'));
    }

    public function folders(Request $request, GoogleDriveService $drive): JsonResponse
    {
        try {
            $parentId = $request->input('parent_id');
            $folders = $drive->listFolders($parentId ?: null);

            return response()->json(['folders' => $folders]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function setFolder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'drive_folder_id' => ['required', 'string', 'max:255'],
            'drive_folder_name' => ['required', 'string', 'max:255'],
        ]);

        $settings = BackupSetting::instance();
        $settings->update($validated);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_folder_changed',
            'summary' => "Backup folder set to: {$validated['drive_folder_name']}",
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Backup folder updated.'));
    }

    public function createFolder(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'string'],
        ]);

        try {
            $folder = $drive->createFolder($validated['name'], $validated['parent_id'] ?? null);

            return response()->json($folder);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function run(Request $request): RedirectResponse
    {
        $settings = BackupSetting::instance();

        if (! $settings->isConnected()) {
            return redirect()->route('backups.index')
                ->with('error', __('Google Drive is not connected.'));
        }

        if (! $settings->drive_folder_id) {
            return redirect()->route('backups.index')
                ->with('error', __('No backup folder selected.'));
        }

        BackupUploadJob::dispatch(
            type: 'manual',
            triggeredBy: $request->user()->id,
        );

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_upload_started',
            'summary' => 'Manual backup triggered',
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Backup job started. You can track progress below.'));
    }

    public function cancel(Request $request, BackupRun $backupRun): RedirectResponse
    {
        if (! in_array($backupRun->status, ['uploading', 'running'])) {
            return redirect()->route('backups.index')
                ->with('error', __('This backup is not in a cancellable state.'));
        }

        $backupRun->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_message' => __('Cancelled by user'),
        ]);

        BackupProgress::dispatch($backupRun->id, $backupRun->progress_percent, __('Backup cancelled'), 'cancelled');

        // Cleanup temp files if they exist
        $tempBase = config('backup.local_backup_base').'/'.now()->format('d-M-Y');
        if (is_dir($tempBase)) {
            $this->cleanupDir($tempBase);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_cancelled',
            'summary' => "Backup #{$backupRun->id} cancelled by user",
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Backup cancelled.'));
    }

    public function restart(Request $request): RedirectResponse
    {
        $settings = BackupSetting::instance();

        if (! $settings->isConnected()) {
            return redirect()->route('backups.index')
                ->with('error', __('Google Drive is not connected.'));
        }

        if (! $settings->drive_folder_id) {
            return redirect()->route('backups.index')
                ->with('error', __('No backup folder selected.'));
        }

        // Cancel any active runs
        BackupRun::whereIn('status', ['uploading', 'running'])
            ->each(function (BackupRun $run): void {
                $run->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                    'error_message' => __('Cancelled for restart'),
                ]);

                BackupProgress::dispatch($run->id, $run->progress_percent, __('Backup cancelled for restart'), 'cancelled');
            });

        BackupUploadJob::dispatch(
            type: 'manual',
            triggeredBy: $request->user()->id,
        );

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_restarted',
            'summary' => 'Backup restarted by user',
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Backup restarted. You can track progress below.'));
    }

    public function driveQuota(GoogleDriveService $drive): JsonResponse
    {
        try {
            $quota = $drive->getStorageQuota();

            return response()->json($quota);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function driveFiles(Request $request, GoogleDriveService $drive): JsonResponse
    {
        try {
            $parentId = $request->input('parent_id');
            $files = $drive->listFilesAndFolders($parentId ?: null);

            return response()->json(['files' => $files]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function driveDownload(string $fileId, GoogleDriveService $drive): StreamedResponse
    {
        try {
            $file = $drive->downloadFile($fileId);

            return response()->streamDownload(function () use ($file): void {
                $stream = $file['stream'];
                while (! $stream->eof()) {
                    echo $stream->read(8192);
                    flush();
                }
            }, $file['name'], [
                'Content-Type' => $file['mimeType'],
                'Content-Length' => $file['size'],
            ]);
        } catch (\Throwable $e) {
            abort(500, $e->getMessage());
        }
    }

    public function history(): JsonResponse
    {
        $runs = BackupRun::query()
            ->with('triggeredByUser:id,name')
            ->latest()
            ->paginate(20);

        return response()->json($runs);
    }

    public function restore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'restore_type' => ['required', 'in:website,database'],
            'source_mode' => ['required', 'in:full,incremental'],
            'target' => ['required', 'string', 'max:255'],
            'source_drive_folder_id' => ['nullable', 'string', 'max:255'],
            'source_drive_file_id' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = BackupSetting::instance();

        if (! $settings->isConnected()) {
            return redirect()->route('backups.index')
                ->with('error', __('Google Drive is not connected.'));
        }

        // Prevent concurrent restores
        $activeRestore = BackupRestoreRun::whereIn('status', ['pending', 'downloading', 'restoring'])->exists();

        if ($activeRestore) {
            return redirect()->route('backups.index')
                ->with('error', __('A restore operation is already in progress.'));
        }

        $restoreRun = BackupRestoreRun::create([
            ...$validated,
            'status' => 'pending',
            'triggered_by' => $request->user()->id,
        ]);

        BackupRestoreJob::dispatch($restoreRun->id);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_restore_started',
            'summary' => "Restore started: {$validated['restore_type']} — {$validated['target']} ({$validated['source_mode']} mode)",
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Restore job started. You can track progress below.'));
    }

    public function cancelRestore(Request $request, BackupRestoreRun $backupRestoreRun): RedirectResponse
    {
        if (! in_array($backupRestoreRun->status, ['pending', 'downloading', 'restoring'])) {
            return redirect()->route('backups.index')
                ->with('error', __('This restore is not in a cancellable state.'));
        }

        $backupRestoreRun->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_message' => __('Cancelled by user'),
        ]);

        RestoreProgress::dispatch($backupRestoreRun->id, $backupRestoreRun->progress_percent, __('Restore cancelled'), 'cancelled');

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_restore_cancelled',
            'summary' => "Restore #{$backupRestoreRun->id} cancelled by user",
        ]);

        return redirect()->route('backups.index')
            ->with('success', __('Restore cancelled.'));
    }

    public function restoreHistory(): JsonResponse
    {
        $runs = BackupRestoreRun::query()
            ->with('triggeredByUser:id,name')
            ->latest()
            ->paginate(20);

        return response()->json($runs);
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
