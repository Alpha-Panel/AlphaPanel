<?php

namespace App\Services;

use App\Enums\DockerServiceStatus;
use App\Jobs\DeployDockerServiceJob;
use App\Models\DockerService;
use Illuminate\Support\Facades\Log;

class DockerServiceManager
{
    public function __construct(
        private PortainerService $portainer,
        private ComposeFileService $composeFile,
    ) {}

    /**
     * Deploy a Docker service by dispatching a background job.
     */
    public function deploy(DockerService $service, ?int $triggeredBy = null): void
    {
        DeployDockerServiceJob::dispatch($service, $triggeredBy);
    }

    /**
     * Start a stopped service.
     */
    public function start(DockerService $service): void
    {
        if (! $service->container_id) {
            throw new \RuntimeException("Service {$service->name} has no container ID.");
        }

        $this->portainer->startContainer($service->container_id);
        $service->update(['status' => DockerServiceStatus::Running]);
    }

    /**
     * Stop a running service.
     */
    public function stop(DockerService $service): void
    {
        if (! $service->container_id) {
            throw new \RuntimeException("Service {$service->name} has no container ID.");
        }

        $this->portainer->stopContainer($service->container_id);
        $service->update(['status' => DockerServiceStatus::Stopped]);
    }

    /**
     * Restart a service.
     */
    public function restart(DockerService $service): void
    {
        if (! $service->container_id) {
            throw new \RuntimeException("Service {$service->name} has no container ID.");
        }

        $this->portainer->restartContainer($service->container_id);
        $service->update(['status' => DockerServiceStatus::Running]);
    }

    /**
     * Remove a service and its container.
     */
    public function remove(DockerService $service): void
    {
        $service->update(['status' => DockerServiceStatus::Removing]);

        try {
            if ($service->container_id) {
                $this->portainer->stopContainer($service->container_id);
                $this->portainer->removeContainer($service->container_id, force: true);
            }

            $service->delete();

            // Regenerate compose file
            $this->composeFile->regenerate();

            Log::info("Docker service removed: {$service->name}");
        } catch (\Exception $e) {
            $service->update(['status' => DockerServiceStatus::Failed]);
            Log::error("Failed to remove Docker service {$service->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Get container logs.
     */
    public function getLogs(DockerService $service, int $tail = 200): string
    {
        if (! $service->container_id) {
            return '';
        }

        return $this->portainer->getContainerLogs($service->container_id, $tail);
    }

    /**
     * Get container resource stats.
     *
     * @return array<string, mixed>
     */
    public function getStats(DockerService $service): array
    {
        if (! $service->container_id) {
            return [];
        }

        try {
            $raw = $this->portainer->getContainerStats($service->container_id);

            return $this->parseStats($raw);
        } catch (\Exception $e) {
            Log::warning("Failed to get stats for {$service->name}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Sync service status from Docker.
     */
    public function syncStatus(DockerService $service): void
    {
        if (! $service->container_id) {
            return;
        }

        try {
            $info = $this->portainer->inspectContainer($service->container_id);
            $state = $info['State']['Status'] ?? 'unknown';

            $status = match ($state) {
                'running' => DockerServiceStatus::Running,
                'exited', 'dead' => DockerServiceStatus::Stopped,
                'created', 'restarting' => DockerServiceStatus::Pending,
                default => DockerServiceStatus::Failed,
            };

            $service->update(['status' => $status]);
        } catch (\Exception $e) {
            Log::warning("Failed to sync status for {$service->name}: {$e->getMessage()}");
            $service->update(['status' => DockerServiceStatus::Failed]);
        }
    }

    /**
     * Parse raw Docker stats into readable format.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function parseStats(array $raw): array
    {
        // CPU percentage
        $cpuDelta = ($raw['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($raw['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = ($raw['cpu_stats']['system_cpu_usage'] ?? 0) - ($raw['precpu_stats']['system_cpu_usage'] ?? 0);
        $cpuCount = $raw['cpu_stats']['online_cpus'] ?? 1;
        $cpuPercent = ($systemDelta > 0 && $cpuDelta > 0) ? ($cpuDelta / $systemDelta) * $cpuCount * 100.0 : 0.0;

        // Memory (convert bytes → MB)
        $memUsage = $raw['memory_stats']['usage'] ?? 0;
        $memLimit = $raw['memory_stats']['limit'] ?? 0;

        // Network (convert bytes → human-readable)
        $netRx = 0;
        $netTx = 0;
        foreach ($raw['networks'] ?? [] as $iface) {
            $netRx += $iface['rx_bytes'] ?? 0;
            $netTx += $iface['tx_bytes'] ?? 0;
        }

        return [
            'cpu_percent' => round($cpuPercent, 2),
            'mem_usage_mb' => round($memUsage / 1048576, 1),
            'mem_limit_mb' => round($memLimit / 1048576, 1),
            'net_rx' => $this->formatBytes($netRx),
            'net_tx' => $this->formatBytes($netTx),
        ];
    }

    /**
     * Format bytes into a human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }
}
