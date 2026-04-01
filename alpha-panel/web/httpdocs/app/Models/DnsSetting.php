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

    public static function instance(): self
    {
        return self::firstOrCreate([]);
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
        return array_values(array_filter([
            $this->ns1,
            $this->ns2,
            $this->ns3,
            $this->ns4,
        ]));
    }
}
