<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDockerProjectRequest;
use App\Http\Requests\UpdateDockerProjectRequest;
use App\Jobs\BuildDockerImageJob;
use App\Models\AuditLog;
use App\Models\DockerProject;
use App\Services\DockerProjectManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class DockerProjectController extends Controller
{
    public function __construct(
        private DockerProjectManager $manager,
    ) {}

    public function index(Request $request): Response
    {
        $projects = DockerProject::with('createdBy')
            ->withCount('domainBindings')
            ->latest()
            ->get();

        return Inertia::render('DockerProjects/Index', [
            'projects' => $projects,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('DockerProjects/Create');
    }

    public function store(StoreDockerProjectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $composeYaml = $data['compose_yaml'] ?? null;
        unset($data['compose_yaml']);

        $project = DockerProject::create($data);

        $projectDir = $project->projectPath();
        File::ensureDirectoryExists($projectDir, 0755, true);

        $composePath = $project->composeFilePath();

        if ($composeYaml) {
            file_put_contents($composePath, $composeYaml);
        } elseif (! file_exists($composePath)) {
            file_put_contents($composePath, "services:\n  web:\n    image: nginx:latest\n    restart: unless-stopped\n");
        }

        return redirect()->route('docker-projects.show', $project)
            ->with('success', __('Project created. Click Deploy to start it.'));
    }

    public function show(Request $request, DockerProject $dockerProject): Response
    {
        $dockerProject->load(['createdBy', 'domainBindings.domain']);

        return Inertia::render('DockerProjects/Show', [
            'project' => $dockerProject,
        ]);
    }

    public function edit(Request $request, DockerProject $dockerProject): Response
    {
        return Inertia::render('DockerProjects/Edit', [
            'project' => $dockerProject,
        ]);
    }

    public function update(UpdateDockerProjectRequest $request, DockerProject $dockerProject): RedirectResponse
    {
        $dockerProject->update($request->validated());

        $projectLabel = $dockerProject->display_name ?? $dockerProject->name;
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'docker_project_updated',
            'summary' => "Updated Docker project \"{$projectLabel}\".",
            'details' => json_encode(['project_id' => $dockerProject->id], JSON_THROW_ON_ERROR),
        ]);

        return redirect()->route('docker-projects.show', $dockerProject)
            ->with('success', __('Project settings saved.'));
    }

    public function destroy(Request $request, DockerProject $dockerProject): RedirectResponse|JsonResponse
    {
        $projectName = $dockerProject->display_name ?? $dockerProject->name;

        try {
            $this->manager->remove($dockerProject);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'docker_project_removed',
                'summary' => "Removed Docker project \"{$projectName}\".",
            ]);

            if ($request->wantsJson()) {
                return response()->json(['message' => __('Project removed successfully.')]);
            }

            return redirect()->route('docker-projects.index')
                ->with('success', __('Project removed successfully.'));
        } catch (\Exception $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'docker_project_remove_failed',
                'summary' => "Failed to remove Docker project \"{$projectName}\": {$e->getMessage()}",
            ]);

            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 500);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function action(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $request->validate(['action' => 'required|in:start,stop,redeploy,build']);

        $actionName = $request->input('action');

        try {
            match ($actionName) {
                'start' => $this->manager->start($dockerProject),
                'stop' => $this->manager->stop($dockerProject),
                'redeploy' => $this->manager->deploy($dockerProject, $request->user()->id),
                'build' => BuildDockerImageJob::dispatch($dockerProject, $request->user()->id),
            };

            $projectLabel = $dockerProject->display_name ?? $dockerProject->name;
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => "docker_project_{$actionName}",
                'summary' => ucfirst($actionName)." Docker project \"{$projectLabel}\".",
            ]);

            return response()->json([
                'message' => __('Action :action executed successfully.', ['action' => $actionName]),
                'status' => $dockerProject->fresh()->status->value,
            ]);
        } catch (\Exception $e) {
            $projectLabel = $dockerProject->display_name ?? $dockerProject->name;
            $errorMessage = $e->getMessage();
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => "docker_project_{$actionName}_failed",
                'summary' => "Failed to {$actionName} Docker project \"{$projectLabel}\": {$errorMessage}",
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function logs(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $serviceName = $request->string('service', '')->toString();
        $tail = $request->integer('tail', 200);

        if (! $serviceName) {
            return response()->json(['logs' => '']);
        }

        $logs = $this->manager->getLogs($dockerProject, $serviceName, $tail);

        return response()->json(['logs' => $logs]);
    }

    public function syncStatus(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $this->manager->syncStatus($dockerProject);

        return response()->json([
            'status' => $dockerProject->fresh()->status->value,
            'status_label' => $dockerProject->fresh()->status->label(),
        ]);
    }
}
