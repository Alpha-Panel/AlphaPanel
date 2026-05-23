<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerStatsBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, int|float|bool>  $payload
     */
    public function __construct(public array $payload) {}

    public function broadcastAs(): string
    {
        return 'ServerStatsBroadcast';
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin')];
    }

    /** @return array<string, int|float|bool> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
