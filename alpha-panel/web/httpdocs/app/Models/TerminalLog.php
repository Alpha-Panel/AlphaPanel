<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerminalLog extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'session_type',
        'container_name',
        'command',
        'output',
        'ip_address',
        'port',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
