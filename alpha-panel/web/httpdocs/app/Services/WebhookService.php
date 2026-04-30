<?php

namespace App\Services;

use App\Jobs\SendWebhookJob;
use App\Models\WebhookEndpoint;

class WebhookService
{
    public function dispatch(string $event, array $data): void
    {
        $endpoints = WebhookEndpoint::query()
            ->where('active', true)
            ->whereJsonContains('events', $event)
            ->get();

        foreach ($endpoints as $endpoint) {
            dispatch(new SendWebhookJob($endpoint, $event, $data));
        }
    }
}
