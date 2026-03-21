<?php

namespace App\Jobs;

use App\Enums\DockerServiceStatus;
use App\Models\AuditLog;
use App\Models\DockerService;
use App\Services\ComposeFileService;
use App\Services\PortainerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeployDockerServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public DockerService $service,
        public ?int $triggeredBy = null,
    ) {}

    public function handle(
        PortainerService $portainer,
        ComposeFileService $composeFile,
    ): void {
        $service = $this->service;

        $service->update(['status' => DockerServiceStatus::Pending]);

        try {
            // Pull image
            $portainer->pullImage($service->image, $service->tag);

            // Build container config
            $config = $this->buildContainerConfig($service, $portainer);

            // Create container
            $containerId = $portainer->createPersistentContainer($config, $service->name);

            $service->update(['container_id' => $containerId]);

            // Start container
            $portainer->startContainer($containerId);

            $service->update(['status' => DockerServiceStatus::Running]);

            // Regenerate compose file (best-effort, non-fatal)
            try {
                $composeFile->regenerate();
            } catch (\Exception $e) {
                Log::warning("Compose file regeneration failed for {$service->name}: {$e->getMessage()}");
            }

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'docker_service_deployed',
                'summary' => "Deployed Docker service \"{$service->display_name}\" ({$service->image}:{$service->tag}).",
                'details' => json_encode([
                    'service_id' => $service->id,
                    'image' => $service->image,
                    'tag' => $service->tag,
                    'name' => $service->name,
                    'container_id' => $containerId,
                ], JSON_THROW_ON_ERROR),
            ]);

            Log::info("Docker service deployed: {$service->name}");
        } catch (\Exception $e) {
            $service->update(['status' => DockerServiceStatus::Failed]);

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'docker_service_deploy_failed',
                'summary' => "Failed to deploy Docker service \"{$service->display_name}\": {$e->getMessage()}",
                'details' => json_encode([
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR),
            ]);

            Log::error("Failed to deploy Docker service {$service->name}: {$e->getMessage()}");
        }
    }

    /**
     * Build Docker API container configuration from the service model.
     *
     * @return array<string, mixed>
     */
    private function buildContainerConfig(DockerService $service, PortainerService $portainer): array
    {
        $config = [
            'Image' => "{$service->image}:{$service->tag}",
            'Hostname' => $service->hostname ?? $service->name,
            'Env' => $this->buildEnvArray($service->environment_variables ?? []),
            'HostConfig' => [
                'RestartPolicy' => [
                    'Name' => $service->restart_policy->value,
                ],
            ],
            'NetworkingConfig' => [
                'EndpointsConfig' => [
                    'vhost_network' => new \stdClass,
                ],
            ],
        ];

        // Volumes / Binds
        $binds = $this->buildBinds($service);
        if ($binds) {
            $config['HostConfig']['Binds'] = $binds;
        }

        // Port bindings
        $portBindings = $this->buildPortBindings($service);
        if ($portBindings) {
            $config['HostConfig']['PortBindings'] = $portBindings;
            $config['ExposedPorts'] = array_fill_keys(array_keys($portBindings), new \stdClass);
        }

        // Resource limits
        $limits = $service->resource_limits;
        if (! empty($limits['cpu_limit'])) {
            $config['HostConfig']['NanoCpus'] = (int) ((float) $limits['cpu_limit'] * 1e9);
        }
        if (! empty($limits['memory_limit'])) {
            $config['HostConfig']['Memory'] = $this->parseMemoryLimit($limits['memory_limit']);
        }

        return $config;
    }

    /**
     * @param  array<string, string>  $envVars
     * @return list<string>
     */
    private function buildEnvArray(array $envVars): array
    {
        $result = [];
        foreach ($envVars as $key => $value) {
            $result[] = "{$key}={$value}";
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function buildBinds(DockerService $service): array
    {
        $binds = [];
        $volumeBase = config('panel.docker_services.volume_base_path');

        foreach ($service->volumes ?? [] as $vol) {
            $hostPath = $vol['host_path'] ?? "{$volumeBase}/{$service->name}/data";
            $containerPath = $vol['container_path'] ?? '';
            $mode = $vol['mode'] ?? 'rw';

            if ($containerPath) {
                $binds[] = "{$hostPath}:{$containerPath}:{$mode}";
            }
        }

        return $binds;
    }

    /**
     * @return array<string, list<array{HostPort: string}>>
     */
    private function buildPortBindings(DockerService $service): array
    {
        $bindings = [];

        foreach ($service->ports ?? [] as $port) {
            $containerPort = $port['container_port'] ?? 0;
            $hostPort = $port['host_port'] ?? 0;
            $protocol = $port['protocol'] ?? 'tcp';

            if ($containerPort && $hostPort) {
                $key = "{$containerPort}/{$protocol}";
                $bindings[$key] = [['HostPort' => (string) $hostPort]];
            }
        }

        return $bindings;
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = strtoupper(trim($limit));

        if (str_ends_with($limit, 'G')) {
            return (int) $limit * 1024 * 1024 * 1024;
        }
        if (str_ends_with($limit, 'M')) {
            return (int) $limit * 1024 * 1024;
        }
        if (str_ends_with($limit, 'K')) {
            return (int) $limit * 1024;
        }

        return (int) $limit;
    }
}
