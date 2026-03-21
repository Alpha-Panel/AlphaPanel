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

        $panelUpdate = $result['panel']['update_available'] ?? false;
        $mysqlMajor = $result['mysql']['major_upgrade_available'] ?? false;
        $mysqlMinor = $result['mysql']['minor_update_available'] ?? false;

        if (! $panelUpdate && ! $mysqlMajor && ! $mysqlMinor) {
            return;
        }

        $body = $this->buildNotificationBody($result, $panelUpdate, $mysqlMajor, $mysqlMinor);

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

    private function buildNotificationBody(array $result, bool $panelUpdate, bool $mysqlMajor, bool $mysqlMinor): string
    {
        $parts = [];

        if ($panelUpdate) {
            $current = $result['panel']['current'] ?? '?';
            $latest = $result['panel']['latest'] ?? '?';
            $parts[] = __('Panel: :current → :latest', ['current' => $current, 'latest' => $latest]);
        }

        if ($mysqlMajor) {
            $current = $result['mysql']['current'] ?? '?';
            $latest = $result['mysql']['latest_major'] ?? '?';
            $parts[] = __('MySQL major: :current → :latest', ['current' => $current, 'latest' => $latest]);
        } elseif ($mysqlMinor) {
            $current = $result['mysql']['current'] ?? '?';
            $latest = $result['mysql']['latest_minor'] ?? '?';
            $parts[] = __('MySQL minor: :current → :latest', ['current' => $current, 'latest' => $latest]);
        }

        return implode(' | ', $parts);
    }
}
