<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class HostMetricsService
{
    public function __construct(private PortainerService $portainer) {}

    /**
     * Get host-level CPU, RAM, and disk metrics via /proc and df.
     *
     * @return array{cpu_percent: float, mem_used_mb: int, mem_total_mb: int, mem_percent: float, disk_used_gb: float, disk_total_gb: float, disk_percent: float, uptime_seconds: int, load_1: float, load_5: float, load_15: float}
     */
    public function getHostMetrics(): array
    {
        $container = config('panel.frankenphp_container', 'frankenphp');

        $result = $this->portainer->execInContainer($container, ['sh', '-c',
            'head -1 /proc/stat; sleep 0.3; head -1 /proc/stat; grep -E "^(MemTotal|MemAvailable):" /proc/meminfo; df -k / | tail -1; cat /proc/uptime; cat /proc/loadavg',
        ], timeout: 10);

        $lines = array_values(array_filter(explode("\n", trim($result->output))));

        return [
            ...$this->parseCpu($lines[0] ?? '', $lines[1] ?? ''),
            ...$this->parseMemory($lines[2] ?? '', $lines[3] ?? ''),
            ...$this->parseDisk($lines[4] ?? ''),
            ...$this->parseUptime($lines[5] ?? ''),
            ...$this->parseLoadAverage($lines[6] ?? ''),
        ];
    }

    /**
     * Get stats for all Docker containers.
     *
     * @return array<int, array{name: string, image: string, status: string, state: string, cpu_percent: float, mem_mb: float}>
     */
    public function getContainerStats(): array
    {
        $containers = $this->portainer->listContainers(all: true);

        return collect($containers)->map(function (array $c): array {
            $name = ltrim($c['Names'][0] ?? 'unknown', '/');
            $state = $c['State'] ?? 'unknown';

            $cpuPercent = 0.0;
            $memMb = 0.0;

            if ($state === 'running') {
                try {
                    $stats = $this->portainer->getContainerStats($c['Id']);
                    $calculated = $this->calculateContainerCpu($stats);
                    $cpuPercent = $calculated['cpu_percent'];
                    $memMb = $calculated['mem_mb'];
                } catch (\Throwable $e) {
                    Log::debug("Container stats failed for {$name}: {$e->getMessage()}");
                }
            }

            return [
                'id' => $c['Id'],
                'name' => $name,
                'image' => $this->shortImage($c['Image'] ?? ''),
                'status' => $c['Status'] ?? $state,
                'state' => $state,
                'cpu_percent' => $cpuPercent,
                'mem_mb' => $memMb,
            ];
        })->sortByDesc('cpu_percent')->values()->all();
    }

    /**
     * @return array{cpu_percent: float}
     */
    private function parseCpu(string $line1, string $line2): array
    {
        $parse = function (string $line): array {
            $parts = preg_split('/\s+/', trim($line));
            array_shift($parts); // remove "cpu" label

            return array_map('intval', $parts);
        };

        $s1 = $parse($line1);
        $s2 = $parse($line2);

        if (count($s1) < 4 || count($s2) < 4) {
            return ['cpu_percent' => 0.0];
        }

        $idle1 = $s1[3] + ($s1[4] ?? 0);
        $idle2 = $s2[3] + ($s2[4] ?? 0);
        $total1 = array_sum($s1);
        $total2 = array_sum($s2);

        $totalDelta = $total2 - $total1;
        $idleDelta = $idle2 - $idle1;

        if ($totalDelta <= 0) {
            return ['cpu_percent' => 0.0];
        }

        return ['cpu_percent' => round(100 - ($idleDelta / $totalDelta * 100), 1)];
    }

    /**
     * @return array{mem_used_mb: int, mem_total_mb: int, mem_percent: float}
     */
    private function parseMemory(string $totalLine, string $availableLine): array
    {
        preg_match('/MemTotal:\s+(\d+)/', $totalLine, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+)/', $availableLine, $availMatch);

        $totalKb = (int) ($totalMatch[1] ?? 0);
        $availKb = (int) ($availMatch[1] ?? 0);
        $usedKb = $totalKb - $availKb;

        $totalMb = (int) round($totalKb / 1024);
        $usedMb = (int) round($usedKb / 1024);
        $percent = $totalKb > 0 ? round($usedKb / $totalKb * 100, 1) : 0;

        return ['mem_used_mb' => $usedMb, 'mem_total_mb' => $totalMb, 'mem_percent' => $percent];
    }

    /**
     * @return array{disk_used_gb: float, disk_total_gb: float, disk_percent: float}
     */
    private function parseDisk(string $dfLine): array
    {
        $parts = preg_split('/\s+/', trim($dfLine));

        // df output: Filesystem 1K-blocks Used Available Use% Mounted
        $totalKb = (int) ($parts[1] ?? 0);
        $usedKb = (int) ($parts[2] ?? 0);

        $totalGb = round($totalKb / 1024 / 1024, 1);
        $usedGb = round($usedKb / 1024 / 1024, 1);
        $percent = $totalKb > 0 ? round($usedKb / $totalKb * 100, 1) : 0;

        return ['disk_used_gb' => $usedGb, 'disk_total_gb' => $totalGb, 'disk_percent' => $percent];
    }

    /**
     * @return array{uptime_seconds: int}
     */
    private function parseUptime(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line));

        return ['uptime_seconds' => (int) ($parts[0] ?? 0)];
    }

    /**
     * @return array{load_1: float, load_5: float, load_15: float}
     */
    private function parseLoadAverage(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line));

        return [
            'load_1' => round((float) ($parts[0] ?? 0), 2),
            'load_5' => round((float) ($parts[1] ?? 0), 2),
            'load_15' => round((float) ($parts[2] ?? 0), 2),
        ];
    }

    /**
     * @return array{cpu_percent: float, mem_mb: float}
     */
    private function calculateContainerCpu(array $stats): array
    {
        $cpuDelta = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0)
            - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0)
            - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
        $numCpus = $stats['cpu_stats']['online_cpus']
            ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? [1]);

        $cpuPercent = ($systemDelta > 0)
            ? round(($cpuDelta / $systemDelta) * $numCpus * 100.0, 2)
            : 0.0;

        $memUsage = $stats['memory_stats']['usage'] ?? 0;
        $memCache = $stats['memory_stats']['stats']['cache'] ?? $stats['memory_stats']['stats']['inactive_file'] ?? 0;
        $memMb = round(($memUsage - $memCache) / 1024 / 1024, 1);

        return ['cpu_percent' => $cpuPercent, 'mem_mb' => max(0, $memMb)];
    }

    private function shortImage(string $image): string
    {
        // Strip sha256 digests and long registry prefixes
        $image = preg_replace('/@sha256:.+$/', '', $image);
        $parts = explode('/', $image);

        return end($parts);
    }
}
