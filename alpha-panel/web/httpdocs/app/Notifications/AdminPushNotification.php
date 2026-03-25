<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Traits\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class AdminPushNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    public function preferenceType(): NotificationType
    {
        return NotificationType::AdminAnnouncements;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'level' => 'info',
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => 'bx bx-megaphone',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/img/android-icon-192x192.png')
            ->data(['url' => $this->url])
            ->options(['TTL' => 86400]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->line($this->body);

        if ($this->url) {
            $mail->action(__('View Details'), $this->url);
        }

        return $mail;
    }
}
