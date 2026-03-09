<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupSetting extends Model
{
    protected $fillable = [
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'drive_folder_id',
        'drive_folder_name',
        'connected_email',
        'is_enabled',
        'backup_retention_days',
        'backup_schedule',
        'backup_time',
        'last_backup_at',
    ];

    protected function casts(): array
    {
        return [
            'google_access_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
            'is_enabled' => 'boolean',
            'backup_retention_days' => 'integer',
            'last_backup_at' => 'datetime',
        ];
    }

    public static function instance(): self
    {
        return self::firstOrCreate([]);
    }

    public function isConnected(): bool
    {
        return $this->google_refresh_token !== null
            && $this->google_refresh_token !== '';
    }

    /**
     * Convert schedule + time settings to a cron expression.
     *
     * Supported schedules: daily, every_2_days, every_3_days,
     * weekly, every_2_weeks, monthly
     */
    public function getCronExpression(): string
    {
        [$hour, $minute] = explode(':', $this->backup_time ?? '03:00');

        return match ($this->backup_schedule ?? 'daily') {
            'daily' => "{$minute} {$hour} * * *",
            'every_2_days' => "{$minute} {$hour} */2 * *",
            'every_3_days' => "{$minute} {$hour} */3 * *",
            'weekly' => "{$minute} {$hour} * * 1",         // her pazartesi
            'every_2_weeks' => "{$minute} {$hour} 1,15 * *", // ayın 1'i ve 15'i
            'monthly' => "{$minute} {$hour} 1 * *",
            default => "{$minute} {$hour} * * *",
        };
    }
}
