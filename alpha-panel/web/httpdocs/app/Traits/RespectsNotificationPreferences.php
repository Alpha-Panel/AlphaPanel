<?php

namespace App\Traits;

use App\Enums\NotificationType;
use NotificationChannels\WebPush\WebPushChannel;

trait RespectsNotificationPreferences
{
    abstract public function preferenceType(): NotificationType;

    /**
     * The user that triggered the action this notification describes.
     * If the recipient has opted out of self-push and matches this id,
     * the WebPush channel is dropped while in-app/email still flow.
     */
    public function actorUserId(): ?int
    {
        return property_exists($this, 'actorUserId') ? $this->actorUserId : null;
    }

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

        if (! $dbEnabled) {
            return $channels;
        }

        $channels[] = 'database';

        $pushEnabled = $pref?->push ?? true;
        $isSelfActor = $this->actorUserId() !== null
            && property_exists($notifiable, 'id')
            && (int) $notifiable->id === (int) $this->actorUserId();
        $skipSelfPush = $isSelfActor && (bool) ($notifiable->skip_self_push ?? false);

        if ($pushEnabled
            && ! $skipSelfPush
            && method_exists($notifiable, 'pushSubscriptions')
            && $notifiable->pushSubscriptions()->exists()
        ) {
            $channels[] = WebPushChannel::class;
        }

        if ($pref?->mail ?? false) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
