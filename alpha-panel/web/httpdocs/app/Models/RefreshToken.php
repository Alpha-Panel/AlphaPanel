<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'alpha_center_webhook_url',
        'alpha_center_webhook_secret',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
