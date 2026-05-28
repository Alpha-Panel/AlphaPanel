<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\PhpVersion;
use App\Services\PhpFpmSupervisorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PhpVersionController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => PhpVersion::query()->orderBy('sort_order')->get()]);
    }

    public function toggle(Request $request, PhpVersion $version, PhpFpmSupervisorService $service): JsonResponse
    {
        $version->update(['is_enabled' => ! $version->is_enabled]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $version->is_enabled ? 'php_version_enabled' : 'php_version_disabled', 'summary' => "PHP {$version->slug}"]);

        return response()->json(['data' => ['is_enabled' => $version->fresh()->is_enabled]]);
    }

    public function frankenphpIni(PhpFpmSupervisorService $service): JsonResponse
    {
        $path = $service->frankenPhpIniPath();
        $content = file_exists($path) ? (string) file_get_contents($path) : '';

        return response()->json(['data' => ['content' => $content, 'path' => $path]]);
    }

    public function updateFrankenphpIni(Request $request, PhpFpmSupervisorService $service): JsonResponse
    {
        $request->validate(['content' => 'required|string|max:500000']);

        try {
            $path = $service->frankenPhpIniPath();
            file_put_contents($path, $request->input('content'));
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'frankenphp_ini_updated', 'summary' => 'FrankenPHP php.ini updated']);

            return response()->json(['message' => __('php.ini updated.')]);
        } catch (\Throwable $e) {
            Log::error("FrankenPHP ini update failed: {$e->getMessage()}");

            return response()->json(['message' => __('Failed: :error', ['error' => $e->getMessage()])], 500);
        }
    }

    public function phpIni(PhpVersion $version, PhpFpmSupervisorService $service): JsonResponse
    {
        $path = $service->phpIniPath($version);
        $content = file_exists($path) ? (string) file_get_contents($path) : '';

        return response()->json(['data' => ['content' => $content, 'path' => $path]]);
    }

    public function updatePhpIni(Request $request, PhpVersion $version, PhpFpmSupervisorService $service): JsonResponse
    {
        $request->validate(['content' => 'required|string|max:500000']);

        try {
            $path = $service->phpIniPath($version);
            file_put_contents($path, $request->input('content'));

            if ($version->is_enabled) {
                $service->restartFpm($version);
            }

            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'php_ini_updated', 'summary' => "PHP {$version->slug} php.ini updated"]);

            return response()->json(['message' => __('php.ini updated.')]);
        } catch (\Throwable $e) {
            Log::error("PHP ini update failed for {$version->slug}: {$e->getMessage()}");

            return response()->json(['message' => __('Failed: :error', ['error' => $e->getMessage()])], 500);
        }
    }
}
