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

class BuildDockerImageJob implements ShouldQueue
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
        $this->progress(5, __('Starting image build...'));

        try {
            $portainer->buildImage($project, function (int $percent, string $message) {
                $this->progress($percent, $message);
            });

            $this->progress(100, __('Image built successfully!'));

            $project->update(['status' => DockerProjectStatus::Stopped]);

            $projectLabel = $project->display_name ?? $project->name;
            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'docker_project_image_built',
                'summary' => "Built Docker image for project \"{$projectLabel}\".",
                'details' => json_encode(['project_id' => $project->id, 'image' => $project->imageTag()], JSON_THROW_ON_ERROR),
            ]);

            DockerDeployCompleted::dispatch($project->id, $project->display_name ?? $project->name, $userId);

            Log::info("Docker image built: {$project->imageTag()}");
        } catch (\Exception $e) {
            $project->update(['status' => DockerProjectStatus::Failed]);

            $projectLabel = $project->display_name ?? $project->name;
            $errorMessage = $e->getMessage();
            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'docker_project_build_failed',
                'summary' => "Failed to build image for project \"{$projectLabel}\": {$errorMessage}",
                'details' => json_encode(['project_id' => $project->id, 'error' => $errorMessage], JSON_THROW_ON_ERROR),
            ]);

            DockerDeployFailed::dispatch(
                $project->id,
                $project->display_name ?? $project->name,
                $userId,
                $errorMessage,
            );

            Log::error("Failed to build Docker image for project {$project->name}: {$errorMessage}");
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
