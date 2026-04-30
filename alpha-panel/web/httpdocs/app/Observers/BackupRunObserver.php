<?php

namespace App\Observers;

use App\Models\BackupRun;
use App\Services\WebhookService;

class BackupRunObserver
{
    public function __construct(private readonly WebhookService $webhookService) {}

    public function created(BackupRun $run): void
    {
        $this->webhookService->dispatch('backup.started', ['id' => $run->id]);
    }

    public function updated(BackupRun $run): void
    {
        if (! $run->wasChanged('status')) {
            return;
        }

        $event = match ($run->status) {
            'completed' => 'backup.completed',
            'failed' => 'backup.failed',
            default => null,
        };

        if ($event) {
            $this->webhookService->dispatch($event, ['id' => $run->id, 'status' => $run->status]);
        }
    }
}
