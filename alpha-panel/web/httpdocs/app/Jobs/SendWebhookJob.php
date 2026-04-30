<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly WebhookEndpoint $endpoint,
        private readonly string $event,
        private readonly array $data,
    ) {}

    public function handle(): void
    {
        $deliveryId = Str::uuid()->toString();
        $payload = [
            'event' => $this->event,
            'panel_id' => substr(md5(config('app.key')), 0, 16),
            'timestamp' => now()->toIso8601String(),
            'data' => $this->data,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $secret = $this->endpoint->getPlainSecret();
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-AlphaPanel-Signature' => $signature,
                'X-AlphaPanel-Event' => $this->event,
                'X-AlphaPanel-Delivery' => $deliveryId,
            ])
                ->timeout(15)
                ->post($this->endpoint->url, $payload);

            $this->endpoint->update([
                'last_triggered_at' => now(),
                'last_status_code' => $response->status(),
            ]);

            if (! $response->successful()) {
                Log::warning("[Webhook] Delivery {$deliveryId} to {$this->endpoint->url} returned {$response->status()}");
                $this->fail("HTTP {$response->status()}");
            }
        } catch (\Throwable $e) {
            Log::error("[Webhook] Delivery {$deliveryId} to {$this->endpoint->url} failed: {$e->getMessage()}");
            $this->fail($e);
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 300, 1800];
    }
}
