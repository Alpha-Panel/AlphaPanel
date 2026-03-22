<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DockerDeployFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $serviceId,
        public string $serviceName,
        public int $userId,
        public string $error,
    ) {}

    public function broadcastAs(): string
    {
        return 'DockerDeployFailed';
    }

    /** @return array<int, Channel> */
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
            'service_id' => $this->serviceId,
            'service_name' => $this->serviceName,
            'error' => $this->error,
        ];
    }
}
