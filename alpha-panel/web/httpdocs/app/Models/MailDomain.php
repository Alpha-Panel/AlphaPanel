<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'mail_domain',
        'mailcow_domain_id',
        'is_active',
        'max_mailboxes',
        'quota_mb',
        'relay_host',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_mailboxes' => 'integer',
            'quota_mb' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(MailMailbox::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MailAlias::class);
    }
}
