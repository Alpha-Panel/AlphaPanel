<?php

namespace App\Http\Controllers;

use App\Helpers\UserAgentParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
        ]);

        $subscription = $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aesgcm',
        );

        $userAgent = $validated['user_agent'] ?? $request->userAgent() ?? '';
        $deviceInfo = UserAgentParser::parse($userAgent);

        $subscription->update([
            'browser_name' => $deviceInfo['browser_name'],
            'browser_version' => $deviceInfo['browser_version'],
            'os_name' => $deviceInfo['os_name'],
            'device_type' => $deviceInfo['device_type'],
            'user_agent' => $userAgent,
        ]);

        return response()->json(['status' => 'subscribed']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['status' => 'unsubscribed']);
    }

    public function status(Request $request): JsonResponse
    {
        $endpoint = $request->query('endpoint');

        if ($endpoint) {
            $exists = $request->user()
                ->pushSubscriptions()
                ->where('endpoint', $endpoint)
                ->exists();

            return response()->json(['subscribed' => $exists]);
        }

        return response()->json([
            'subscribed' => $request->user()->pushSubscriptions()->exists(),
        ]);
    }
}
