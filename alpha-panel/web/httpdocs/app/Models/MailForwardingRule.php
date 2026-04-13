<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailForwardingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'destination',
        'keep_copy',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'keep_copy' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function mailMailbox(): BelongsTo
    {
        return $this->belongsTo(MailMailbox::class);
    }
}
