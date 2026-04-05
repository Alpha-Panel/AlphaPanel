<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Normalize webroot_path on write so the value stored in the database
     * never contains the `.well-known/acme-challenge` subpath. The ACME
     * writer always appends that suffix itself, and Caddy's file_server
     * roots at the base path (hard-coded to `/var/www/acme-challenge`).
     * Storing the suffix caused double-nested paths and 404s during
     * HTTP-01 validation.
     */
    protected function webrootPath(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): string {
                $value = trim((string) $value);

                if ($value === '') {
                    return '/var/www/acme-challenge';
                }

                $value = rtrim($value, '/');
                $value = preg_replace('#/\.well-known/acme-challenge/?$#', '', $value);
                $value = rtrim((string) $value, '/');

                return $value === '' ? '/var/www/acme-challenge' : $value;
            },
        );
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
