<?php

namespace App\Services;

use App\Models\DockerService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

class ComposeFileService
{
    /**
     * Regenerate all individual service compose files and rebuild the index.
     */
    public function regenerate(): void
    {
        $services = DockerService::all();

        foreach ($services as $service) {
            $this->writeServiceYaml($service);
        }

        $this->rebuildIndex();
        $this->ensureIndexIncluded();
        $this->cleanupLegacyFiles();
    }

    /**
     * Write compose file for a single service and update the aggregator index.
     */
    public function writeServiceFile(DockerService $service): void
    {
        $this->writeServiceYaml($service);
        $this->rebuildIndex();
        $this->ensureIndexIncluded();

        Log::info("Docker service compose file written: {$service->name}");
    }

    /**
     * Remove compose file for a service and update the aggregator index.
     */
    public function removeServiceFile(DockerService $service): void
    {
        $this->removeServiceFileByName($service->name);
    }

    /**
     * Remove compose file by service name and rebuild the index.
     */
    public function removeServiceFileByName(string $serviceName): void
    {
        $path = $this->getServiceFilePath($serviceName);

        if (File::exists($path)) {
            File::delete($path);
            Log::info("Docker service compose file removed: {$path}");
        }

        $this->rebuildIndex();
    }

    /**
     * Generate compose YAML for a single Docker service.
     *
     * The document is assembled as a structured array and serialized with Symfony's
     * YAML dumper so that every scalar (image, tag, hostname, env keys/values, volume
     * paths) is safely quoted/escaped. Building YAML via raw string interpolation would
     * allow a crafted value to inject arbitrary compose directives (YAML injection).
     */
    public function generateServiceYaml(DockerService $service): string
    {
        $serviceDef = [
            'image' => "{$service->image}:{$service->tag}",
            'container_name' => $service->name,
            'restart' => $service->restart_policy->value,
        ];

        if ($service->hostname) {
            $serviceDef['hostname'] = $this->sanitizeHostname((string) $service->hostname);
        }

        // Environment variables (KEY=VALUE entries; invalid keys are skipped)
        $environment = [];
        foreach ($service->environment_variables ?? [] as $key => $value) {
            $key = (string) $key;
            if (! $this->isValidEnvKey($key)) {
                continue;
            }
            $environment[] = "{$key}={$value}";
        }
        if ($environment !== []) {
            $serviceDef['environment'] = $environment;
        }

        // Volumes (host:container:mode entries)
        $volumes = [];
        $volumeBase = rtrim((string) config('panel.docker_services.volume_base_path', '/var/lib/docker-managed'), '/');
        foreach ($service->volumes ?? [] as $vol) {
            $hostPath = $vol['host_path'] ?? "{$volumeBase}/{$service->name}/data";
            $containerPath = $vol['container_path'] ?? '';
            $mode = ($vol['mode'] ?? 'rw') === 'ro' ? 'ro' : 'rw';
            if ($containerPath) {
                $volumes[] = "{$hostPath}:{$containerPath}:{$mode}";
            }
        }
        if ($volumes !== []) {
            $serviceDef['volumes'] = $volumes;
        }

        // Ports
        $ports = [];
        foreach ($service->ports ?? [] as $port) {
            $hostPort = (int) ($port['host_port'] ?? 0);
            $containerPort = (int) ($port['container_port'] ?? 0);
            $protocol = ($port['protocol'] ?? 'tcp') === 'udp' ? 'udp' : 'tcp';
            if ($hostPort && $containerPort) {
                $portStr = "{$hostPort}:{$containerPort}";
                if ($protocol !== 'tcp') {
                    $portStr .= "/{$protocol}";
                }
                $ports[] = $portStr;
            }
        }
        if ($ports !== []) {
            $serviceDef['ports'] = $ports;
        }

        // Resource limits
        $limits = $service->resource_limits;
        if (! empty($limits['cpu_limit']) || ! empty($limits['memory_limit'])) {
            $resourceLimits = [];
            if (! empty($limits['cpu_limit'])) {
                $resourceLimits['cpus'] = (string) $limits['cpu_limit'];
            }
            if (! empty($limits['memory_limit'])) {
                $resourceLimits['memory'] = (string) $limits['memory_limit'];
            }
            $serviceDef['deploy'] = [
                'resources' => [
                    'limits' => $resourceLimits,
                ],
            ];
        }

        // Networks
        $networks = ['vhost_network'];
        foreach ($service->networks ?? [] as $net) {
            $net = is_string($net) ? trim($net) : '';
            if ($net !== '' && $net !== 'vhost_network' && ! in_array($net, $networks, true)) {
                $networks[] = $net;
            }
        }
        $serviceDef['networks'] = $networks;

        $document = [
            'services' => [
                $service->name => $serviceDef,
            ],
            'networks' => [
                'vhost_network' => [
                    'external' => true,
                ],
            ],
        ];

        $header = "# Auto-generated by AlphaPanel for service: {$service->name}\n";
        $yaml = Yaml::dump($document, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        return $header.$yaml;
    }

    /**
     * Restrict a hostname to RFC-1123 safe characters; fall back to a stripped form.
     */
    private function sanitizeHostname(string $hostname): string
    {
        $hostname = trim($hostname);

        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $hostname) === 1) {
            return $hostname;
        }

        return preg_replace('/[^a-zA-Z0-9.-]/', '', $hostname) ?? '';
    }

    /**
     * Validate an environment variable key (POSIX-style identifier).
     */
    private function isValidEnvKey(string $key): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) === 1;
    }

    /**
     * Write the YAML file for a service to disk.
     */
    private function writeServiceYaml(DockerService $service): void
    {
        $path = $this->getServiceFilePath($service->name);
        $yaml = $this->generateServiceYaml($service);

        $dir = dirname($path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $yaml);
    }

    /**
     * Get the file path for a service compose file.
     */
    private function getServiceFilePath(string $serviceName): string
    {
        $dir = config('panel.docker_services.compose_dir');

        return "{$dir}/{$serviceName}.yaml";
    }

    /**
     * Rebuild the aggregator docker-compose.yaml from directory contents.
     *
     * Scans the compose directory for all *.yaml files (excluding the index itself)
     * and generates an include list. This is the single source of truth — whatever
     * files exist in the directory are included automatically.
     */
    private function rebuildIndex(): void
    {
        $dir = config('panel.docker_services.compose_dir');
        $indexPath = "{$dir}/docker-compose.yaml";

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Scan directory for service YAML files (exclude the aggregator itself)
        $files = collect(File::glob("{$dir}/*.yaml"))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => $name !== 'docker-compose.yaml')
            ->sort()
            ->values();

        $lines = ['# Auto-generated by AlphaPanel — do not edit manually'];

        if ($files->isEmpty()) {
            $lines[] = 'services: {}';
        } else {
            $lines[] = 'include:';
            foreach ($files as $file) {
                $lines[] = "  - ./{$file}";
            }
        }

        File::put($indexPath, implode("\n", $lines)."\n");

        Log::info("Docker services index rebuilt with {$files->count()} service(s).");
    }

    /**
     * Ensure the aggregator is included in local-services.yaml (one-time setup).
     */
    private function ensureIndexIncluded(): void
    {
        $localServicesPath = config('panel.docker_services.local_services_path');
        $includeLine = './docker-services/docker-compose.yaml';

        if (! File::exists($localServicesPath)) {
            Log::warning("Local services file not found: {$localServicesPath}");

            return;
        }

        $content = File::get($localServicesPath);

        if (str_contains($content, $includeLine)) {
            return; // Already included
        }

        $content = rtrim($content)."\n  - {$includeLine}\n";
        File::put($localServicesPath, $content);

        Log::info('Added docker-services aggregator to local services include list.');
    }

    /**
     * Remove legacy monolithic docker-services.yaml if it exists in the parent directory.
     */
    private function cleanupLegacyFiles(): void
    {
        $parentDir = dirname(config('panel.docker_services.compose_dir'));
        $legacyFile = "{$parentDir}/docker-services.yaml";

        if (! File::exists($legacyFile)) {
            return;
        }

        File::delete($legacyFile);
        Log::info('Removed legacy monolithic docker-services.yaml file.');

        // Also remove the old include line from local-services.yaml
        $localServicesPath = config('panel.docker_services.local_services_path');
        if (File::exists($localServicesPath)) {
            $content = File::get($localServicesPath);
            $content = preg_replace('/\n\s*-\s*\.\/docker-services\.yaml/', '', $content);
            File::put($localServicesPath, $content);
        }
    }
}
