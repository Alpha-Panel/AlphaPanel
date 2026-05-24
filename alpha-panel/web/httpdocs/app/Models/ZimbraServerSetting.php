<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class ZimbraServerSetting extends Model
{
    protected $table = 'zimbra_servers';

    protected $fillable = [
        'enabled',
        'admin_url',
        'admin_user',
        'admin_password_encrypted',
        'default_mx_host',
        'default_mx_priority',
        'default_spf_include',
        'verify_tls',
        'timeout_seconds',
        'last_health_check_at',
        'last_health_status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'verify_tls' => 'boolean',
            'default_mx_priority' => 'integer',
            'timeout_seconds' => 'integer',
            'last_health_check_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return Cache::remember('zimbra.server.current', 60, fn () => self::query()->first());
    }

    public static function flushCurrent(): void
    {
        Cache::forget('zimbra.server.current');
    }

    public function getAdminPasswordAttribute(): ?string
    {
        if (! $this->admin_password_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->admin_password_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setAdminPasswordAttribute(?string $value): void
    {
        $this->attributes['admin_password_encrypted'] = $value !== null && $value !== ''
            ? Crypt::encryptString($value)
            : null;
    }
}
