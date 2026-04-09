<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\SystemAlert;
use App\Traits\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class SystemAlertNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public SystemAlert $alert,
        public bool $isRecovery = false,
    ) {}

    public function preferenceType(): NotificationType
    {
        return NotificationType::SystemAlert;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $metric = $this->alert->metric;

        if ($this->isRecovery) {
            return [
                'level' => 'success',
                'title' => __(':metric usage recovered', ['metric' => strtoupper($metric)]),
                'body' => __(':metric usage dropped to :value% (was :level at :threshold%)', [
                    'metric' => strtoupper($metric),
                    'value' => $this->alert->resolved_value,
                    'level' => $this->alert->level,
                    'threshold' => $this->alert->threshold,
                ]),
                'url' => '/settings/alerts',
                'icon' => 'bx bx-check-circle',
            ];
        }

        return [
            'level' => $this->alert->level === 'critical' ? 'error' : 'warning',
            'title' => __(':metric :level alert', ['metric' => strtoupper($metric), 'level' => $this->alert->level]),
            'body' => __(':metric usage at :value% (threshold: :threshold%)', [
                'metric' => strtoupper($metric),
                'value' => $this->alert->value,
                'threshold' => $this->alert->threshold,
            ]),
            'url' => '/settings/alerts',
            'icon' => $this->alert->level === 'critical' ? 'bx bx-error-circle' : 'bx bx-error',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $data = $this->toArray($notifiable);

        return (new WebPushMessage)
            ->title($data['title'])
            ->body($data['body'])
            ->icon('/img/android-icon-192x192.png')
            ->data(['url' => $data['url']])
            ->options(['TTL' => 86400]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->subject($data['title'])
            ->line($data['body'])
            ->action(__('View Details'), url($data['url']));
    }
}
