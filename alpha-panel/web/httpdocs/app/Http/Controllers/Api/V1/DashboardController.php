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
    public function index(HostMetricsService $metrics, DockerServiceManager $docker): JsonResponse
    {
        return response()->json([
            'data' => [
                'domains_count' => Domain::query()->count(),
                'users_count' => User::query()->count(),
                'metrics' => $metrics->getAll(),
                'docker_services' => $docker->getSummary(),
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
