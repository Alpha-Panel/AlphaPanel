<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDockerServiceRequest;
use App\Http\Requests\UpdateDockerServiceRequest;
use App\Models\AuditLog;
use App\Models\DockerService;
use App\Services\ComposeFileService;
use App\Services\DockerServiceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DockerServiceController extends Controller
{
    public function __construct(
        private DockerServiceManager $manager,
    ) {}

    public function index(Request $request): Response
    {
        $services = DockerService::with('createdBy')
            ->withCount('domainBindings')
            ->latest()
            ->get();

        return Inertia::render('DockerServices/Index', [
            'services' => $services,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('DockerServices/Create');
    }

    public function store(StoreDockerServiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $service = DockerService::create($data);

        $this->manager->deploy($service, $request->user()->id);

        return redirect()->route('docker-services.show', $service)
            ->with('success', __('Service deployment started. This may take a moment.'));
    }

    public function show(Request $request, DockerService $dockerService): Response
    {
        $dockerService->load(['createdBy', 'domainBindings.domain']);

        return Inertia::render('DockerServices/Show', [
            'service' => $dockerService,
        ]);
    }

    public function edit(Request $request, DockerService $dockerService): Response
    {
        return Inertia::render('DockerServices/Edit', [
            'service' => $dockerService,
        ]);
    }

    public function update(UpdateDockerServiceRequest $request, DockerService $dockerService): RedirectResponse
    {
        $validated = $request->validated();

        // Convert model attributes to primitives for safe comparison (enums → strings, etc.)
        $currentValues = collect($dockerService->only(array_keys($validated)))
            ->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)
            ->map(fn ($v) => is_array($v) ? json_encode($v, JSON_THROW_ON_ERROR) : $v)
            ->all();

        $newValues = collect($validated)
            ->map(fn ($v) => is_array($v) ? json_encode($v, JSON_THROW_ON_ERROR) : $v)
            ->all();

        $changes = array_keys(array_diff_assoc($newValues, $currentValues));

        $dockerService->update($validated);

        // Regenerate compose file to reflect changes
        try {
            app(ComposeFileService::class)->writeServiceFile($dockerService);
        } catch (\Exception $e) {
            Log::warning("Compose file update failed for {$dockerService->name}: {$e->getMessage()}");
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'docker_service_updated',
            'summary' => "Updated Docker service \"{$dockerService->display_name}\": changed ".implode(', ', $changes).'.',
            'details' => json_encode([
                'service_id' => $dockerService->id,
                'changed_fields' => $changes,
            ], JSON_THROW_ON_ERROR),
        ]);

        return redirect()->route('docker-services.show', $dockerService)
            ->with('success', __('Service updated successfully.'));
    }

    public function destroy(Request $request, DockerService $dockerService): RedirectResponse|JsonResponse
    {
        $serviceName = $dockerService->display_name;
        $serviceImage = "{$dockerService->image}:{$dockerService->tag}";

        try {
            $this->manager->remove($dockerService);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'docker_service_removed',
                'summary' => "Removed Docker service \"{$serviceName}\" ({$serviceImage}).",
            ]);

            if ($request->wantsJson()) {
                return response()->json(['message' => __('Service removed successfully.')]);
            }

            return redirect()->route('docker-services.index')
                ->with('success', __('Service removed successfully.'));
        } catch (\Exception $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'docker_service_remove_failed',
                'summary' => "Failed to remove Docker service \"{$serviceName}\": {$e->getMessage()}",
            ]);

            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 500);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function action(Request $request, DockerService $dockerService): JsonResponse
    {
        $request->validate(['action' => 'required|in:start,stop,restart']);

        $actionName = $request->input('action');

        try {
            match ($actionName) {
                'start' => $this->manager->start($dockerService),
                'stop' => $this->manager->stop($dockerService),
                'restart' => $this->manager->restart($dockerService),
            };

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => "docker_service_{$actionName}",
                'summary' => ucfirst($actionName)." Docker service \"{$dockerService->display_name}\" ({$dockerService->image}:{$dockerService->tag}).",
            ]);

            return response()->json([
                'message' => __('Action :action executed successfully.', ['action' => $actionName]),
                'status' => $dockerService->fresh()->status->value,
            ]);
        } catch (\Exception $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => "docker_service_{$actionName}_failed",
                'summary' => "Failed to {$actionName} Docker service \"{$dockerService->display_name}\": {$e->getMessage()}",
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function logs(Request $request, DockerService $dockerService): JsonResponse
    {
        $tail = $request->integer('tail', 200);
        $logs = $this->manager->getLogs($dockerService, $tail);

        return response()->json(['logs' => $logs]);
    }

    public function stats(Request $request, DockerService $dockerService): JsonResponse
    {
        $stats = $this->manager->getStats($dockerService);

        return response()->json(['stats' => $stats]);
    }

    public function syncStatus(Request $request, DockerService $dockerService): JsonResponse
    {
        $this->manager->syncStatus($dockerService);

        return response()->json([
            'status' => $dockerService->fresh()->status->value,
            'status_label' => $dockerService->fresh()->status->label(),
        ]);
    }
}
