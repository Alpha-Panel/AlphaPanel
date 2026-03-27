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
    public function index(): Response
    {
        $versions = PhpVersion::query()
            ->withCount('apacheDomains as domains_count')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('PhpVersions/Index', [
            'versions' => $versions,
        ]);
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
                'ip_address' => $request->ip(),
                'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
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
                'ip_address' => $request->ip(),
                'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to update PHP version: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }
}
