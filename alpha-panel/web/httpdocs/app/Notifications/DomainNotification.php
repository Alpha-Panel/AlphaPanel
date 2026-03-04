<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class DomainNotification extends Notification
{
    use Queueable;

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
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
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
}
