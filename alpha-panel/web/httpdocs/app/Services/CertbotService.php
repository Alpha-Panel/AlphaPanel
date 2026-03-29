<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CertbotService
{
    private string $letsEncryptBasePath;

    private string $selfSignedBasePath;

    public function __construct(
        private PortainerService $portainer,
    ) {
        $this->letsEncryptBasePath = config('panel.letsencrypt_base');
        $this->selfSignedBasePath = config('panel.letsencrypt_selfsigned_base');
    }

    /**
     * Request a wildcard certificate for a domain using certbot via Portainer.
     */
    public function requestCertificate(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $adminEmail = config('panel.certbot_email');
        $image = config('panel.portainer_certbot_image', 'certbot/dns-cloudflare:v5.4.0');
        $hostRoot = config('panel.compose_project_root_host');

        $certbotArgs = [
            'certbot certonly',
            '--non-interactive',
            '--agree-tos',
            '--email', escapeshellarg($adminEmail),
            '--cert-name', escapeshellarg($fqdn),
            '--dns-cloudflare',
            '--dns-cloudflare-credentials /secrets/cloudflare.ini',
            '--dns-cloudflare-propagation-seconds 90',
            '--key-type ecdsa',
            '--elliptic-curve secp384r1',
            '--logs-dir /etc/letsencrypt/logs',
            '-d', escapeshellarg($fqdn),
            '-d', escapeshellarg("*.{$fqdn}"),
        ];

        if (config('panel.certbot_staging')) {
            $certbotArgs[] = '--staging';
        }

        $certbotCommand = $this->buildCommandWithCleanup($fqdn, implode(' ', $certbotArgs));

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
        $image = config('panel.portainer_certbot_image', 'certbot/dns-cloudflare:v5.4.0');
        $hostRoot = config('panel.compose_project_root_host');

        $domains = [escapeshellarg($fqdn)];
        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $domains[] = escapeshellarg("www.{$fqdn}");
        }

        $certbotArgs = [
            'certbot certonly',
            '--non-interactive',
            '--agree-tos',
            '--email', escapeshellarg($adminEmail),
            '--cert-name', escapeshellarg($fqdn),
            '--webroot',
            '-w /var/www/acme-challenge',
            '--key-type ecdsa',
            '--elliptic-curve secp384r1',
            '--logs-dir /etc/letsencrypt/logs',
            ...array_map(fn ($d) => "-d {$d}", $domains),
        ];

        if (config('panel.certbot_staging')) {
            $certbotArgs[] = '--staging';
        }

        $certbotCommand = $this->buildCommandWithCleanup($fqdn, implode(' ', $certbotArgs));

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
        $image = config('panel.portainer_certbot_image', 'certbot/dns-cloudflare:v5.4.0');
        $hostRoot = config('panel.compose_project_root_host');

        $certbotArgs = [
            'certbot renew',
            '--non-interactive',
            '--cert-name', escapeshellarg($fqdn),
            '--dns-cloudflare',
            '--dns-cloudflare-credentials /secrets/cloudflare.ini',
            '--dns-cloudflare-propagation-seconds 90',
            '--logs-dir /etc/letsencrypt/logs',
        ];

        if (config('panel.certbot_staging')) {
            $certbotArgs[] = '--staging';
        }

        $certbotCommand = implode(' ', $certbotArgs);

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
     * Renew an existing certificate using webroot HTTP-01 challenge via Portainer.
     *
     * Uses `certbot certonly --force-renewal` instead of `certbot renew --cert-name`
     * because the latter requires a renewal config at /etc/letsencrypt/renewal/{domain}.conf
     * which doesn't exist for self-signed or manually created certificates.
     */
    public function renewCertificateWebroot(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $adminEmail = config('panel.certbot_email');
        $image = config('panel.portainer_certbot_image', 'certbot/dns-cloudflare:v5.4.0');
        $hostRoot = config('panel.compose_project_root_host');

        $domains = [escapeshellarg($fqdn)];
        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $domains[] = escapeshellarg("www.{$fqdn}");
        }

        $certbotArgs = [
            'certbot certonly',
            '--non-interactive',
            '--agree-tos',
            '--email', escapeshellarg($adminEmail),
            '--cert-name', escapeshellarg($fqdn),
            '--webroot',
            '-w /var/www/acme-challenge',
            '--key-type ecdsa',
            '--elliptic-curve secp384r1',
            '--force-renewal',
            '--logs-dir /etc/letsencrypt/logs',
            ...array_map(fn ($d) => "-d {$d}", $domains),
        ];

        if (config('panel.certbot_staging')) {
            $certbotArgs[] = '--staging';
        }

        $certbotCommand = implode(' ', $certbotArgs);

        Log::info("Renewing SSL certificate for {$fqdn} via webroot HTTP-01.");

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
                Log::error("Certbot webroot renew failed for {$fqdn} (exit code {$result->exitCode}): {$result->output}");

                return false;
            }

            Log::info("Certbot webroot renew succeeded for {$fqdn}: {$result->output}");

            return true;
        } catch (\Exception $e) {
            Log::error("Certbot webroot renew exception for {$fqdn}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate a self-signed certificate as fallback when certbot is unavailable.
     *
     * Stored in a separate /etc/letsencrypt/selfsigned/{domain}/ directory
     * so they never conflict with certbot's /etc/letsencrypt/live/{domain}/.
     */
    public function generateSelfSigned(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $certDir = "{$this->selfSignedBasePath}/{$fqdn}";

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
     * Checks certbot live path first, then self-signed fallback.
     */
    public function certFilesExist(Domain $domain): bool
    {
        return $this->resolveCertDir($domain) !== null;
    }

    /**
     * Resolve the directory containing the active certificate files for a domain.
     * Returns certbot live path if available, otherwise self-signed path.
     * Returns null if no certificate files exist.
     */
    public function resolveCertDir(Domain $domain): ?string
    {
        $fqdn = $domain->fqdn;

        // Prefer certbot-managed certs (live/)
        $liveDir = "{$this->letsEncryptBasePath}/{$fqdn}";
        if (file_exists("{$liveDir}/fullchain.pem") && file_exists("{$liveDir}/privkey.pem")) {
            return $liveDir;
        }

        // Fallback to self-signed certs (selfsigned/)
        $selfSignedDir = "{$this->selfSignedBasePath}/{$fqdn}";
        if (file_exists("{$selfSignedDir}/fullchain.pem") && file_exists("{$selfSignedDir}/privkey.pem")) {
            return $selfSignedDir;
        }

        return null;
    }

    /**
     * Check if a certbot renewal configuration exists for a domain.
     */
    public function certbotRenewalExists(Domain $domain): bool
    {
        $fqdn = $domain->fqdn;
        $renewalPath = dirname($this->letsEncryptBasePath)."/renewal/{$fqdn}.conf";

        return file_exists($renewalPath);
    }

    /**
     * Build a certbot command that first cleans up any conflicting cert files.
     *
     * Removes self-signed certs, any leftover certbot live/archive/renewal data,
     * and numbered variants (-0001, -0002) to ensure certbot creates the cert
     * at the exact expected path.
     */
    private function buildCommandWithCleanup(string $fqdn, string $certbotCommand): string
    {
        // Single-line command with semicolons — most robust across Docker/shell variants.
        // Explicitly list -0001 through -0003 instead of globs for maximum shell compatibility.
        $paths = collect(['', '-0001', '-0002', '-0003'])
            ->flatMap(fn ($suffix) => [
                "/etc/letsencrypt/live/{$fqdn}{$suffix}",
                "/etc/letsencrypt/archive/{$fqdn}{$suffix}",
                "/etc/letsencrypt/renewal/{$fqdn}{$suffix}.conf",
            ])
            ->push("/etc/letsencrypt/selfsigned/{$fqdn}")
            ->implode(' ');

        return "mkdir -p /etc/letsencrypt/logs; rm -rf {$paths} 2>/dev/null || true; {$certbotCommand}";
    }
}
