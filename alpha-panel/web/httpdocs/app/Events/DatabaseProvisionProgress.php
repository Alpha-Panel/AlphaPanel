<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatabaseProvisionProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $domainId,
        public string $dbName,
        public string $message,
        public string $status = 'progress',
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
            'domain_id' => $this->domainId,
            'db_name' => $this->dbName,
            'message' => $this->message,
            'status' => $this->status,
        ];
    }
}
