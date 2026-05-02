<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RefreshToken;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandshakeController extends ApiController
{
    /**
     * Register AlphaCenter's webhook endpoint and return confirmation.
     *
     * AlphaCenter sends its webhook URL + secret so AlphaPanel knows
     * where to push events. We also persist this on the refresh token row
     * so the association survives token rotation.
     */
    public function registerWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'webhook_url' => 'required|url|max:500',
            'webhook_secret' => 'required|string|min:16|max:255',
        ]);

        // Upsert a WebhookEndpoint row for this registration
        $endpoint = WebhookEndpoint::updateOrCreate(
            ['url' => $validated['webhook_url']],
            [
                'name' => 'AlphaCenter',
                'secret' => encrypt($validated['webhook_secret']),
                'events' => ['*'],
                'active' => true,
            ]
        );

        // Persist webhook info on the most recent active refresh token for this user
        RefreshToken::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->limit(1)
            ->update([
                'alpha_center_webhook_url' => $validated['webhook_url'],
                'alpha_center_webhook_secret' => encrypt($validated['webhook_secret']),
            ]);

        return response()->json([
            'data' => [
                'registered' => true,
                'webhook_endpoint_id' => $endpoint->id,
            ],
        ]);
    }
}
