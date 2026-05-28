<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Models\AcmeSetting;
use Illuminate\Support\Facades\Log;
use Rogierw\RwAcme\Api;
use Rogierw\RwAcme\DTO\AccountData;

/**
 * Shared ACME client / account / settings factory.
 *
 * Holds the lazily-built rw-acme-client Api instance and the account
 * adapter, so the challenge runners (HTTP-01, DNS-01) and the order
 * finalizer can share a single configured client per request.
 *
 * Octane workers reuse this service across requests; resetClient() must
 * be called at the entry of any public ACME operation so a settings
 * change (staging toggle, server URL, email) takes effect immediately
 * instead of leaving stale clients bound to the previous config.
 */
class AcmeClientFactory
{
    private ?Api $client = null;

    private ?DatabaseAcmeAccount $accountAdapter = null;

    /**
     * Null out cached client + adapter so the next getClient() call rebuilds from current settings.
     */
    public function resetClient(): void
    {
        $this->client = null;
        $this->accountAdapter = null;
    }

    /**
     * Get or create the ACME API client.
     */
    public function getClient(): Api
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $settings = $this->getSettings();
        $serverUrl = $settings['staging']
            ? $settings['staging_server_url']
            : $settings['server_url'];

        // The rw-acme-client library appends '/directory' automatically,
        // so baseUrl must be the host without the trailing /directory path.
        $baseUrl = rtrim($serverUrl, '/');
        if (str_ends_with($baseUrl, '/directory')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/directory'));
        }

        $this->accountAdapter = new DatabaseAcmeAccount($serverUrl);

        $this->client = new Api(
            staging: $settings['staging'],
            localAccount: $this->accountAdapter,
            baseUrl: $baseUrl,
        );

        // Attach Laravel's logger so rw-acme-client's internal ACME flow logs
        // (challenge status checks, validation attempts, HTTP responses) land
        // in laravel.log. Without this, HTTP-01 failures are opaque black boxes.
        $this->client->setLogger(Log::channel(config('logging.default')));

        return $this->client;
    }

    /**
     * Ensure ACME account exists, create if needed.
     */
    public function ensureAccount(): AccountData
    {
        $client = $this->getClient();
        $settings = $this->getSettings();

        if ($client->account()->exists()) {
            return $client->account()->get();
        }

        Log::info('Creating new ACME account for '.($settings['staging'] ? 'staging' : 'production').'.');
        $accountData = $client->account()->create();

        // Store the account URL
        $this->accountAdapter?->storeAccountUrl($accountData->url, $settings['email']);

        return $accountData;
    }

    /**
     * Get ACME settings from the database (with fallback to config).
     *
     * @return array{email: string, staging: bool, server_url: string, staging_server_url: string, key_type: string, key_length: string, dns_propagation_wait: int, local_dns_wait: int, poll_timeout: int, webroot_path: string, auto_renew_days: int}
     */
    public function getSettings(): array
    {
        try {
            $settings = AcmeSetting::instance();

            return [
                'email' => $settings->email ?: config('panel.certbot_email', ''),
                'staging' => $settings->staging,
                'server_url' => $settings->server_url ?: 'https://acme-v02.api.letsencrypt.org/directory',
                'staging_server_url' => $settings->staging_server_url ?: 'https://acme-staging-v02.api.letsencrypt.org/directory',
                'key_type' => $settings->key_type ?: 'EC',
                'key_length' => $settings->key_length ?: 'P-384',
                'dns_propagation_wait' => $settings->dns_propagation_wait ?: 60,
                'local_dns_wait' => $settings->local_dns_wait ?: 5,
                'poll_timeout' => $settings->poll_timeout ?: 300,
                'webroot_path' => $settings->webroot_path ?: '/var/www/acme-challenge',
                'auto_renew_days' => $settings->auto_renew_days ?: 30,
            ];
        } catch (\Throwable) {
            return [
                'email' => config('panel.certbot_email', ''),
                'staging' => config('panel.certbot_staging', false),
                'server_url' => 'https://acme-v02.api.letsencrypt.org/directory',
                'staging_server_url' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
                'key_type' => 'EC',
                'key_length' => 'P-384',
                'dns_propagation_wait' => 60,
                'local_dns_wait' => 5,
                'poll_timeout' => 300,
                'webroot_path' => '/var/www/acme-challenge',
                'auto_renew_days' => 30,
            ];
        }
    }
}
