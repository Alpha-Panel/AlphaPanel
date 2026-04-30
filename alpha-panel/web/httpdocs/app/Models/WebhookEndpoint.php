<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'active',
        'last_triggered_at',
        'last_status_code',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function getPlainSecret(): string
    {
        return decrypt($this->secret);
    }
}
