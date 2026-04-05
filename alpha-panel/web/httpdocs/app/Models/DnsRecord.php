<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps directly onto PowerDNS's native `records` table in the `powerdns` database.
 *
 * PowerDNS schema columns: id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth.
 * Legacy panel attributes (`dns_zone_id`, `priority`, `is_managed`) are exposed via
 * accessors/mutators so existing callers keep working without a wider rewrite.
 */
class DnsRecord extends Model
{
    protected $connection = 'powerdns';

    protected $table = 'records';

    public $timestamps = false;

    protected $fillable = [
        'domain_id',
        'name',
        'type',
        'content',
        'ttl',
        'prio',
        'disabled',
        'ordername',
        'auth',
        'dns_zone_id',
        'priority',
        'is_managed',
    ];

    protected $attributes = [
        'disabled' => 0,
        'auth' => 1,
        'prio' => 0,
    ];

    protected $appends = [
        'priority',
        'is_managed',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'prio' => 'integer',
            'disabled' => 'boolean',
            'auth' => 'boolean',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'domain_id');
    }

    public function getDnsZoneIdAttribute(): ?int
    {
        return isset($this->attributes['domain_id']) ? (int) $this->attributes['domain_id'] : null;
    }

    public function setDnsZoneIdAttribute(?int $value): void
    {
        $this->attributes['domain_id'] = $value;
    }

    public function getPriorityAttribute(): ?int
    {
        return isset($this->attributes['prio']) ? (int) $this->attributes['prio'] : null;
    }

    public function setPriorityAttribute(?int $value): void
    {
        $this->attributes['prio'] = $value ?? 0;
    }

    /**
     * PowerDNS has no equivalent column — the panel no longer tracks this flag.
     * Returned as false so the bulk-delete UI does not treat any record as locked.
     */
    public function getIsManagedAttribute(): bool
    {
        return false;
    }

    public function setIsManagedAttribute(mixed $value): void
    {
        // Intentional no-op: not stored in PowerDNS schema.
    }
}
