<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainCronJobLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'domain_cron_job_id',
        'started_at',
        'finished_at',
        'duration_ms',
        'status',
        'output',
        'exit_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }

    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(DomainCronJob::class, 'domain_cron_job_id');
    }
}
