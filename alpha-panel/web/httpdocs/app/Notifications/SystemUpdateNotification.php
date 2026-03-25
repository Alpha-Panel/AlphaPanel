<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Traits\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class SystemUpdateNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    /**
     * @param  'success'|'error'|'info'|'warning'  $level
     */
    public function __construct(
        public string $level,
        public string $title,
        public string $body,
        public ?string $url = '/system/updates',
        public string $icon = 'bx bx-revision',
    ) {}

    public function preferenceType(): NotificationType
    {
        return NotificationType::SystemUpdates;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'level' => $this->level,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
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
