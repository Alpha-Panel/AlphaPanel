<?php

namespace App\Jobs;

use App\Enums\DockerServiceStatus;
use App\Events\DockerDeployCompleted;
use App\Events\DockerDeployFailed;
use App\Events\DockerDeployProgress;
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
        $userId = $this->triggeredBy ?? $service->created_by ?? 0;

        $service->update(['status' => DockerServiceStatus::Pending]);
        $this->progress(5, __('Starting deployment...'));

        try {
            // Step 1: Pull image
            $this->progress(10, __('Pulling image :image::tag...', [
                'image' => $service->image,
                'tag' => $service->tag,
            ]));
            $portainer->pullImage($service->image, $service->tag);

            // Step 2: Build container config
            $this->progress(40, __('Creating container...'));
            $config = $this->buildContainerConfig($service, $portainer);

            // Step 3: Create container
            $this->progress(55, __('Configuring container...'));
            $containerId = $portainer->createPersistentContainer($config, $service->name);
            $service->update(['container_id' => $containerId]);

            // Step 4: Start container
            $this->progress(70, __('Starting container...'));
            $portainer->startContainer($containerId);
            $service->update(['status' => DockerServiceStatus::Running]);

            // Step 5: Write compose file
            $this->progress(85, __('Writing compose file...'));
            try {
                $composeFile->writeServiceFile($service);
            } catch (\Exception $e) {
                Log::warning("Compose file write failed for {$service->name}: {$e->getMessage()}");
            }

            // Step 6: Done
            $this->progress(100, __('Deployment complete!'));

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

            DockerDeployCompleted::dispatch($service->id, $service->display_name ?? $service->name, $userId);

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

            DockerDeployFailed::dispatch(
                $service->id,
                $service->display_name ?? $service->name,
                $userId,
                $e->getMessage(),
            );

            Log::error("Failed to deploy Docker service {$service->name}: {$e->getMessage()}");
        }
    }

    /**
     * Broadcast a progress update via WebSocket.
     */
    private function progress(int $percent, string $message): void
    {
        $userId = $this->triggeredBy ?? $this->service->created_by ?? 0;

        DockerDeployProgress::dispatch(
            $this->service->id,
            $this->service->display_name ?? $this->service->name,
            $userId,
            $percent,
            $message,
        );
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
        $volumeBase = rtrim((string) config('panel.docker_services.volume_base_path', '/var/lib/docker-managed'), '/');

        foreach ($service->volumes ?? [] as $vol) {
            $hostPath = $vol['host_path'] ?? "{$volumeBase}/{$service->name}/data";
            $containerPath = $vol['container_path'] ?? '';
            $mode = $vol['mode'] ?? 'rw';

            if (! $containerPath) {
                continue;
            }

            $this->assertHostPathAllowed((string) $hostPath, $volumeBase);
            $this->assertContainerPathAllowed((string) $containerPath);

            $mode = $mode === 'ro' ? 'ro' : 'rw';

            $binds[] = "{$hostPath}:{$containerPath}:{$mode}";
        }

        return $binds;
    }

    /**
     * Defense-in-depth: refuse to bind a host path outside the managed base or onto
     * a sensitive system location, even if request validation was bypassed.
     */
    private function assertHostPathAllowed(string $hostPath, string $volumeBase): void
    {
        $hostPath = trim($hostPath);

        if ($hostPath === '' || ! str_starts_with($hostPath, '/') || str_contains($hostPath, '..') || str_contains($hostPath, "\0")) {
            throw new \RuntimeException("Refusing to bind unsafe host path: {$hostPath}");
        }

        $normalized = rtrim($hostPath, '/');
        $forbiddenPrefixes = ['/etc', '/root', '/proc', '/sys', '/dev', '/boot', '/var/run', '/run', '/var/lib/docker'];

        foreach ($forbiddenPrefixes as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized.'/', $prefix.'/')) {
                throw new \RuntimeException("Refusing to bind sensitive host path: {$hostPath}");
            }
        }

        if (! str_starts_with($normalized.'/', $volumeBase.'/')) {
            throw new \RuntimeException("Host path is outside the managed volume base: {$hostPath}");
        }
    }

    /**
     * Defense-in-depth: never let the Docker socket be mounted into a container.
     */
    private function assertContainerPathAllowed(string $containerPath): void
    {
        $normalized = rtrim(trim($containerPath), '/');

        if (str_contains($containerPath, "\0") || in_array($normalized, ['/var/run/docker.sock', '/run/docker.sock'], true)) {
            throw new \RuntimeException("Refusing to mount onto forbidden container path: {$containerPath}");
        }
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
