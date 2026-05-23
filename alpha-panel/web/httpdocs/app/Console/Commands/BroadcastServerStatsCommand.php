<?php

namespace App\Console\Commands;

use App\Events\ServerStatsBroadcast;
use App\Services\HostMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BroadcastServerStatsCommand extends Command
{
    protected $signature = 'system:broadcast-server-stats
        {--interval=15 : Seconds between broadcasts (clamped to [5, 300])}
        {--once : Run a single broadcast cycle and exit (for testing)}';

    protected $description = 'Long-running broadcaster that pushes host CPU/RAM/disk stats to the admin Reverb channel.';

    private const CACHE_KEY = 'sidebar:server-stats:v1';

    private bool $shouldStop = false;

    public function handle(HostMetricsService $metrics): int
    {
        $interval = max(5, min(300, (int) $this->option('interval')));
        $once = (bool) $this->option('once');

        $this->installSignalHandlers();

        $this->info("Broadcasting server stats every {$interval}s on private channel 'admin' (event: ServerStatsBroadcast).");
        Log::info("[ServerStatsBroadcaster] Started (interval={$interval}s).");

        do {
            $start = microtime(true);

            try {
                $payload = $this->collectPayload($metrics);
                Cache::put(self::CACHE_KEY, $this->stripFlag($payload), $interval + 5);
                ServerStatsBroadcast::dispatch($payload);
            } catch (\Throwable $e) {
                Log::warning("[ServerStatsBroadcaster] Broadcast failed: {$e->getMessage()}");
                ServerStatsBroadcast::dispatch($this->errorPayload());
            }

            if ($once || $this->shouldStop) {
                break;
            }

            $elapsed = microtime(true) - $start;
            $sleepFor = max(1, (int) round($interval - $elapsed));

            for ($i = 0; $i < $sleepFor; $i++) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                if ($this->shouldStop) {
                    break 2;
                }
                sleep(1);
            }
        } while (! $this->shouldStop);

        Log::info('[ServerStatsBroadcaster] Stopped.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int|float|bool>
     */
    private function collectPayload(HostMetricsService $metrics): array
    {
        return [
            'has_error' => false,
            ...$metrics->getHostMetrics(),
        ];
    }

    /**
     * @return array<string, int|float|bool>
     */
    private function errorPayload(): array
    {
        return [
            'has_error' => true,
            'cpu_percent' => 0,
            'mem_used_mb' => 0,
            'mem_total_mb' => 0,
            'mem_percent' => 0,
            'disk_used_gb' => 0,
            'disk_total_gb' => 0,
            'disk_percent' => 0,
            'uptime_seconds' => 0,
            'load_1' => 0,
            'load_5' => 0,
            'load_15' => 0,
        ];
    }

    /**
     * @param  array<string, int|float|bool>  $payload
     * @return array<string, int|float|bool>
     */
    private function stripFlag(array $payload): array
    {
        unset($payload['has_error']);

        return $payload;
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            Log::info("[ServerStatsBroadcaster] Received signal {$signal}, draining.");
            $this->shouldStop = true;
        };

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handler);
        }
        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $handler);
        }
    }
}
