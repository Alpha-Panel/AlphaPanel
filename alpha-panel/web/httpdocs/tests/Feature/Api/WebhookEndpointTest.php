<?php

namespace Tests\Feature\Api;

use App\Jobs\SendWebhookJob;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_endpoint_can_be_created(): void
    {
        $endpoint = WebhookEndpoint::create([
            'name' => 'AlphaCenter',
            'url' => 'https://alphacenter.example.com/webhooks',
            'secret' => 'my-secret',
            'events' => ['domain.created', 'domain.deleted'],
            'active' => true,
        ]);

        $this->assertDatabaseHas('webhook_endpoints', ['name' => 'AlphaCenter']);
        $this->assertEquals(['domain.created', 'domain.deleted'], $endpoint->events);
    }

    public function test_send_webhook_job_dispatched_for_event(): void
    {
        Queue::fake();

        WebhookEndpoint::create([
            'name' => 'AlphaCenter',
            'url' => 'https://alphacenter.example.com/webhooks',
            'secret' => encrypt('my-secret'),
            'events' => ['domain.created'],
            'active' => true,
        ]);

        $service = app(\App\Services\WebhookService::class);
        $service->dispatch('domain.created', ['id' => 1, 'fqdn' => 'example.com']);

        Queue::assertPushed(SendWebhookJob::class);
    }
}
