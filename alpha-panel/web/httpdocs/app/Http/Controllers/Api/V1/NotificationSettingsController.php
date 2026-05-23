<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationGroup;
use App\Enums\NotificationType;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationSettingsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        foreach ($validated['preferences'] as $pref) {
            $dbEnabled = (bool) $pref['database'];

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $pref['type']],
                [
                    'database' => $dbEnabled,
                    'push' => $dbEnabled ? (bool) $pref['push'] : false,
                    'mail' => $dbEnabled ? (bool) $pref['mail'] : false,
                ],
            );
        }

        if (array_key_exists('skip_self_push', $validated)) {
            $user->forceFill(['skip_self_push' => (bool) $validated['skip_self_push']])->save();
        }

        return response()->json(['status' => 'saved']);
    }

    public function destroyDevice(Request $request, PushSubscription $pushSubscription): Response
    {
        abort_unless($request->user()->ownsPushSubscription($pushSubscription), 403);

        $pushSubscription->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $user = $request->user();
        $saved = $user->notificationPreferences()
            ->get()
            ->keyBy(fn (NotificationPreference $p): string => $p->type->value);

        $preferences = collect(NotificationType::cases())
            ->map(fn (NotificationType $type): array => [
                'type' => $type->value,
                'database' => $saved->has($type->value) ? $saved[$type->value]->database : true,
                'push' => $saved->has($type->value) ? $saved[$type->value]->push : true,
                'mail' => $saved->has($type->value) ? $saved[$type->value]->mail : false,
            ])
            ->values()
            ->all();

        $types = collect(NotificationType::cases())
            ->map(fn (NotificationType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'icon' => $type->icon(),
                'description' => $type->description(),
                'group' => $type->group()->value,
            ])
            ->values()
            ->all();

        $groups = collect(NotificationGroup::cases())
            ->map(fn (NotificationGroup $g): array => [
                'value' => $g->value,
                'label' => $g->label(),
                'description' => $g->description(),
                'icon' => $g->icon(),
            ])
            ->values()
            ->all();

        $subscriptions = $user->pushSubscriptions()
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
            ])
            ->all();

        return [
            'preferences' => $preferences,
            'types' => $types,
            'groups' => $groups,
            'subscriptions' => $subscriptions,
            'behavior' => [
                'skip_self_push' => (bool) $user->skip_self_push,
            ],
        ];
    }
}
