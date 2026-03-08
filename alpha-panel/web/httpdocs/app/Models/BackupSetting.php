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
}
