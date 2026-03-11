<?php

namespace App\Events;

use App\Models\Domain;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomainProvisionProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Domain $domain,
        public int $percent,
        public string $message,
    ) {}

    public function broadcastAs(): string
    {
        return 'DomainProvisionProgress';
    }

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [
            new Channel('domain.'.$this->domain->id),
            new PrivateChannel('user.'.$this->domain->owner_user_id),
            new PrivateChannel('admin'),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'domain_id' => $this->domain->id,
            'fqdn' => $this->domain->fqdn,
            'percent' => $this->percent,
            'message' => $this->message,
        ];
    }
}
