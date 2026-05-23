<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\HostMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServerStatsController extends Controller
{
    private const CACHE_KEY = 'sidebar:server-stats:v1';

    private const CACHE_SECONDS = 15;

    public function index(Request $request, HostMetricsService $metrics): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isAdmin(), 403);

        try {
            // Hot path: broadcaster (system:broadcast-server-stats) refreshes this
            // cache key on its loop, so the SPA's initial paint and any fallback
            // poll just read the latest snapshot without hitting Portainer.
            $data = Cache::get(self::CACHE_KEY);

            if (! is_array($data)) {
                $data = Cache::remember(
                    self::CACHE_KEY,
                    now()->addSeconds(self::CACHE_SECONDS),
                    fn (): array => $metrics->getHostMetrics(),
                );
            }

            return response()->json([
                'has_error' => false,
                ...$data,
            ]);
        } catch (\Throwable) {
            return response()->json([
                'has_error' => true,
                'cpu_percent' => 0,
                'mem_used_mb' => 0,
                'mem_total_mb' => 0,
                'mem_percent' => 0,
                'disk_used_gb' => 0,
                'disk_total_gb' => 0,
                'disk_percent' => 0,
                'uptime_seconds' => 0,
                'load_1' => 0,
                'load_5' => 0,
                'load_15' => 0,
            ]);
        }
    }
}
