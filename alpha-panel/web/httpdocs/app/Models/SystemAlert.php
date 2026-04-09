<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SystemAlert extends Model
{
    protected $fillable = [
        'metric',
        'level',
        'value',
        'threshold',
        'resolved_at',
        'resolved_value',
        'notified_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'resolved_value' => 'decimal:2',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeForMetric(Builder $query, string $metric): Builder
    {
        return $query->where('metric', $metric);
    }
}
