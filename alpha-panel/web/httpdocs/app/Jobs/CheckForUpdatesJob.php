<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\SystemUpdateNotification;
use App\Services\UpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckForUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(UpdateService $service): void
    {
        if (! $service->isAgentHealthy()) {
            Log::warning('Update agent not reachable, skipping update check.');

            return;
        }

        try {
            $result = $service->checkForUpdates();
        } catch (\Throwable $e) {
            Log::warning('Failed to check for updates', ['error' => $e->getMessage()]);

            return;
        }

        $panelUpdate = ! empty($result['panel_update']);
        $mysqlUpdate = ! empty($result['mysql_update']);

        if (! $panelUpdate && ! $mysqlUpdate) {
            return;
        }

        $body = $this->buildNotificationBody($result);

        $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();

        $notification = new SystemUpdateNotification(
            level: 'info',
            title: __('System Update Available'),
            body: $body,
        );

        foreach ($admins as $admin) {
            $admin->notify($notification);
        }
    }

    private function buildNotificationBody(array $result): string
    {
        $parts = [];

        if (! empty($result['panel_update'])) {
            $latest = $result['panel_update']['latest_version'] ?? '?';
            $parts[] = __('Panel update: :latest available', ['latest' => $latest]);
        }

        if (! empty($result['mysql_update'])) {
            $current = $result['mysql_update']['current_version'] ?? '?';
            $target = $result['mysql_update']['target_version'] ?? '?';
            $type = ($result['mysql_update']['is_major'] ?? false) ? 'major' : 'minor';
            $parts[] = __("MySQL {$type}: :current → :target", ['current' => $current, 'target' => $target]);
        }

        return implode(' | ', $parts);
    }
}
