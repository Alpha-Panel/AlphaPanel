<?php

namespace App\Console\Commands;

use App\Models\AlertSetting;
use App\Models\SystemAlert;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use App\Services\HostMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckSystemHealth extends Command
{
    protected $signature = 'system:check-health';

    protected $description = 'Check system metrics against alert thresholds and send notifications';

    public function handle(HostMetricsService $metricsService): int
    {
        $settings = AlertSetting::instance();

        if (! $settings->enabled) {
            return self::SUCCESS;
        }

        try {
            $metrics = $metricsService->getHostMetrics();
        } catch (\Throwable $e) {
            $this->warn("Failed to retrieve host metrics: {$e->getMessage()}");

            return self::FAILURE;
        }

        $values = [
            'cpu' => (float) ($metrics['cpu_percent'] ?? 0),
            'ram' => (float) ($metrics['mem_percent'] ?? 0),
            'disk' => (float) ($metrics['disk_percent'] ?? 0),
        ];

        foreach ($values as $metric => $value) {
            $this->checkMetric($settings, $metric, $value);
        }

        return self::SUCCESS;
    }

    private function checkMetric(AlertSetting $settings, string $metric, float $value): void
    {
        $warningThreshold = $settings->getThreshold($metric, 'warning');
        $criticalThreshold = $settings->getThreshold($metric, 'critical');

        $unresolvedAlert = SystemAlert::unresolved()->forMetric($metric)->latest()->first();

        // Check if metric is below warning (recovery)
        if ($value < $warningThreshold) {
            if ($unresolvedAlert) {
                $unresolvedAlert->update([
                    'resolved_at' => now(),
                    'resolved_value' => $value,
                ]);

                $this->notifyAdmins(new SystemAlertNotification($unresolvedAlert->fresh(), isRecovery: true));
                $this->info("Recovery: {$metric} dropped to {$value}%");
            }

            return;
        }

        // Determine alert level
        $level = $value >= $criticalThreshold ? 'critical' : 'warning';
        $threshold = $value >= $criticalThreshold ? $criticalThreshold : $warningThreshold;

        // If existing unresolved alert at same or higher level, check cooldown
        if ($unresolvedAlert) {
            // Upgrade warning to critical if needed
            if ($unresolvedAlert->level === 'warning' && $level === 'critical') {
                $unresolvedAlert->update([
                    'resolved_at' => now(),
                    'resolved_value' => $value,
                ]);

                $this->createAndNotify($metric, $level, $value, $threshold);

                return;
            }

            // Same level - check cooldown before re-notifying
            if ($unresolvedAlert->notified_at && $unresolvedAlert->notified_at->diffInMinutes(now()) < $settings->cooldown_minutes) {
                return;
            }

            // Re-notify (cooldown expired)
            $unresolvedAlert->update(['notified_at' => now()]);
            $this->notifyAdmins(new SystemAlertNotification($unresolvedAlert));

            return;
        }

        // No existing alert - create new one
        $this->createAndNotify($metric, $level, $value, $threshold);
    }

    private function createAndNotify(string $metric, string $level, float $value, int $threshold): void
    {
        $alert = SystemAlert::create([
            'metric' => $metric,
            'level' => $level,
            'value' => $value,
            'threshold' => $threshold,
            'notified_at' => now(),
        ]);

        $this->notifyAdmins(new SystemAlertNotification($alert));
        $this->warn("Alert: {$metric} at {$value}% ({$level}, threshold: {$threshold}%)");
    }

    private function notifyAdmins(SystemAlertNotification $notification): void
    {
        $admins = User::where('admin', true)->get();
        Notification::send($admins, $notification);
    }
}
