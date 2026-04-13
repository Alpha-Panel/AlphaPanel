<?php

use App\Jobs\BackupUploadJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\ExecuteDomainCronJob;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\DomainCronJob;
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

        $now = now();

        foreach ($cronJobs as $cronJob) {
            try {
                $cron = new CronExpression($cronJob->schedule);
                if ($cron->isDue($now)) {
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
    ->onFailure(fn () => Log::error('SSL renewal check failed'));

/*
|--------------------------------------------------------------------------
| Telescope Pruning
|--------------------------------------------------------------------------
|
| Prunes old Telescope entries daily to prevent database bloat.
| Retention period is configurable via TELESCOPE_PRUNE_HOURS (default: 48).
|
*/

Schedule::command('telescope:prune --hours='.config('telescope.prune_hours', 48))
    ->daily()
    ->name('telescope:prune')
    ->withoutOverlapping();

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
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Mail Sync (Mailcow)
|--------------------------------------------------------------------------
|
| Synchronizes mailbox statistics (quota usage, message count, last login)
| from the Mailcow API into the local database every 15 minutes.
| Only runs when Mailcow integration is enabled.
|
*/

try {
    if (config('panel.mailcow.enabled')) {
        Schedule::command('panel:mail:sync')
            ->everyFifteenMinutes()
            ->name('panel:mail:sync')
            ->withoutOverlapping(30)
            ->onFailure(fn () => Log::error('Mail sync with Mailcow failed'));
    }
} catch (Throwable) {
    // Config may not be available yet
}
