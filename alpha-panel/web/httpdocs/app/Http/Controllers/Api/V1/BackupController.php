<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\BackupUploadJob;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BackupController extends ApiController
{
    public function index(): JsonResponse
    {
        $settings = BackupSetting::instance();

        return response()->json(['data' => [
            'is_connected' => $settings->isConnected(),
            'connected_email' => $settings->connected_email,
            'drive_folder_id' => $settings->drive_folder_id,
            'drive_folder_name' => $settings->drive_folder_name,
            'is_enabled' => $settings->is_enabled,
            'backup_retention_days' => $settings->backup_retention_days,
            'backup_schedule' => $settings->backup_schedule ?? 'daily',
            'backup_time' => $settings->backup_time ?? '03:00',
            'last_backup_at' => $settings->last_backup_at?->toISOString(),
        ]]);
    }

    public function history(): JsonResponse
    {
        $runs = BackupRun::query()->with('triggeredByUser:id,name')->orderByDesc('created_at')->paginate(25);

        return response()->json(['data' => $runs->items(), 'meta' => ['total' => $runs->total(), 'current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'per_page' => $runs->perPage()]]);
    }

    public function folders(GoogleDriveService $drive): JsonResponse
    {
        return response()->json(['data' => $drive->listFolders()]);
    }

    public function driveQuota(GoogleDriveService $drive): JsonResponse
    {
        return response()->json(['data' => $drive->getStorageQuota()]);
    }

    public function driveFiles(GoogleDriveService $drive): JsonResponse
    {
        return response()->json(['data' => $drive->listFilesAndFolders()]);
    }

    public function driveDownload(GoogleDriveService $drive, string $fileId): Response
    {
        $info = $drive->downloadFile($fileId);

        return response(base64_decode($info['content'] ?? ''), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"backup-{$fileId}.tar.gz\"",
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $run = BackupRun::create([
            'type' => 'manual',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $request->user()->id,
        ]);

        BackupUploadJob::dispatch(backupRunId: $run->id);

        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'backup_upload_started', 'summary' => 'API: Manual backup triggered']);

        return response()->json(['data' => $run], 201);
    }

    public function cancel(BackupRun $run): JsonResponse
    {
        $run->update(['status' => 'cancelled']);

        return response()->json(['message' => __('Backup cancelled.')]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule' => 'nullable|string',
            'retention_days' => 'nullable|integer|min:1',
            'enabled' => 'boolean',
            'backup_time' => 'nullable|string',
        ]);

        $settings = BackupSetting::instance();
        $settings->update(array_filter([
            'backup_schedule' => $validated['schedule'] ?? null,
            'backup_retention_days' => $validated['retention_days'] ?? null,
            'is_enabled' => $validated['enabled'] ?? null,
            'backup_time' => $validated['backup_time'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['message' => __('Backup settings updated.')]);
    }

    public function setFolder(Request $request): JsonResponse
    {
        $validated = $request->validate(['folder_id' => 'required|string', 'folder_name' => 'nullable|string']);
        $settings = BackupSetting::instance();
        $settings->update(['drive_folder_id' => $validated['folder_id'], 'drive_folder_name' => $validated['folder_name'] ?? null]);

        return response()->json(['message' => __('Backup folder set.')]);
    }

    public function createFolder(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:100']);
        $folder = $drive->createFolder($request->input('name'));

        return response()->json(['data' => $folder], 201);
    }
}
