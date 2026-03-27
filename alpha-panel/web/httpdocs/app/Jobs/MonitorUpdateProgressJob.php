<?php

namespace App\Jobs;

use App\Enums\UpdateStatus;
use App\Enums\UpdateType;
use App\Events\UpdateProgress;
use App\Models\SystemUpdate;
use App\Services\UpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorUpdateProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 1800;

    private const MAX_CONSECUTIVE_FAILURES = 30;

    public function __construct(
        public SystemUpdate $systemUpdate,
        public string $taskId,
        public string $operation = 'prepare',
    ) {}

    public function handle(UpdateService $service): void
    {
        $lastPercent = 0;
        $consecutiveFailures = 0;
        $broadcastType = $this->resolveBroadcastType();

        while (true) {
            try {
                $status = $service->getTaskStatus($this->taskId);
                $consecutiveFailures = 0;
            } catch (\Throwable $e) {
                $consecutiveFailures++;

                Log::warning('Failed to poll update task status', [
                    'task_id' => $this->taskId,
                    'attempt' => $consecutiveFailures,
                    'error' => $e->getMessage(),
                ]);

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    Log::error('Update monitoring aborted: agent unreachable', [
                        'task_id' => $this->taskId,
                        'update_id' => $this->systemUpdate->id,
                    ]);

                    $this->markFailed(__('Update agent became unreachable during monitoring.'), $broadcastType);

                    return;
                }

                sleep(5);

                continue;
            }

            $percent = $status['percent'] ?? 0;
            $message = $status['message'] ?? '';
            $taskStatus = $status['status'] ?? 'in_progress';

            try {
                if ($percent !== $lastPercent || in_array($taskStatus, ['completed', 'failed'])) {
                    $this->systemUpdate->update([
                        'progress_percent' => $percent,
                        'message' => $message,
                    ]);

                    $this->broadcastSafely($this->systemUpdate->id, $percent, $message, $taskStatus, $broadcastType);

                    $lastPercent = $percent;
                }

                if ($taskStatus === 'completed') {
                    $stage = $this->resolveCompletionStage();

                    $this->systemUpdate->update([
                        'status' => UpdateStatus::Completed,
                        'progress_percent' => 100,
                        'finished_at' => now(),
                    ]);

                    if ($this->systemUpdate->type === UpdateType::PanelUpdate
                        || ($this->systemUpdate->type === UpdateType::MysqlUpgrade && $this->operation === 'apply')
                    ) {
                        Cache::forget('system:update_available');
                    }

                    $this->broadcastSafely($this->systemUpdate->id, 100, $message, 'completed', $broadcastType, $stage);

                    return;
                }

                if ($taskStatus === 'failed') {
                    $this->markFailed($message, $broadcastType);

                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('MonitorUpdateProgressJob: error updating state/broadcasting', [
                    'error' => $e->getMessage(),
                    'update_id' => $this->systemUpdate->id,
                ]);
                // Don't kill the loop — keep polling, DB/broadcast may recover
            }

            sleep(3);
        }
    }

    private function broadcastSafely(int $updateId, int $percent, string $message, string $status, string $type, ?string $stage = null): void
    {
        try {
            UpdateProgress::dispatch($updateId, $percent, $message, $status, $type, $stage);
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast UpdateProgress', ['error' => $e->getMessage()]);
        }
    }

    private function markFailed(string $message, string $broadcastType): void
    {
        try {
            $this->systemUpdate->update([
                'status' => UpdateStatus::Failed,
                'error_message' => $message,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update SystemUpdate status', ['error' => $e->getMessage()]);
        }

        $this->broadcastSafely(
            $this->systemUpdate->id,
            $this->systemUpdate->progress_percent,
            $message,
            'failed',
            $broadcastType,
        );
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('MonitorUpdateProgressJob failed', [
            'update_id' => $this->systemUpdate->id,
            'task_id' => $this->taskId,
            'error' => $exception?->getMessage(),
        ]);

        if ($this->systemUpdate->status === UpdateStatus::InProgress) {
            $this->systemUpdate->update([
                'status' => UpdateStatus::Failed,
                'error_message' => __('Monitoring job failed: :message', ['message' => $exception?->getMessage() ?? 'unknown']),
                'finished_at' => now(),
            ]);
        }
    }

    private function resolveBroadcastType(): string
    {
        return match ($this->systemUpdate->type) {
            UpdateType::MysqlUpgrade => 'mysql',
            UpdateType::PanelUpdate => 'panel',
            default => 'service',
        };
    }

    private function resolveCompletionStage(): ?string
    {
        if ($this->systemUpdate->type !== UpdateType::MysqlUpgrade) {
            return null;
        }

        return match ($this->operation) {
            'apply' => 'applied',
            'rollback' => 'idle',
            default => 'prepared',
        };
    }
}
