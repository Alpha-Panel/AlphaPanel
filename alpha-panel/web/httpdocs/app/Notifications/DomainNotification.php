<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Traits\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class DomainNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    /**
     * @param  'success'|'error'|'info'  $level
     */
    public function __construct(
        public string $level,
        public string $title,
        public string $body,
        public ?int $domainId = null,
        public ?string $url = null,
        public string $icon = 'bx bx-globe',
        public NotificationType $notificationType = NotificationType::DomainProvisioned,
        public ?int $actorUserId = null,
    ) {}

    public function preferenceType(): NotificationType
    {
        return $this->notificationType;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'level' => $this->level,
            'title' => $this->title,
            'body' => $this->body,
            'domain_id' => $this->domainId,
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
