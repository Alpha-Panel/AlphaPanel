<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainIpRule extends Model
{
    protected $fillable = [
        'domain_id',
        'ip_address',
        'path',
        'note',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'path' => '',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
