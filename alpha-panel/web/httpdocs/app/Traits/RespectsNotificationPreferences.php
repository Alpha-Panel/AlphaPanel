<?php

namespace App\Traits;

use App\Enums\NotificationType;
use NotificationChannels\WebPush\WebPushChannel;

trait RespectsNotificationPreferences
{
    abstract public function preferenceType(): NotificationType;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['broadcast'];

        if (method_exists($notifiable, 'notificationPreferences')) {
            $notifiable->loadMissing('notificationPreferences');
            $pref = $notifiable->notificationPreferences
                ->firstWhere('type', $this->preferenceType());
        } else {
            $pref = null;
        }

        // Defaults: database=true, push=true, mail=false
        $dbEnabled = $pref?->database ?? true;

        if ($dbEnabled) {
            $channels[] = 'database';

            if (($pref?->push ?? true)
                && method_exists($notifiable, 'pushSubscriptions')
                && $notifiable->pushSubscriptions()->exists()
            ) {
                $channels[] = WebPushChannel::class;
            }

            if ($pref?->mail ?? false) {
                $channels[] = 'mail';
            }
        }

        return $channels;
    }
}
