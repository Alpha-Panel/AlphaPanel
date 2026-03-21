<?php

namespace App\Jobs;

use App\Enums\UpdateStatus;
use App\Events\UpdateProgress;
use App\Models\SystemUpdate;
use App\Services\UpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MonitorUpdateProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public SystemUpdate $systemUpdate,
        public string $taskId,
    ) {}

    public function handle(UpdateService $service): void
    {
        $lastPercent = 0;

        while (true) {
            try {
                $status = $service->getTaskStatus($this->taskId);
            } catch (\Throwable $e) {
                Log::error('Failed to poll update task status', [
                    'task_id' => $this->taskId,
                    'error' => $e->getMessage(),
                ]);
                sleep(5);

                continue;
            }

            $percent = $status['percent'] ?? 0;
            $message = $status['message'] ?? '';
            $taskStatus = $status['status'] ?? 'in_progress';

            if ($percent !== $lastPercent || in_array($taskStatus, ['completed', 'failed'])) {
                $this->systemUpdate->update([
                    'progress_percent' => $percent,
                    'message' => $message,
                ]);

                UpdateProgress::dispatch(
                    $this->systemUpdate->id,
                    $percent,
                    $message,
                    $taskStatus,
                );

                $lastPercent = $percent;
            }

            if ($taskStatus === 'completed') {
                $this->systemUpdate->update([
                    'status' => UpdateStatus::Completed,
                    'progress_percent' => 100,
                    'finished_at' => now(),
                ]);

                return;
            }

            if ($taskStatus === 'failed') {
                $this->systemUpdate->update([
                    'status' => UpdateStatus::Failed,
                    'error_message' => $message,
                    'finished_at' => now(),
                ]);

                return;
            }

            sleep(2);
        }
    }
}
