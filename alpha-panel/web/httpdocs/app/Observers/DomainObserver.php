<?php

namespace App\Observers;

use App\Models\Domain;
use App\Services\WebhookService;

class DomainObserver
{
    public function __construct(private readonly WebhookService $webhookService) {}

    public function created(Domain $domain): void
    {
        $this->webhookService->dispatch('domain.created', [
            'id' => $domain->id,
            'fqdn' => $domain->fqdn,
            'type' => $domain->type?->value,
            'status' => $domain->status?->value,
        ]);
    }

    public function updated(Domain $domain): void
    {
        if ($domain->wasChanged()) {
            $this->webhookService->dispatch('domain.updated', [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
                'changed' => array_keys($domain->getChanges()),
            ]);
        }
    }

    public function deleted(Domain $domain): void
    {
        $this->webhookService->dispatch('domain.deleted', [
            'id' => $domain->id,
            'fqdn' => $domain->fqdn,
        ]);
    }
}
