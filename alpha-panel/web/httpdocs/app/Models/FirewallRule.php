<?php

namespace App\Models;

use App\Observers\FirewallRuleObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallRule extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'chain',
        'action',
        'protocol',
        'sources',
        'ports',
        'comment',
        'position',
        'enabled',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'ports' => 'array',
            'position' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    // ── Scopes ───────────────────────────────────────────────

    /** @param Builder<self> $query */
    public function scopeInput(Builder $query): Builder
    {
        return $query->where('chain', 'INPUT');
    }

    /** @param Builder<self> $query */
    public function scopeOutput(Builder $query): Builder
    {
        return $query->where('chain', 'OUTPUT');
    }

    /** @param Builder<self> $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position');
    }

    // ── Relations ────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Observer ─────────────────────────────────────────────

    protected static function booted(): void
    {
        static::observe(FirewallRuleObserver::class);
    }
}
