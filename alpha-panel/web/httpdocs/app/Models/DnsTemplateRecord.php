<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsTemplateRecord extends Model
{
    protected $fillable = [
        'dns_template_id',
        'type',
        'name',
        'content',
        'ttl',
        'priority',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DnsTemplate::class, 'dns_template_id');
    }
}
