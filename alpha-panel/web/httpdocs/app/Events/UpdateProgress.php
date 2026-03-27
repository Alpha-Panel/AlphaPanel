<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdateProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $updateId,
        public int $percent,
        public string $message,
        public string $status = 'in_progress',
        public string $type = 'panel',
        public ?string $stage = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'UpdateProgress';
    }

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin'),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'update_id' => $this->updateId,
            'percent' => $this->percent,
            'message' => $this->message,
            'status' => $this->status,
            'type' => $this->type,
            'stage' => $this->stage,
        ];
    }
}
