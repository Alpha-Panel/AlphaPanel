<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcmeSetting extends Model
{
    protected $fillable = [
        'email',
        'staging',
        'server_url',
        'staging_server_url',
        'key_type',
        'key_length',
        'dns_propagation_wait',
        'local_dns_wait',
        'poll_timeout',
        'webroot_path',
        'auto_renew_days',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'staging' => 'boolean',
            'dns_propagation_wait' => 'integer',
            'local_dns_wait' => 'integer',
            'poll_timeout' => 'integer',
            'auto_renew_days' => 'integer',
        ];
    }

    public static function instance(): self
    {
        return self::firstOrCreate([]);
    }

    /**
     * Get the active ACME server URL based on staging mode.
     */
    public function getActiveServerUrl(): string
    {
        return $this->staging
            ? ($this->staging_server_url ?: 'https://acme-staging-v02.api.letsencrypt.org/directory')
            : ($this->server_url ?: 'https://acme-v02.api.letsencrypt.org/directory');
    }

    /**
     * Get key parameters for openssl operations.
     *
     * @return array{type: string, length: string}
     */
    public function getKeyParams(): array
    {
        return [
            'type' => $this->key_type ?: 'EC',
            'length' => $this->key_length ?: 'P-384',
        ];
    }
}
