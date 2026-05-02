<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DockerService;
use App\Models\Domain;
use App\Models\User;
use App\Services\DockerServiceManager;
use App\Services\HostMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function index(HostMetricsService $metrics): JsonResponse
    {
        $m = $metrics->getHostMetrics();

        return response()->json([
            'data' => [
                'host' => [
                    'cpu' => ['usage_percent' => $m['cpu_percent']],
                    'memory' => [
                        'used_mb' => $m['mem_used_mb'],
                        'total_mb' => $m['mem_total_mb'],
                    ],
                    'disk' => [
                        'used_gb' => $m['disk_used_gb'],
                        'total_gb' => $m['disk_total_gb'],
                    ],
                    'uptime_seconds' => $m['uptime_seconds'],
                    'load_average' => [$m['load_1'], $m['load_5'], $m['load_15']],
                ],
                'counts' => [
                    'domains' => Domain::query()->whereNull('parent_domain_id')->count(),
                    'users' => User::query()->count(),
                ],
            ],
        ]);
    }

    public function dockerAction(Request $request, DockerServiceManager $docker): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|integer|exists:docker_services,id',
            'action' => 'required|string|in:start,stop,restart',
        ]);

        $service = DockerService::findOrFail($validated['service_id']);
        $result = $docker->performAction($service, $validated['action']);

        return response()->json(['data' => $result]);
    }
}
