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
        $this->cronJob->loadMissing('domain.ftpUser');
        $domain = $this->cronJob->domain;

        $log = DomainCronJobLog::create([
            'domain_cron_job_id' => $this->cronJob->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        $startTime = microtime(true);

        try {
            $basePath = $domain->getBasePath();
            $webRoot = $basePath.'/httpdocs';
            $command = $this->cronJob->command;

            // Build a sandboxed script that prevents directory traversal
            $escapedWebRoot = escapeshellarg($webRoot);
            $escapedBasePath = escapeshellarg($basePath);
            $script = <<<SH
                cd {$escapedWebRoot} 2>/dev/null || cd {$escapedBasePath} 2>/dev/null || { echo "ERROR: Working directory not found"; exit 126; }
                REAL_CWD=\$(realpath "\$PWD")
                case "\$REAL_CWD" in
                    {$basePath}|{$basePath}/*) ;;
                    *) echo "ERROR: Access denied - working directory outside domain root"; exit 126 ;;
                esac
                {$command}
                SH;

            // Run as the domain's FTP user for filesystem isolation
            $execUser = $domain->ftpUser?->username;

            $result = $portainer->execInContainer(
                self::FRANKENPHP_CONTAINER,
                ['sh', '-c', $script],
                300,
                $execUser,
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
