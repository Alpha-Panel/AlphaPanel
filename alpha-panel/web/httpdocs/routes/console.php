<?php

use App\Jobs\BackupUploadJob;
use App\Models\BackupSetting;
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
