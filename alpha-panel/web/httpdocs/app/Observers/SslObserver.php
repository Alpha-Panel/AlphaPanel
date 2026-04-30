<?php

namespace App\Observers;

use App\Models\SslCertificate;
use App\Services\WebhookService;

class SslObserver
{
    public function __construct(private readonly WebhookService $webhookService) {}

    public function updated(SslCertificate $cert): void
    {
        if ($cert->wasChanged('status')) {
            $status = $cert->status;
            $event = match (true) {
                str_contains((string) $status, 'fail') => 'ssl.failed',
                str_contains((string) $status, 'expir') => 'ssl.expiring_soon',
                str_contains((string) $status, 'active') || str_contains((string) $status, 'renew') => 'ssl.renewed',
                default => null,
            };

            if ($event) {
                $this->webhookService->dispatch($event, [
                    'id' => $cert->id,
                    'domain_id' => $cert->domain_id,
                    'status' => $status,
                ]);
            }
        }
    }
}
