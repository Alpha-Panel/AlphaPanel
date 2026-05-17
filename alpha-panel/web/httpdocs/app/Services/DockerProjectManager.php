<?php

namespace App\Services;

use App\Enums\DockerProjectStatus;
use App\Jobs\BuildDockerProjectJob;
use App\Models\DockerProject;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DockerProjectManager
{
    public function __construct(
        private PortainerService $portainer,
    ) {}

    public function deploy(DockerProject $project, ?int $triggeredBy = null): void
    {
        BuildDockerProjectJob::dispatch($project, $triggeredBy);
    }

    public function start(DockerProject $project): void
    {
        if (! $project->portainer_stack_id) {
            throw new \RuntimeException("Project {$project->name} has no Portainer stack.");
        }

        $this->portainer->startStack($project->portainer_stack_id);
        $this->portainer->connectProjectContainersToNetwork($project->stackName());
        $project->update(['status' => DockerProjectStatus::Running]);
    }

    public function stop(DockerProject $project): void
    {
        if (! $project->portainer_stack_id) {
            throw new \RuntimeException("Project {$project->name} has no Portainer stack.");
        }

        $this->portainer->stopStack($project->portainer_stack_id);
        $project->update(['status' => DockerProjectStatus::Stopped]);
    }

    public function remove(DockerProject $project): void
    {
        $project->update(['status' => DockerProjectStatus::Removing]);

        try {
            if ($project->portainer_stack_id) {
                $this->portainer->removeStack($project->portainer_stack_id);
            }

            $projectDir = $project->projectPath();
            $project->delete();

            if (is_dir($projectDir)) {
                File::deleteDirectory($projectDir);
            }

            Log::info("Docker project removed: {$project->name}");
        } catch (\Exception $e) {
            $project->update(['status' => DockerProjectStatus::Failed]);
            Log::error("Failed to remove Docker project {$project->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getLogs(DockerProject $project, string $serviceName, int $tail = 200): string
    {
        $containerName = $project->containerName($serviceName);

        try {
            return $this->portainer->getContainerLogs($containerName, $tail);
        } catch (\Exception $e) {
            Log::warning("Failed to get logs for {$containerName}: {$e->getMessage()}");

            return '';
        }
    }

    public function syncStatus(DockerProject $project): void
    {
        if (! $project->portainer_stack_id) {
            return;
        }

        try {
            $containers = $this->portainer->listContainersByProject($project->stackName());

            if (empty($containers)) {
                $project->update(['status' => DockerProjectStatus::Stopped]);

                return;
            }

            $running = collect($containers)->every(fn ($c) => ($c['State'] ?? '') === 'running');
            $anyRunning = collect($containers)->contains(fn ($c) => ($c['State'] ?? '') === 'running');

            if ($running) {
                $project->update(['status' => DockerProjectStatus::Running]);
            } elseif ($anyRunning) {
                $project->update(['status' => DockerProjectStatus::Running]);
            } else {
                $project->update(['status' => DockerProjectStatus::Stopped]);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to sync status for project {$project->name}: {$e->getMessage()}");
            $project->update(['status' => DockerProjectStatus::Failed]);
        }
    }
}
