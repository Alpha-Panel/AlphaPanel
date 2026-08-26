<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsSetting extends Model
{
    protected $fillable = [
        'ns1',
        'ns2',
        'ns3',
        'ns4',
        'default_ip',
        'soa_admin_email',
        'soa_refresh',
        'soa_retry',
        'soa_expire',
        'soa_minimum_ttl',
        'default_ttl',
        'default_template_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'soa_refresh' => 'integer',
            'soa_retry' => 'integer',
            'soa_expire' => 'integer',
            'soa_minimum_ttl' => 'integer',
            'default_ttl' => 'integer',
        ];
    }

    /**
     * The singleton settings row, seeded from config/dns.php on first use.
     *
     * Without the seed a fresh install starts on the migration's example.com
     * column defaults, and every zone LocalDnsService creates would delegate to
     * nameservers the operator does not own.
     */
    public static function instance(): self
    {
        return self::firstOrCreate([], [
            'ns1' => config('dns.ns1'),
            'ns2' => config('dns.ns2'),
            'soa_admin_email' => config('dns.soa_admin_email'),
            'default_ip' => config('dns.default_ip'),
        ]);
    }

    public function defaultTemplate(): BelongsTo
    {
        return $this->belongsTo(DnsTemplate::class, 'default_template_id');
    }

    /**
     * Get all configured nameservers as an array.
     *
     * @return list<string>
     */
    public function getNameservers(): array
    {
        $nameservers = array_values(array_filter([
            $this->ns1,
            $this->ns2,
            $this->ns3,
            $this->ns4,
        ]));

        // A zone with no NS records is broken DNS, so never return an empty list —
        // fall back to the configured defaults if every field was cleared.
        if ($nameservers === []) {
            $nameservers = array_values(array_filter([
                config('dns.ns1'),
                config('dns.ns2'),
            ]));
        }

        return $nameservers;
    }
}
