<?php

namespace App\Models;

use App\Enums\UpdateStatus;
use App\Enums\UpdateType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'from_version',
        'to_version',
        'progress_percent',
        'message',
        'log',
        'pre_flight_snapshot',
        'error_message',
        'rollback_info',
        'triggered_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => UpdateType::class,
            'status' => UpdateStatus::class,
            'progress_percent' => 'integer',
            'pre_flight_snapshot' => 'array',
            'rollback_info' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
