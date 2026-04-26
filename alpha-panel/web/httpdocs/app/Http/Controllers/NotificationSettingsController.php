<?php

namespace App\Http\Controllers;

use App\Enums\NotificationGroup;
use App\Enums\NotificationType;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('User/NotificationSettings', $this->payload($request, 'preferences'));
    }

    public function devices(Request $request): Response
    {
        return Inertia::render('User/NotificationSettings', $this->payload($request, 'devices'));
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

    public function destroyDevice(Request $request, PushSubscription $pushSubscription): JsonResponse
    {
        if (! $request->user()->ownsPushSubscription($pushSubscription)) {
            abort(403);
        }

        $pushSubscription->delete();

        return response()->json(['status' => 'removed']);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, string $tab): array
    {
        return [
            'tab' => $tab,
            'preferences' => $this->buildPreferences($request),
            'types' => $this->buildTypes(),
            'groups' => $this->buildGroups(),
            'subscriptions' => $this->buildSubscriptions($request),
            'behavior' => [
                'skip_self_push' => (bool) $request->user()->skip_self_push,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function buildPreferences(Request $request): array
    {
        $saved = $request->user()
            ->notificationPreferences()
            ->get()
            ->keyBy(fn (NotificationPreference $p): string => $p->type->value);

        return collect(NotificationType::cases())
            ->map(fn (NotificationType $type): array => [
                'type' => $type->value,
                'database' => $saved->has($type->value) ? $saved[$type->value]->database : true,
                'push' => $saved->has($type->value) ? $saved[$type->value]->push : true,
                'mail' => $saved->has($type->value) ? $saved[$type->value]->mail : false,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string>> */
    private function buildTypes(): array
    {
        return collect(NotificationType::cases())
            ->map(fn (NotificationType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'icon' => $type->icon(),
                'description' => $type->description(),
                'group' => $type->group()->value,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string>> */
    private function buildGroups(): array
    {
        return collect(NotificationGroup::cases())
            ->map(fn (NotificationGroup $g): array => [
                'value' => $g->value,
                'label' => $g->label(),
                'description' => $g->description(),
                'icon' => $g->icon(),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function buildSubscriptions(Request $request): Collection
    {
        return $request->user()
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
    }
}
