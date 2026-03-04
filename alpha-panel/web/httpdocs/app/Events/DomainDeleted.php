<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomainDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $fqdn,
    ) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
            new PrivateChannel('admin'),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'fqdn' => $this->fqdn,
            'message' => "Domain {$this->fqdn} has been deleted.",
        ];
    }
}
