<?php

use App\Enums\NotificationType;
use App\Jobs\BackupUploadJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\ExecuteDomainCronJob;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\DomainCronJob;
use App\Models\User;
use App\Notifications\DomainNotification;
use Cron\CronExpression;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Backup
|--------------------------------------------------------------------------
|
| Reads schedule frequency and time from backup_settings table.
| Frequency and time are configurable from the Backups panel page.
|
*/

try {
    $backupSettings = BackupSetting::instance();

    if ($backupSettings->is_enabled && $backupSettings->isConnected() && $backupSettings->drive_folder_id) {
        Schedule::call(function () {
            $run = BackupRun::create([
                'type' => 'scheduled',
                'status' => 'running',
                'started_at' => now(),
            ]);
            BackupUploadJob::dispatch(backupRunId: $run->id);
        })
            ->cron($backupSettings->getCronExpression())
            ->name('backup:scheduled')
            ->withoutOverlapping(120)
            ->onFailure(fn () => Log::error('Scheduled backup failed'));
    }
} catch (Throwable) {
    // Table may not exist yet (before migration)
}

/*
|--------------------------------------------------------------------------
| Domain Cron Job Dispatcher
|--------------------------------------------------------------------------
|
| Checks every minute for enabled cron jobs that are due and dispatches
| them as queued jobs for execution in the FrankenPHP container.
|
*/

Schedule::call(function (): void {
    try {
        $cronJobs = DomainCronJob::where('enabled', true)
            ->with('domain')
            ->get();

        // Pin evaluation to the app timezone so container TZ drift can't shift fires.
        $appTz = (string) config('app.timezone', 'UTC');
        $now = now($appTz);

        foreach ($cronJobs as $cronJob) {
            try {
                $cron = new CronExpression($cronJob->schedule);
                if ($cron->isDue($now, $appTz)) {
                    dispatch(new ExecuteDomainCronJob($cronJob));
                }
            } catch (Throwable $e) {
                Log::warning("Invalid cron expression for job #{$cronJob->id}: {$e->getMessage()}");
            }
        }
    } catch (Throwable) {
        // Table may not exist yet (before migration)
    }
})->everyMinute()->name('domain-cron-dispatcher');

/*
|--------------------------------------------------------------------------
| Weekly Security Audit
|--------------------------------------------------------------------------
|
| Runs composer audit and npm audit weekly to detect known vulnerabilities.
| Results are logged via Log::warning() when issues are found.
|
*/

/*
|--------------------------------------------------------------------------
| Daily Update Check
|--------------------------------------------------------------------------
|
| Checks GitHub releases and Docker Hub for available updates.
| Notifies admin users when new versions are found.
|
*/

try {
    if (config('panel.update.auto_check')) {
        Schedule::job(new CheckForUpdatesJob)
            ->daily()
            ->name('check-for-updates')
            ->withoutOverlapping();
    }
} catch (Throwable) {
    // Config may not be available yet
}

Schedule::command('panel:security-audit')
    ->weeklyOn(1, '03:00')
    ->name('security-audit')
    ->withoutOverlapping(30)
    ->onFailure(fn () => Log::warning('Security audit found vulnerabilities or failed to run'));

/*
|--------------------------------------------------------------------------
| SSL Certificate Auto-Renewal
|--------------------------------------------------------------------------
|
| Checks twice daily for Let's Encrypt certificates expiring within
| the configured renewal window and dispatches renewal jobs.
|
*/

Schedule::command('ssl:renew')
    ->twiceDaily(3, 15)
    ->name('ssl:renew')
    ->withoutOverlapping(30)
    ->onFailure(function (): void {
        Log::error('SSL renewal check failed — notifying admins.');
        try {
            $admins = User::query()->where('admin', true)->get();
            foreach ($admins as $admin) {
                $admin->notify(new DomainNotification(
                    level: 'error',
                    title: __('SSL Renewal Check Failed'),
                    body: __('Scheduled ssl:renew command failed. Check application logs.'),
                    notificationType: NotificationType::SslRenewal,
                ));
            }
        } catch (Throwable $e) {
            Log::error("SSL renewal failure notification could not be sent: {$e->getMessage()}");
        }
    });

/*
|--------------------------------------------------------------------------
| Telescope Pruning
|--------------------------------------------------------------------------
|
| Prunes old Telescope entries hourly to prevent database bloat.
| Retention period is configurable via TELESCOPE_PRUNE_HOURS (default: 72 = 3 days).
|
*/

Schedule::command('telescope:prune --hours='.config('telescope.prune_hours', 72))
    ->hourly()
    ->name('telescope:prune')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('Telescope prune failed'));

/*
|--------------------------------------------------------------------------
| System Health Monitoring
|--------------------------------------------------------------------------
|
| Checks CPU, RAM, and disk usage against configured thresholds.
| Sends notifications when warning or critical levels are exceeded
| and when metrics recover to normal.
|
*/

Schedule::command('system:check-health')
    ->everyFiveMinutes()
    ->name('system:check-health')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('System health check failed'));
