<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplyRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'status',
        'progress_percent',
        'message',
        'started_at',
        'finished_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
