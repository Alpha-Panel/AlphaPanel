<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TerminalOutput implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $sessionId,
        public string $output,
    ) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("terminal.{$this->sessionId}"),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'output' => $this->output,
        ];
    }
}
