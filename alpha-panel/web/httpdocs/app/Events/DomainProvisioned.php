<?php

namespace App\Events;

use App\Models\Domain;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomainProvisioned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Domain $domain,
    ) {}

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
            'status' => $this->domain->status->value,
        ];
    }
}
