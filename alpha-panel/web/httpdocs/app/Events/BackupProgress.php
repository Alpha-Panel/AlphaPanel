<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BackupProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $backupRunId,
        public int $percent,
        public string $message,
        public string $status = 'uploading',
        public string $currentFileName = '',
        public int $currentFilePercent = 0,
        public int $itemsDone = 0,
        public int $itemsTotal = 0,
    ) {}

    /** @return array<int, Channel> */
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
            'backup_run_id' => $this->backupRunId,
            'percent' => $this->percent,
            'message' => $this->message,
            'status' => $this->status,
            'current_file_name' => $this->currentFileName,
            'current_file_percent' => $this->currentFilePercent,
            'items_done' => $this->itemsDone,
            'items_total' => $this->itemsTotal,
        ];
    }
}
