<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreDockerServiceRequest;
use App\Models\AuditLog;
use App\Models\DockerService;
use App\Services\DockerServiceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DockerServiceController extends ApiController
{
    public function index(): JsonResponse
    {
        $services = DockerService::query()->orderBy('name')->get();

        return response()->json(['data' => $services]);
    }

    public function store(StoreDockerServiceRequest $request, DockerServiceManager $manager): JsonResponse
    {
        $service = $manager->create($request->validated());
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'docker_service_created', 'summary' => $service->name, 'ip_address' => $request->ip()]);

        return response()->json(['data' => $service], 201);
    }

    public function show(DockerService $service): JsonResponse
    {
        return response()->json(['data' => $service->load('domainBindings.domain')]);
    }

    public function update(Request $request, DockerService $service, DockerServiceManager $manager): JsonResponse
    {
        $manager->update($service, $request->all());

        return response()->json(['data' => $service->fresh()]);
    }

    public function destroy(Request $request, DockerService $service, DockerServiceManager $manager): Response
    {
        $manager->delete($service);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'docker_service_deleted', 'summary' => $service->name, 'ip_address' => $request->ip()]);

        return response()->noContent();
    }

    public function action(Request $request, DockerService $service, DockerServiceManager $manager): JsonResponse
    {
        $validated = $request->validate(['action' => 'required|string|in:start,stop,restart,pull']);
        $result = $manager->performAction($service, $validated['action']);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => "docker_service_{$validated['action']}", 'summary' => $service->name, 'ip_address' => $request->ip()]);

        return response()->json(['data' => $result]);
    }

    public function syncStatus(DockerService $service, DockerServiceManager $manager): JsonResponse
    {
        $manager->syncStatus($service);

        return response()->json(['data' => $service->fresh()]);
    }

    public function logs(DockerService $service, DockerServiceManager $manager): JsonResponse
    {
        $logs = $manager->getLogs($service);

        return response()->json(['data' => ['logs' => $logs]]);
    }

    public function stats(DockerService $service, DockerServiceManager $manager): JsonResponse
    {
        $stats = $manager->getStats($service);

        return response()->json(['data' => $stats]);
    }
}
