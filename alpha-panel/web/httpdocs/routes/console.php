<?php

use App\Jobs\BackupUploadJob;
use App\Jobs\ExecuteDomainCronJob;
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
        Schedule::job(new BackupUploadJob(type: 'scheduled'))
            ->cron($backupSettings->getCronExpression())
            ->name('backup:scheduled')
            ->withoutOverlapping(120)
            ->onFailure(fn () => Log::error('Scheduled backup failed'));
    }
} catch (\Throwable) {
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
            } catch (\Throwable $e) {
                Log::warning("Invalid cron expression for job #{$cronJob->id}: {$e->getMessage()}");
            }
        }
    } catch (\Throwable) {
        // Table may not exist yet (before migration)
    }
})->everyMinute()->name('domain-cron-dispatcher');
