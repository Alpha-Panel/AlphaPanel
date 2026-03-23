<?php

namespace App\Http\Controllers;

use App\Enums\UpdateStatus;
use App\Enums\UpdateType;
use App\Jobs\MonitorUpdateProgressJob;
use App\Models\AuditLog;
use App\Models\SystemUpdate;
use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SystemUpdateController extends Controller
{
    public function index(UpdateService $service): Response
    {
        return Inertia::render('System/Updates', [
            'current_version' => $service->getCurrentVersion(),
            'agent_healthy' => $service->isAgentHealthy(),
            'cached_check' => Cache::get('system:latest_version_check'),
            'recent_updates' => SystemUpdate::query()
                ->with('triggeredByUser:id,name')
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (SystemUpdate $u) => [
                    'id' => $u->id,
                    'type' => $u->type,
                    'status' => $u->status,
                    'from_version' => $u->from_version,
                    'to_version' => $u->to_version,
                    'progress_percent' => $u->progress_percent,
                    'message' => $u->message,
                    'error_message' => $u->error_message,
                    'triggered_by' => $u->triggeredByUser?->name,
                    'started_at' => $u->started_at?->format('d.m.Y H:i:s'),
                    'finished_at' => $u->finished_at?->format('d.m.Y H:i:s'),
                ]),
        ]);
    }

    public function check(Request $request, UpdateService $service): JsonResponse
    {
        try {
            $result = $service->checkForUpdates();

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.check',
                'ip_address' => $request->ip(),
                'details' => 'Manual update check performed',
            ]);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => __('Failed to check for updates: :message', ['message' => $e->getMessage()]),
            ], 503);
        }
    }

    public function updatePanel(Request $request, UpdateService $service): JsonResponse
    {
        $currentVersion = $service->getCurrentVersion();

        $update = SystemUpdate::create([
            'type' => UpdateType::PanelUpdate,
            'status' => UpdateStatus::InProgress,
            'from_version' => $currentVersion['version'] ?? 'unknown',
            'progress_percent' => 0,
            'message' => __('Starting panel update...'),
            'triggered_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.panel.started',
            'ip_address' => $request->ip(),
            'details' => "Panel update initiated from {$update->from_version}",
        ]);

        try {
            $taskId = $service->updatePanel();
        } catch (\Throwable $e) {
            $update->update([
                'status' => UpdateStatus::Failed,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.panel.failed',
                'ip_address' => $request->ip(),
                'details' => "Panel update failed: {$e->getMessage()}",
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        dispatch(new MonitorUpdateProgressJob($update, $taskId));

        return response()->json([
            'update_id' => $update->id,
            'task_id' => $taskId,
        ]);
    }

    public function prepareMysqlUpgrade(Request $request, UpdateService $service): JsonResponse
    {
        $request->validate([
            'target_version' => 'required|string|max:20',
        ]);

        $currentVersion = $service->getCurrentVersion();
        $mysqlVersion = $currentVersion['services']['mysql'] ?? 'unknown';

        $update = SystemUpdate::create([
            'type' => UpdateType::MysqlUpgrade,
            'status' => UpdateStatus::InProgress,
            'from_version' => $mysqlVersion,
            'to_version' => $request->input('target_version'),
            'progress_percent' => 0,
            'message' => __('Preparing MySQL upgrade test environment...'),
            'triggered_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.prepare',
            'ip_address' => $request->ip(),
            'details' => "MySQL upgrade preparation: {$mysqlVersion} → {$request->input('target_version')}",
        ]);

        try {
            $taskId = $service->prepareMysqlUpgrade($request->input('target_version'));
        } catch (\Throwable $e) {
            $update->update([
                'status' => UpdateStatus::Failed,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.mysql.prepare_failed',
                'ip_address' => $request->ip(),
                'details' => "MySQL upgrade preparation failed: {$e->getMessage()}",
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        dispatch(new MonitorUpdateProgressJob($update, $taskId));

        return response()->json([
            'update_id' => $update->id,
            'task_id' => $taskId,
        ]);
    }

    public function applyMysqlUpgrade(Request $request, UpdateService $service): JsonResponse
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.apply',
            'ip_address' => $request->ip(),
            'details' => 'MySQL upgrade applied to production',
        ]);

        try {
            $taskId = $service->applyMysqlUpgrade();
        } catch (\Throwable $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.mysql.apply_failed',
                'ip_address' => $request->ip(),
                'details' => "MySQL upgrade apply failed: {$e->getMessage()}",
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        $update = SystemUpdate::where('type', UpdateType::MysqlUpgrade)
            ->latest()
            ->first();

        if ($update) {
            dispatch(new MonitorUpdateProgressJob($update, $taskId));
        }

        return response()->json(['task_id' => $taskId]);
    }

    public function rollbackMysqlUpgrade(Request $request, UpdateService $service): JsonResponse
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.rollback',
            'ip_address' => $request->ip(),
            'details' => 'MySQL upgrade rolled back',
        ]);

        try {
            $taskId = $service->rollbackMysqlUpgrade();
        } catch (\Throwable $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.mysql.rollback_failed',
                'ip_address' => $request->ip(),
                'details' => "MySQL upgrade rollback failed: {$e->getMessage()}",
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        $update = SystemUpdate::where('type', UpdateType::MysqlUpgrade)
            ->latest()
            ->first();

        if ($update) {
            $update->update(['status' => UpdateStatus::RolledBack]);
            dispatch(new MonitorUpdateProgressJob($update, $taskId));
        }

        return response()->json(['task_id' => $taskId]);
    }

    public function cleanupMysqlBackup(Request $request, UpdateService $service): JsonResponse
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.cleanup',
            'ip_address' => $request->ip(),
            'details' => 'MySQL backup cleanup requested',
        ]);

        $success = $service->cleanupMysqlBackup();

        return response()->json([
            'status' => $success ? 'ok' : 'error',
        ], $success ? 200 : 500);
    }

    public function taskStatus(string $taskId, UpdateService $service): JsonResponse
    {
        try {
            $status = $service->getTaskStatus($taskId);

            return response()->json($status);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
