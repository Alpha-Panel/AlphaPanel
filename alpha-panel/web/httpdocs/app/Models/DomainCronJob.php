<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DomainCronJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'command',
        'schedule',
        'description',
        'enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DomainCronJobLog::class);
    }

    public function latestLog(): HasOne
    {
        return $this->hasOne(DomainCronJobLog::class)->latestOfMany();
    }
}
