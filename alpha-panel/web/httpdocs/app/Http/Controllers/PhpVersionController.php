<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PhpVersion;
use App\Services\PhpFpmSupervisorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PhpVersionController extends Controller
{
    public function index(PhpFpmSupervisorService $service): Response
    {
        $versions = PhpVersion::query()
            ->withCount('apacheDomains as domains_count')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('PhpVersions/Index', [
            'versions' => $versions,
            'supervisorStatuses' => Inertia::defer(fn () => $service->getSupervisorStatuses($versions)),
        ]);
    }

    public function restart(Request $request, PhpVersion $phpVersion, PhpFpmSupervisorService $service): JsonResponse
    {
        if (! $phpVersion->is_enabled) {
            return response()->json([
                'status' => 'error',
                'message' => __('PHP version is not enabled.'),
            ], 422);
        }

        try {
            $service->restartFpm($phpVersion);

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'php_fpm_restarted',
                'summary' => "PHP {$phpVersion->slug} FPM restarted manually",
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('PHP :version FPM restarted.', ['version' => $phpVersion->slug]),
            ]);
        } catch (\Throwable $e) {
            Log::error("PHP FPM restart failed for {$phpVersion->slug}: {$e->getMessage()}");

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'php_fpm_restart_failed',
                'summary' => "PHP {$phpVersion->slug}: {$e->getMessage()}",
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to restart PHP-FPM: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    public function recreateConf(Request $request, PhpVersion $phpVersion, PhpFpmSupervisorService $service): JsonResponse
    {
        if (! $phpVersion->is_enabled) {
            return response()->json([
                'status' => 'error',
                'message' => __('PHP version is not enabled.'),
            ], 422);
        }

        try {
            $service->recreateConf($phpVersion);

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'php_fpm_conf_recreated',
                'summary' => "PHP {$phpVersion->slug} supervisor config recreated from stub",
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('PHP :version supervisor config recreated.', ['version' => $phpVersion->slug]),
            ]);
        } catch (\Throwable $e) {
            Log::error("PHP supervisor conf recreate failed for {$phpVersion->slug}: {$e->getMessage()}");

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'php_fpm_conf_recreate_failed',
                'summary' => "PHP {$phpVersion->slug}: {$e->getMessage()}",
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to recreate supervisor config: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    public function getPhpIni(PhpVersion $phpVersion, PhpFpmSupervisorService $service): JsonResponse
    {
        $path = $service->phpIniPath($phpVersion);
        $content = file_exists($path) ? (string) file_get_contents($path) : '';

        return response()->json(['content' => $content]);
    }

    public function updatePhpIni(Request $request, PhpVersion $phpVersion, PhpFpmSupervisorService $service): JsonResponse
    {
        $request->validate([
            'content' => ['required', 'string', 'max:500000'],
        ]);

        $path = $service->phpIniPath($phpVersion);

        try {
            file_put_contents($path, $request->input('content'));

            $restarted = false;

            if ($phpVersion->is_enabled) {
                $service->restartFpm($phpVersion);
                $restarted = true;
            }

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'php_ini_updated',
                'summary' => "PHP {$phpVersion->slug} php.ini updated".($restarted ? ' (FPM restarted)' : ''),
            ]);

            $message = $restarted
                ? __('PHP :version configuration saved. PHP-FPM restarted.', ['version' => $phpVersion->slug])
                : __('PHP :version configuration saved.', ['version' => $phpVersion->slug]);

            return response()->json([
                'status' => 'success',
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            Log::error("PHP ini update failed for {$phpVersion->slug}: {$e->getMessage()}");

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'php_ini_update_failed',
                'summary' => "PHP {$phpVersion->slug}: {$e->getMessage()}",
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to save PHP configuration: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    public function getFrankenPhpIni(PhpFpmSupervisorService $service): JsonResponse
    {
        $path = $service->frankenPhpIniPath();
        $content = file_exists($path) ? (string) file_get_contents($path) : '';

        return response()->json(['content' => $content]);
    }

    public function updateFrankenPhpIni(Request $request, PhpFpmSupervisorService $service): JsonResponse
    {
        $request->validate([
            'content' => ['required', 'string', 'max:500000'],
        ]);

        $path = $service->frankenPhpIniPath();

        try {
            file_put_contents($path, $request->input('content'));
            $service->restartFrankenPhp();

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'frankenphp_ini_updated',
                'summary' => 'FrankenPHP php.ini updated (container restarted)',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('FrankenPHP configuration saved. Container restarted.'),
            ]);
        } catch (\Throwable $e) {
            Log::error("FrankenPHP ini update failed: {$e->getMessage()}");

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'frankenphp_ini_update_failed',
                'summary' => "FrankenPHP: {$e->getMessage()}",
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to save PHP configuration: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    public function toggle(Request $request, PhpVersion $phpVersion, PhpFpmSupervisorService $service): JsonResponse
    {
        $newState = ! $phpVersion->is_enabled;

        $apacheDomainCount = $phpVersion->apacheDomains()->count();

        if (! $newState && $apacheDomainCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cannot disable PHP :version because :count domain(s) are using it.', [
                    'version' => $phpVersion->slug,
                    'count' => $apacheDomainCount,
                ]),
            ], 422);
        }

        try {
            if ($newState) {
                $service->enable($phpVersion);
            } else {
                $service->disable($phpVersion);
            }

            $phpVersion->update(['is_enabled' => $newState]);

            $action = $newState ? 'php_version_enabled' : 'php_version_disabled';

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => $action,
                'summary' => "PHP {$phpVersion->slug} ".($newState ? 'enabled' : 'disabled'),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $newState
                    ? __('PHP :version enabled successfully.', ['version' => $phpVersion->slug])
                    : __('PHP :version disabled successfully.', ['version' => $phpVersion->slug]),
                'is_enabled' => $phpVersion->is_enabled,
            ]);
        } catch (\Throwable $e) {
            Log::error("PHP version toggle failed for {$phpVersion->slug}: {$e->getMessage()}");

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'php_version_toggle_failed',
                'summary' => "PHP {$phpVersion->slug}: {$e->getMessage()}",
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to update PHP version: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }
}
