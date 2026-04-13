<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailMailbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'local_part',
        'full_address',
        'display_name',
        'quota_mb',
        'is_active',
        'last_login_at',
        'messages_count',
        'quota_used_mb',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'quota_mb' => 'integer',
            'quota_used_mb' => 'integer',
            'messages_count' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }

    public function forwardingRules(): HasMany
    {
        return $this->hasMany(MailForwardingRule::class);
    }

    /** @return Attribute<string|null, never> */
    protected function domainName(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->full_address) {
                return null;
            }

            $parts = explode('@', $this->full_address);

            return $parts[1] ?? null;
        });
    }
}
