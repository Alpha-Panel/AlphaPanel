<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FtpBanWhitelist extends Model
{
    protected $table = 'ftp_ban_whitelist';

    /** @var list<string> */
    protected $fillable = [
        'ip_address',
        'note',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check whether the given IP address is on the whitelist.
     */
    public static function isWhitelisted(string $ip): bool
    {
        return static::where('ip_address', $ip)->exists();
    }
}
