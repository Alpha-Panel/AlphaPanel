<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Maps directly onto PowerDNS's native `domains` table in the `powerdns` database.
 *
 * PowerDNS schema columns: id, name, master, last_check, type, notified_serial, account, catalog.
 * Legacy panel attributes (`zone_name`, `status`, `serial`, `domain_id`) are exposed via
 * accessors/mutators so existing callers keep working without a wider rewrite.
 */
class DnsZone extends Model
{
    protected $connection = 'powerdns';

    protected $table = 'domains';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'type',
        'master',
        'account',
        'zone_name',
    ];

    protected $attributes = [
        'type' => 'NATIVE',
    ];

    protected $appends = [
        'zone_name',
        'status',
        'serial',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class, 'domain_id');
    }

    public function getZoneNameAttribute(): ?string
    {
        return $this->attributes['name'] ?? null;
    }

    public function setZoneNameAttribute(?string $value): void
    {
        $this->attributes['name'] = $value;
    }

    public function getStatusAttribute(): string
    {
        return 'active';
    }

    /**
     * Read the SOA serial (3rd whitespace-separated token of the SOA record's content).
     */
    public function getSerialAttribute(): int
    {
        $soa = $this->records()->where('type', 'SOA')->first();

        if (! $soa) {
            return 0;
        }

        $parts = preg_split('/\s+/', trim((string) $soa->content)) ?: [];

        return (int) ($parts[2] ?? 0);
    }

    /**
     * Increment the SOA serial number using YYYYMMDDNN format.
     *
     * Writes the new serial into the 3rd token of the SOA record's content.
     */
    public function incrementSerial(): void
    {
        $soa = $this->records()->where('type', 'SOA')->first();

        if (! $soa) {
            return;
        }

        $parts = preg_split('/\s+/', trim((string) $soa->content)) ?: [];

        if (count($parts) < 7) {
            return;
        }

        $today = (int) now()->format('Ymd');
        $todayBase = $today * 100;
        $current = (int) ($parts[2] ?? 0);

        $parts[2] = (string) (
            ($current >= $todayBase && $current < $todayBase + 99)
                ? $current + 1
                : $todayBase + 1
        );

        $soa->content = implode(' ', $parts);
        $soa->save();
    }

    /**
     * Generate a fresh serial for today (YYYYMMDD01).
     */
    public static function generateSerial(): int
    {
        return (int) (now()->format('Ymd').'01');
    }
}
