<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Models\Domain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Rogierw\RwAcme\Enums\AuthorizationChallengeEnum;

/**
 * Runs the HTTP-01 webroot ACME challenge flow.
 *
 * Writes per-identifier challenge files under the configured webroot,
 * triggers LE validation, polls until all challenges pass, then delegates
 * to OrderFinalizer to produce the certificate.
 *
 * HTTP-01 cannot issue wildcard certificates by ACME spec — callers must
 * not pass `*.{fqdn}` here.
 */
class Http01ChallengeRunner
{
    public function __construct(
        private AcmeClientFactory $clientFactory,
        private OrderFinalizer $orderFinalizer,
    ) {}

    /**
     * Request a certificate using HTTP-01 webroot validation.
     */
    public function run(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $fqdn = $domain->fqdn;
        $domains = [$fqdn];

        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $domains[] = "www.{$fqdn}";
        }

        Log::info("Requesting HTTP-01 (webroot) certificate for {$fqdn}.", [
            'identifiers' => $domains,
            'enable_www_redirect' => $domain->enable_www_redirect,
        ]);

        $settings = $this->clientFactory->getSettings();
        $webrootPath = $this->normalizeWebrootPath($settings['webroot_path']);

        try {
            $client = $this->clientFactory->getClient();
            $accountData = $this->clientFactory->ensureAccount();

            if ($onProgress) {
                $onProgress(20, __('Creating certificate order...'));
            }

            $order = $client->order()->new($accountData, $domains);

            Log::info("ACME order created for {$fqdn}.", [
                'order_id' => $order->id ?? null,
                'status' => $order->status ?? null,
                'validation_urls' => $order->domainValidationUrls ?? [],
            ]);

            if ($onProgress) {
                $onProgress(30, __('Setting up HTTP challenge files...'));
            }
            $challengeFiles = [];

            $validations = $client->domainValidation()->status($order);
            $httpAuths = $client->domainValidation()->getValidationData($validations, AuthorizationChallengeEnum::HTTP);

            Log::info("HTTP-01 challenge setup for {$fqdn}.", [
                'webroot_path' => $webrootPath,
                'auth_count' => count($httpAuths),
                'identifiers' => array_map(fn ($a) => $a['identifier'] ?? null, $httpAuths),
            ]);

            foreach ($httpAuths as $auth) {
                $challengePath = "{$webrootPath}/.well-known/acme-challenge";
                if (! File::isDirectory($challengePath)) {
                    File::makeDirectory($challengePath, 0755, true);
                }

                $filePath = "{$challengePath}/{$auth['filename']}";
                File::put($filePath, $auth['content']);
                // Ensure Caddy (potentially different UID in frankenphp container)
                // can read the challenge file via the shared bind mount.
                @chmod($filePath, 0644);
                $challengeFiles[] = $filePath;

                Log::info("HTTP-01 challenge file written for {$auth['identifier']}.", [
                    'path' => $filePath,
                    'exists' => File::exists($filePath),
                    'size' => File::exists($filePath) ? File::size($filePath) : null,
                    'perms' => File::exists($filePath) ? substr(sprintf('%o', fileperms($filePath)), -4) : null,
                    'content_preview' => substr($auth['content'], 0, 20).'...',
                ]);
            }

            // Start validation for each domain
            if ($onProgress) {
                $onProgress(40, __('Validating domain ownership via HTTP...'));
            }
            foreach ($validations as $validation) {
                if (! empty($validation->file)) {
                    $identifier = $validation->identifier['value'] ?? 'unknown';
                    Log::info("Triggering HTTP-01 validation for {$identifier}.");
                    $startResponse = $client->domainValidation()->start($accountData, $validation, AuthorizationChallengeEnum::HTTP, false);
                    Log::info("HTTP-01 start() response for {$identifier}.", [
                        'http_code' => $startResponse->getHttpResponseCode(),
                        'body' => $startResponse->getBody(),
                    ]);
                }
            }

            // Poll for all challenges to pass
            if ($onProgress) {
                $onProgress(55, __('Waiting for validation...'));
            }
            $pollTimeout = $this->clientFactory->getSettings()['poll_timeout'];
            if (! $this->orderFinalizer->pollUntilChallengesPassed($client, $order, $pollTimeout)) {
                // Capture final authorization state so we can see WHY validation
                // failed (which identifier, what error from Let's Encrypt).
                $finalStatus = $client->domainValidation()->status($order);
                foreach ($finalStatus as $s) {
                    Log::error("HTTP-01 final status for {$fqdn}.", [
                        'identifier' => $s->identifier ?? null,
                        'status' => $s->status ?? null,
                        'file' => $s->file ?? null,
                        'expires' => $s->expires ?? null,
                    ]);
                }

                $this->cleanupFiles($challengeFiles);

                return AcmeResult::failure('HTTP-01 domain validation failed. Ensure the domain is publicly accessible.');
            }

            // Refresh order status
            $order = $client->order()->get($order->id);

            // Finalize
            if ($onProgress) {
                $onProgress(70, __('Finalizing certificate...'));
            }
            $result = $this->orderFinalizer->finalize($order, $domains);

            $this->cleanupFiles($challengeFiles);

            Log::info("HTTP-01 certificate obtained for {$fqdn}.");

            return $result;
        } catch (\Throwable $e) {
            Log::error("HTTP-01 certificate request failed for {$fqdn}: {$e->getMessage()}");

            return AcmeResult::failure($e->getMessage());
        }
    }

    /**
     * Normalize the ACME webroot base path.
     *
     * The base path MUST point at the directory Caddy serves as the root for
     * `/.well-known/acme-challenge/*` (hard-coded to `/var/www/acme-challenge`
     * in DomainConfigService::renderAcmeChallengePath). This method strips any
     * trailing `.well-known/acme-challenge` subpath so a kirli setting like
     * `/var/www/html/.well-known/acme-challenge` does not cause the writer to
     * double-nest the path (which silently misses the bind-mounted directory
     * Caddy is reading from, producing 404s for every HTTP-01 challenge).
     */
    private function normalizeWebrootPath(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '/var/www/acme-challenge';
        }

        $path = rtrim($path, '/');
        $path = preg_replace('#/\.well-known/acme-challenge/?$#', '', $path);
        $path = rtrim((string) $path, '/');

        return $path === '' ? '/var/www/acme-challenge' : $path;
    }

    /**
     * @param  string[]  $files
     */
    private function cleanupFiles(array $files): void
    {
        foreach ($files as $file) {
            try {
                File::delete($file);
            } catch (\Throwable $e) {
                Log::warning("Failed to cleanup challenge file: {$e->getMessage()}");
            }
        }
    }
}
