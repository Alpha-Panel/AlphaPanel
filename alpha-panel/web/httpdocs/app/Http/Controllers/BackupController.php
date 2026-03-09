<?php

namespace App\Http\Controllers;

use App\Jobs\BackupUploadJob;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('Backups/Index', [
            'settings' => [
                'is_connected' => $settings->isConnected(),
                'connected_email' => $settings->connected_email,
                'drive_folder_id' => $settings->drive_folder_id,
                'drive_folder_name' => $settings->drive_folder_name,
                'is_enabled' => $settings->is_enabled,
                'backup_retention_days' => $settings->backup_retention_days,
                'last_backup_at' => $settings->last_backup_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
                'has_credentials' => config('backup.google.client_id') !== null
                    && config('backup.google.client_id') !== '',
            ],
            'recent_runs' => $recentRuns->map(fn (BackupRun $run) => [
                'id' => $run->id,
                'type' => $run->type,
                'status' => $run->status,
                'file_name' => $run->file_name,
                'file_size' => $run->file_size_bytes,
                'progress_percent' => $run->progress_percent,
                'error_message' => $run->error_message,
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
        ]);

        $settings = BackupSetting::instance();
        $settings->update($validated);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'backup_settings_updated',
            'summary' => "Backup settings updated: enabled={$validated['is_enabled']}, retention={$validated['backup_retention_days']}d",
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

    public function history(): JsonResponse
    {
        $runs = BackupRun::query()
            ->with('triggeredByUser:id,name')
            ->latest()
            ->paginate(20);

        return response()->json($runs);
    }
}
