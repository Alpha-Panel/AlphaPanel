<?php

namespace App\Http\Controllers;

use App\Enums\UpdateStatus;
use App\Enums\UpdateType;
use App\Jobs\MonitorUpdateProgressJob;
use App\Models\AuditLog;
use App\Models\SystemUpdate;
use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SystemUpdateController extends Controller
{
    public function index(UpdateService $service): Response
    {
        $latestMysqlUpdate = SystemUpdate::query()
            ->where('type', UpdateType::MysqlUpgrade)
            ->latest()
            ->first();

        $mysqlStage = 'idle';
        $activeProgress = null;

        if ($latestMysqlUpdate) {
            $operation = $latestMysqlUpdate->rollback_info['operation'] ?? 'prepare';

            $mysqlStage = match ($latestMysqlUpdate->status) {
                UpdateStatus::InProgress => match ($operation) {
                    'apply' => 'applying',
                    'rollback' => 'rolling_back',
                    default => 'preparing',
                },
                UpdateStatus::Completed => match ($operation) {
                    'apply' => 'applied',
                    default => 'prepared',
                },
                UpdateStatus::Failed, UpdateStatus::RolledBack => 'idle',
                default => 'idle',
            };

            if ($latestMysqlUpdate->status === UpdateStatus::InProgress) {
                $activeProgress = [
                    'percent' => $latestMysqlUpdate->progress_percent,
                    'message' => $latestMysqlUpdate->message,
                ];
            }
        }

        return Inertia::render('System/Updates', [
            'current_version' => $service->getCurrentVersion(),
            'agent_healthy' => $service->isAgentHealthy(),
            'cached_check' => Cache::get('system:latest_version_check'),
            'mysql_stage' => $mysqlStage,
            'active_progress' => $activeProgress,
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
                    'started_at' => $u->started_at?->toIso8601String(),
                    'finished_at' => $u->finished_at?->toIso8601String(),
                ]),
        ]);
    }

    public function check(Request $request, UpdateService $service): RedirectResponse
    {
        try {
            $service->checkForUpdates();

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.check',
                'details' => 'Manual update check performed',
            ]);

            return back()->with('success', __('Update check completed.'));
        } catch (\Throwable $e) {
            return back()->with('error', __('Failed to check for updates: :message', ['message' => $e->getMessage()]));
        }
    }

    public function updatePanel(Request $request, UpdateService $service): RedirectResponse
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
                'details' => "Panel update failed: {$e->getMessage()}",
            ]);

            return back()->with('error', __('Panel update failed: :message', ['message' => $e->getMessage()]));
        }

        dispatch(new MonitorUpdateProgressJob($update, $taskId));

        return back()->with('success', __('Panel update started.'));
    }

    public function prepareMysqlUpgrade(Request $request, UpdateService $service): RedirectResponse
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
            'message' => __('Backing up MySQL data...'),
            'triggered_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.prepare',
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
                'details' => "MySQL upgrade preparation failed: {$e->getMessage()}",
            ]);

            return back()->with('error', __('MySQL upgrade preparation failed: :message', ['message' => $e->getMessage()]));
        }

        dispatch(new MonitorUpdateProgressJob($update, $taskId, 'prepare'));

        return back()->with('success', __('MySQL upgrade preparation started.'));
    }

    public function applyMysqlUpgrade(Request $request, UpdateService $service): RedirectResponse
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.apply',
            'details' => 'MySQL upgrade applied to production',
        ]);

        try {
            $taskId = $service->applyMysqlUpgrade();
        } catch (\Throwable $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.mysql.apply_failed',
                'details' => "MySQL upgrade apply failed: {$e->getMessage()}",
            ]);

            return back()->with('error', __('MySQL upgrade failed: :message', ['message' => $e->getMessage()]));
        }

        $update = SystemUpdate::where('type', UpdateType::MysqlUpgrade)
            ->latest()
            ->first();

        if ($update) {
            $update->update([
                'status' => UpdateStatus::InProgress,
                'message' => __('Applying MySQL upgrade...'),
                'rollback_info' => array_merge($update->rollback_info ?? [], ['operation' => 'apply']),
            ]);
            dispatch(new MonitorUpdateProgressJob($update, $taskId, 'apply'));
        }

        return back()->with('success', __('MySQL upgrade applied.'));
    }

    public function rollbackMysqlUpgrade(Request $request, UpdateService $service): RedirectResponse
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.rollback',
            'details' => 'MySQL upgrade rolled back',
        ]);

        try {
            $taskId = $service->rollbackMysqlUpgrade();
        } catch (\Throwable $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'system_update.mysql.rollback_failed',
                'details' => "MySQL upgrade rollback failed: {$e->getMessage()}",
            ]);

            return back()->with('error', __('MySQL rollback failed: :message', ['message' => $e->getMessage()]));
        }

        $update = SystemUpdate::where('type', UpdateType::MysqlUpgrade)
            ->latest()
            ->first();

        if ($update) {
            $update->update([
                'status' => UpdateStatus::InProgress,
                'message' => __('Rolling back MySQL upgrade...'),
                'rollback_info' => array_merge($update->rollback_info ?? [], ['operation' => 'rollback']),
            ]);
            dispatch(new MonitorUpdateProgressJob($update, $taskId, 'rollback'));
        }

        return back()->with('success', __('MySQL upgrade rolled back.'));
    }

    public function cleanupMysqlBackup(Request $request, UpdateService $service): RedirectResponse
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'system_update.mysql.cleanup',
            'details' => 'MySQL backup cleanup requested',
        ]);

        $success = $service->cleanupMysqlBackup();

        if (! $success) {
            return back()->with('error', __('Failed to cleanup MySQL backup.'));
        }

        return back()->with('success', __('MySQL backup deleted.'));
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
