<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Auth;

class SuppressNotificationsDuringImpersonation
{
    public function __construct(private ImpersonationService $service) {}

    public function handle(NotificationSending $event): bool
    {
        if (! $this->service->isActive()) {
            return true;
        }

        if ($event->notifiable instanceof User
            && $event->notifiable->getKey() === Auth::id()) {
            return false;
        }

        return true;
    }
}
