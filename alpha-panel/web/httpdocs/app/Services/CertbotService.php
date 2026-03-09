<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CertbotService
{
    private string $letsEncryptBasePath;

    public function __construct(
        private PortainerService $portainer,
    ) {
        $this->letsEncryptBasePath = config('panel.letsencrypt_base');
    }

    /**
     * Request a wildcard certificate for a domain using certbot via Portainer.
     *
     * Replicates the compose command:
     * docker compose run --rm --entrypoint /bin/sh certbot-init -lc \
     *   'certbot certonly --non-interactive --agree-tos --email "$ADMIN_EMAIL" \
     *    --dns-cloudflare --dns-cloudflare-credentials /secrets/cloudflare.ini \
     *    --dns-cloudflare-propagation-seconds 90 --key-type ecdsa --elliptic-curve secp384r1 \
     *    -d example.com -d "*.example.com"'
     */
    public function requestCertificate(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $adminEmail = config('panel.certbot_email');
        $image = config('panel.portainer_certbot_image', 'alphapanel-docker-certbot-init:latest');
        $hostRoot = config('panel.compose_project_root_host');

        $certbotCommand = implode(' ', [
            'certbot certonly',
            '--non-interactive',
            '--agree-tos',
            '--email', escapeshellarg($adminEmail),
            '--dns-cloudflare',
            '--dns-cloudflare-credentials /secrets/cloudflare.ini',
            '--dns-cloudflare-propagation-seconds 90',
            '--key-type ecdsa',
            '--elliptic-curve secp384r1',
            '-d', escapeshellarg($fqdn),
            '-d', escapeshellarg("*.{$fqdn}"),
        ]);

        Log::info("Requesting SSL certificate for {$fqdn} via Portainer.");

        try {
            $result = $this->portainer->createAndRunContainer([
                'Image' => $image,
                'Entrypoint' => ['/bin/sh'],
                'Cmd' => ['-lc', $certbotCommand],
                'Env' => [
                    'TZ=Europe/Istanbul',
                    "ADMIN_EMAIL={$adminEmail}",
                    'CERTBOT_DOMAIN_ROOT=/etc/frankenphp/sites-enabled',
                    'CERTBOT_UPDATE_CADDYFILES=1',
                    'CERTBOT_USE_STAGING=0',
                ],
                'HostConfig' => [
                    'Binds' => [
                        "{$hostRoot}/letsencrypt:/etc/letsencrypt",
                        "{$hostRoot}/secrets/cloudflare.ini:/secrets/cloudflare.ini:ro",
                        "{$hostRoot}/frankenphp/sites-enabled:/etc/frankenphp/sites-enabled",
                        "{$hostRoot}/scripts/certbot:/opt/certbot-scripts:ro",
                    ],
                ],
            ], timeout: 300);

            if (! $result->isSuccessful()) {
                Log::error("Certbot failed for {$fqdn} (exit code {$result->exitCode}): {$result->output}");

                return false;
            }

            Log::info("Certbot succeeded for {$fqdn}: {$result->output}");

            return true;
        } catch (\Exception $e) {
            Log::error("Certbot exception for {$fqdn}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Request a certificate using webroot HTTP-01 challenge via Portainer.
     * HTTP-01 cannot issue wildcard certs — only single domain (+ optional www).
     */
    public function requestCertificateWebroot(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $adminEmail = config('panel.certbot_email');
        $image = config('panel.portainer_certbot_image', 'alphapanel-docker-certbot-init:latest');
        $hostRoot = config('panel.compose_project_root_host');

        $domains = [escapeshellarg($fqdn)];
        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $domains[] = escapeshellarg("www.{$fqdn}");
        }

        $certbotCommand = implode(' ', [
            'certbot certonly',
            '--non-interactive',
            '--agree-tos',
            '--email', escapeshellarg($adminEmail),
            '--webroot',
            '-w /var/www/acme-challenge',
            '--key-type ecdsa',
            '--elliptic-curve secp384r1',
            ...array_map(fn ($d) => "-d {$d}", $domains),
        ]);

        Log::info("Requesting SSL certificate for {$fqdn} via webroot HTTP-01.");

        try {
            $result = $this->portainer->createAndRunContainer([
                'Image' => $image,
                'Entrypoint' => ['/bin/sh'],
                'Cmd' => ['-lc', $certbotCommand],
                'Env' => [
                    'TZ=Europe/Istanbul',
                    "ADMIN_EMAIL={$adminEmail}",
                ],
                'HostConfig' => [
                    'Binds' => [
                        "{$hostRoot}/letsencrypt:/etc/letsencrypt",
                        "{$hostRoot}/acme-challenge:/var/www/acme-challenge",
                    ],
                ],
            ], timeout: 120);

            if (! $result->isSuccessful()) {
                Log::error("Certbot webroot failed for {$fqdn} (exit code {$result->exitCode}): {$result->output}");

                return false;
            }

            Log::info("Certbot webroot succeeded for {$fqdn}: {$result->output}");

            return true;
        } catch (\Exception $e) {
            Log::error("Certbot webroot exception for {$fqdn}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Renew an existing certificate using certbot renew via Portainer.
     */
    public function renewCertificate(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $image = config('panel.portainer_certbot_image', 'alphapanel-docker-certbot-init:latest');
        $hostRoot = config('panel.compose_project_root_host');

        $certbotCommand = implode(' ', [
            'certbot renew',
            '--non-interactive',
            '--cert-name', escapeshellarg($fqdn),
            '--dns-cloudflare',
            '--dns-cloudflare-credentials /secrets/cloudflare.ini',
            '--dns-cloudflare-propagation-seconds 90',
        ]);

        Log::info("Renewing SSL certificate for {$fqdn} via Portainer.");

        try {
            $result = $this->portainer->createAndRunContainer([
                'Image' => $image,
                'Entrypoint' => ['/bin/sh'],
                'Cmd' => ['-lc', $certbotCommand],
                'Env' => [
                    'TZ=Europe/Istanbul',
                ],
                'HostConfig' => [
                    'Binds' => [
                        "{$hostRoot}/letsencrypt:/etc/letsencrypt",
                        "{$hostRoot}/secrets/cloudflare.ini:/secrets/cloudflare.ini:ro",
                    ],
                ],
            ], timeout: 300);

            if (! $result->isSuccessful()) {
                Log::error("Certbot renew failed for {$fqdn} (exit code {$result->exitCode}): {$result->output}");

                return false;
            }

            Log::info("Certbot renew succeeded for {$fqdn}: {$result->output}");

            return true;
        } catch (\Exception $e) {
            Log::error("Certbot renew exception for {$fqdn}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate a self-signed certificate as fallback when certbot is unavailable.
     * Files are placed in the same letsencrypt path so existing config logic works seamlessly.
     */
    public function generateSelfSigned(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $certDir = "{$this->letsEncryptBasePath}/{$fqdn}";

        Log::info("Generating self-signed certificate for {$fqdn}.");

        try {
            if (! File::isDirectory($certDir)) {
                File::makeDirectory($certDir, 0755, true);
            }

            $keyPath = "{$certDir}/privkey.pem";
            $certPath = "{$certDir}/fullchain.pem";

            $result = Process::timeout(30)->run(implode(' ', [
                'openssl req -x509 -nodes',
                '-days 365',
                '-newkey rsa:2048',
                '-keyout', escapeshellarg($keyPath),
                '-out', escapeshellarg($certPath),
                '-subj', escapeshellarg("/CN={$fqdn}"),
                '-addext', escapeshellarg("subjectAltName=DNS:{$fqdn},DNS:*.{$fqdn}"),
            ]));

            if (! $result->successful()) {
                Log::error("Self-signed cert generation failed for {$fqdn}: {$result->errorOutput()}");

                return false;
            }

            Log::info("Self-signed certificate generated for {$fqdn}.");

            return true;
        } catch (\Exception $e) {
            Log::error("Self-signed cert exception for {$fqdn}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Check if certificate files exist for a domain.
     */
    public function certFilesExist(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $certPath = "{$this->letsEncryptBasePath}/{$fqdn}/fullchain.pem";
        $keyPath = "{$this->letsEncryptBasePath}/{$fqdn}/privkey.pem";

        return file_exists($certPath) && file_exists($keyPath);
    }
}
