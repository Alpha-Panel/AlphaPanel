<?php

namespace App\Jobs;

use App\Models\DomainCronJob;
use App\Models\DomainCronJobLog;
use App\Services\PortainerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteDomainCronJob implements ShouldQueue
{
    use Queueable;

    private const MAX_OUTPUT_LENGTH = 5000;

    private const FRANKENPHP_CONTAINER = 'frankenphp';

    public function __construct(public DomainCronJob $cronJob) {}

    public function handle(PortainerService $portainer): void
    {
        $this->cronJob->loadMissing('domain');
        $domain = $this->cronJob->domain;

        $log = DomainCronJobLog::create([
            'domain_cron_job_id' => $this->cronJob->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        $startTime = microtime(true);

        try {
            $webRoot = $domain->getBasePath().'/httpdocs';
            $command = $this->cronJob->command;
            $script = 'cd '.escapeshellarg($webRoot)." && {$command}";

            $result = $portainer->execInContainer(
                self::FRANKENPHP_CONTAINER,
                ['sh', '-lc', $script],
                300,
            );

            $output = trim($result->output);
            if (trim($result->errorOutput) !== '') {
                $output .= "\n--- STDERR ---\n".trim($result->errorOutput);
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $log->update([
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'status' => $result->isSuccessful() ? 'success' : 'failed',
                'output' => mb_substr($output, 0, self::MAX_OUTPUT_LENGTH),
                'exit_code' => $result->exitCode,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $log->update([
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'status' => 'failed',
                'output' => mb_substr($e->getMessage(), 0, self::MAX_OUTPUT_LENGTH),
                'exit_code' => -1,
            ]);

            Log::error("Cron job #{$this->cronJob->id} execution failed for {$domain->fqdn}: {$e->getMessage()}");
        }
    }
}
