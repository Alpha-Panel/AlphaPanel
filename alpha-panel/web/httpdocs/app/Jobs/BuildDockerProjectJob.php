<?php

namespace App\Jobs;

use App\Enums\DockerProjectStatus;
use App\Events\DockerDeployCompleted;
use App\Events\DockerDeployFailed;
use App\Events\DockerDeployProgress;
use App\Models\AuditLog;
use App\Models\DockerProject;
use App\Services\PortainerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BuildDockerProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public DockerProject $project,
        public ?int $triggeredBy = null,
    ) {}

    public function handle(PortainerService $portainer): void
    {
        $project = $this->project;
        $userId = $this->triggeredBy ?? $project->created_by ?? 0;

        $project->update(['status' => DockerProjectStatus::Building]);
        $this->progress(5, __('Starting project build...'));

        try {
            // Create or update Portainer stack
            $this->progress(20, __('Deploying compose stack...'));

            if ($project->portainer_stack_id) {
                $portainer->updateStack($project->portainer_stack_id, $project->compose_yaml);
            } else {
                $result = $portainer->createStack($project->stackName(), $project->compose_yaml);
                $project->update(['portainer_stack_id' => $result['Id']]);
            }

            // Give containers a moment to start
            $this->progress(70, __('Connecting containers to network...'));
            sleep(3);

            // Connect all project containers to vhost_network so Caddy can reach them
            $portainer->connectProjectContainersToNetwork($project->stackName());

            $this->progress(90, __('Finalizing...'));
            $project->update(['status' => DockerProjectStatus::Running]);

            $this->progress(100, __('Build complete!'));

            $projectLabel = $project->display_name ?? $project->name;
            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'docker_project_deployed',
                'summary' => "Deployed Docker project \"{$projectLabel}\".",
                'details' => json_encode([
                    'project_id' => $project->id,
                    'name' => $project->name,
                    'portainer_stack_id' => $project->portainer_stack_id,
                ], JSON_THROW_ON_ERROR),
            ]);

            DockerDeployCompleted::dispatch($project->id, $project->display_name ?? $project->name, $userId);

            Log::info("Docker project deployed: {$project->name}");
        } catch (\Exception $e) {
            $project->update(['status' => DockerProjectStatus::Failed]);

            $projectLabel = $project->display_name ?? $project->name;
            $errorMessage = $e->getMessage();
            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'docker_project_deploy_failed',
                'summary' => "Failed to deploy Docker project \"{$projectLabel}\": {$errorMessage}",
                'details' => json_encode([
                    'project_id' => $project->id,
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR),
            ]);

            DockerDeployFailed::dispatch(
                $project->id,
                $project->display_name ?? $project->name,
                $userId,
                $e->getMessage(),
            );

            Log::error("Failed to deploy Docker project {$project->name}: {$e->getMessage()}");
        }
    }

    private function progress(int $percent, string $message): void
    {
        $userId = $this->triggeredBy ?? $this->project->created_by ?? 0;

        DockerDeployProgress::dispatch(
            $this->project->id,
            $this->project->display_name ?? $this->project->name,
            $userId,
            $percent,
            $message,
        );
    }
}
