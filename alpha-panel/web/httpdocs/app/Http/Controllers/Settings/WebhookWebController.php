<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebhookWebController extends Controller
{
    public const AVAILABLE_EVENTS = [
        'domain.created', 'domain.updated', 'domain.deleted', 'domain.provisioned',
        'ssl.renewed', 'ssl.expiring_soon', 'ssl.failed',
        'backup.started', 'backup.completed', 'backup.failed',
        'docker_service.created', 'docker_service.deleted',
        'docker_service.started', 'docker_service.stopped', 'docker_service.failed',
        'system.update_available',
        'cron_job.failed',
        'ftp.banned',
    ];

    public function index(): Response
    {
        $endpoints = WebhookEndpoint::query()->orderBy('name')->get()->map(fn (WebhookEndpoint $ep): array => [
            'id' => $ep->id,
            'name' => $ep->name,
            'url' => $ep->url,
            'events' => $ep->events,
            'active' => $ep->active,
            'last_triggered_at' => $ep->last_triggered_at?->toIso8601String(),
            'last_status_code' => $ep->last_status_code,
        ]);

        return Inertia::render('Settings/Webhooks', [
            'endpoints' => $endpoints,
            'availableEvents' => self::AVAILABLE_EVENTS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'url' => 'required|url|max:500',
            'secret' => 'required|string|min:16',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:'.implode(',', self::AVAILABLE_EVENTS),
            'active' => 'boolean',
        ]);

        $validated['secret'] = encrypt($validated['secret']);
        $endpoint = WebhookEndpoint::create($validated);

        return response()->json(['data' => [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'active' => $endpoint->active,
            'last_triggered_at' => null,
            'last_status_code' => null,
        ]], 201);
    }

    public function update(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'url' => 'sometimes|url|max:500',
            'secret' => 'nullable|string|min:16',
            'events' => 'sometimes|array|min:1',
            'events.*' => 'string|in:'.implode(',', self::AVAILABLE_EVENTS),
            'active' => 'boolean',
        ]);

        if (isset($validated['secret'])) {
            $validated['secret'] = encrypt($validated['secret']);
        } else {
            unset($validated['secret']);
        }

        $endpoint->update($validated);

        return response()->json(['data' => $endpoint->fresh()]);
    }

    public function destroy(WebhookEndpoint $endpoint): JsonResponse
    {
        $endpoint->delete();

        return response()->json(['message' => __('Webhook endpoint deleted.')]);
    }

    public function sendTest(WebhookEndpoint $endpoint): JsonResponse
    {
        $service = app(WebhookService::class);
        $event = $endpoint->events[0] ?? 'domain.created';
        $service->dispatch($event, ['test' => true, 'endpoint_id' => $endpoint->id]);

        return response()->json(['message' => __('Test payload dispatched.')]);
    }

    public function regenerateSecret(WebhookEndpoint $endpoint): JsonResponse
    {
        $newSecret = Str::random(40);
        $endpoint->update(['secret' => encrypt($newSecret)]);

        return response()->json(['data' => ['secret' => $newSecret]]);
    }
}
