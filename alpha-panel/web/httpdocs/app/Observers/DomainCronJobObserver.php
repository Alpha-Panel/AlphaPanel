<?php

namespace App\Observers;

use App\Models\DomainCronJobLog;
use App\Services\WebhookService;

class DomainCronJobObserver
{
    public function __construct(private readonly WebhookService $webhookService) {}

    public function created(DomainCronJobLog $log): void
    {
        if ($log->status === 'failed') {
            $this->webhookService->dispatch('cron_job.failed', [
                'cron_job_id' => $log->domain_cron_job_id,
                'exit_code' => $log->exit_code,
                'output' => substr((string) $log->output, 0, 500),
            ]);
        }
    }
}
