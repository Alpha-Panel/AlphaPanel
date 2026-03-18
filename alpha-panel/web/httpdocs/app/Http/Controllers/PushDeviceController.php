<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PushDeviceController extends Controller
{
    public function index(Request $request): Response
    {
        $subscriptions = $request->user()
            ->pushSubscriptions()
            ->latest()
            ->get()
            ->map(fn (PushSubscription $sub): array => [
                'id' => $sub->id,
                'endpoint' => $sub->endpoint,
                'browser_name' => $sub->browser_name,
                'browser_version' => $sub->browser_version,
                'os_name' => $sub->os_name,
                'device_type' => $sub->device_type,
                'created_at' => $sub->created_at?->toISOString(),
            ]);

        return Inertia::render('User/PushDevices', [
            'subscriptions' => $subscriptions,
        ]);
    }

    public function destroy(Request $request, PushSubscription $pushSubscription): JsonResponse
    {
        if (! $request->user()->ownsPushSubscription($pushSubscription)) {
            abort(403);
        }

        $pushSubscription->delete();

        return response()->json(['status' => 'removed']);
    }
}
