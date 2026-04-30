<?php

namespace App\Observers;

use App\Models\DockerService;
use App\Services\WebhookService;

class DockerServiceObserver
{
    public function __construct(private readonly WebhookService $webhookService) {}

    public function created(DockerService $service): void
    {
        $this->webhookService->dispatch('docker_service.created', ['id' => $service->id, 'name' => $service->name]);
    }

    public function deleted(DockerService $service): void
    {
        $this->webhookService->dispatch('docker_service.deleted', ['id' => $service->id, 'name' => $service->name]);
    }

    public function updated(DockerService $service): void
    {
        if (! $service->wasChanged('status')) {
            return;
        }

        $event = match ($service->status) {
            'running' => 'docker_service.started',
            'stopped' => 'docker_service.stopped',
            'failed' => 'docker_service.failed',
            default => null,
        };

        if ($event) {
            $this->webhookService->dispatch($event, ['id' => $service->id, 'name' => $service->name, 'status' => $service->status]);
        }
    }
}
