<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZone extends Model
{
    protected $fillable = [
        'domain_id',
        'zone_name',
        'serial',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'serial' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    /**
     * Increment the SOA serial number using YYYYMMDDNN format.
     *
     * If the current serial already starts with today's date,
     * increment the revision counter (NN). Otherwise, start fresh.
     */
    public function incrementSerial(): void
    {
        $today = (int) now()->format('Ymd');
        $todayBase = $today * 100;
        $currentSerial = (int) $this->serial;

        if ($currentSerial >= $todayBase && $currentSerial < $todayBase + 99) {
            $this->serial = $currentSerial + 1;
        } else {
            $this->serial = $todayBase + 1;
        }

        $this->save();
    }

    /**
     * Generate a fresh serial for today.
     */
    public static function generateSerial(): int
    {
        return (int) (now()->format('Ymd').'01');
    }
}
