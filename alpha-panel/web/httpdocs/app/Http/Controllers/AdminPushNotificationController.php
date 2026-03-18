<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendPushNotificationRequest;
use App\Models\Domain;
use App\Models\User;
use App\Notifications\AdminPushNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class AdminPushNotificationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('PushNotifications/Composer');
    }

    public function send(SendPushNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $recipients = $this->resolveRecipients($validated['target'], $validated['domain_id'] ?? null);

        if ($recipients->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => __('No recipients found.')], 422);
        }

        Notification::send(
            $recipients,
            new AdminPushNotification(
                title: $validated['title'],
                body: $validated['body'],
                url: $validated['url'] ?? null,
            ),
        );

        return response()->json([
            'status' => 'success',
            'recipients_count' => $recipients->count(),
        ]);
    }

    /** @return Collection<int, User> */
    private function resolveRecipients(string $target, ?int $domainId): Collection
    {
        return match ($target) {
            'admins' => User::where('admin', true)->get(),
            'domain' => $this->domainRelatedUsers($domainId),
            default => User::all(),
        };
    }

    /** @return Collection<int, User> */
    private function domainRelatedUsers(?int $domainId): Collection
    {
        if (! $domainId) {
            return new Collection;
        }

        $domain = Domain::with(['owner', 'authorizedUsers'])->find($domainId);

        if (! $domain) {
            return new Collection;
        }

        $users = collect([$domain->owner])
            ->merge($domain->authorizedUsers)
            ->unique('id')
            ->values();

        return new Collection($users->all());
    }
}
