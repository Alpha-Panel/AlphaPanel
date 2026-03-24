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
use Illuminate\Support\Facades\Log;

class MonitorUpdateProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    private const MAX_CONSECUTIVE_FAILURES = 30;

    public function __construct(
        public SystemUpdate $systemUpdate,
        public string $taskId,
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

                    $this->systemUpdate->update([
                        'status' => UpdateStatus::Failed,
                        'error_message' => __('Update agent became unreachable during monitoring.'),
                        'finished_at' => now(),
                    ]);

                    UpdateProgress::dispatch(
                        $this->systemUpdate->id,
                        $this->systemUpdate->progress_percent,
                        __('Update agent became unreachable.'),
                        'failed',
                        $broadcastType,
                    );

                    return;
                }

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
                    $broadcastType,
                );

                $lastPercent = $percent;
            }

            if ($taskStatus === 'completed') {
                $stage = $this->resolveCompletionStage();

                $this->systemUpdate->update([
                    'status' => UpdateStatus::Completed,
                    'progress_percent' => 100,
                    'finished_at' => now(),
                ]);

                UpdateProgress::dispatch(
                    $this->systemUpdate->id,
                    100,
                    $message,
                    'completed',
                    $broadcastType,
                    $stage,
                );

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

            sleep(3);
        }
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

        // If this was a prepare task (status was in_progress, from_version set, to_version set)
        // the completion means "prepared" stage
        return 'prepared';
    }
}
