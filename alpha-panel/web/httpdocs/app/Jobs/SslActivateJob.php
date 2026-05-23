<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\SslCertificateType;
use App\Enums\SslMethod;
use App\Events\SslOperationProgress;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Notifications\DomainNotification;
use App\Services\Acme\AcmeResult;
use App\Services\Acme\AcmeService;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use App\Services\SslCertificateService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SslActivateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> Exponential backoff in seconds: 1m, 5m, 15m, 1h, 2h. */
    public array $backoff = [60, 300, 900, 3600, 7200];

    public int $timeout = 900;

    public function __construct(
        public Domain $domain,
        public ?int $triggeredBy = null,
        public string $locale = 'en',
        public ?string $actorIpAddress = null,
        public ?int $actorPort = null,
        public bool $isRenewal = false,
    ) {}

    private function sslNotificationType(): NotificationType
    {
        return $this->isRenewal
            ? NotificationType::SslRenewal
            : NotificationType::SslIssuance;
    }

    public function handle(
        AcmeService $acmeService,
        DomainConfigService $configService,
        ReloadService $reloadService,
    ): void {
        $this->applyLocale();
        $domain = $this->domain;
        $fqdn = $domain->fqdn;

        $lock = Cache::lock("ssl:operation:{$domain->id}", $this->timeout);

        if (! $lock->get()) {
            Log::warning("SSL operation already in progress for {$fqdn}, skipping.");

            return;
        }

        try {
            $sslMethod = $domain->ssl_method ?? SslMethod::CloudflareDns;

            if ($sslMethod === SslMethod::None) {
                Log::info("SSL method is 'none' for {$fqdn}, skipping.");

                return;
            }

            // Subdomain short-circuit: if the apex holds a cert that covers this
            // subdomain's FQDN (e.g. a wildcard), reuse it instead of issuing a
            // new one. No ACME order, no DNS zone lookup, no duplication.
            if ($domain->isSubdomain()) {
                $parent = $domain->parentDomain
                    ?: Domain::where('fqdn', $domain->getApexDomain())->first();
                $parentCert = $parent?->activeSslCertificate;

                if ($parentCert && $parentCert->coversFqdn($domain->fqdn)) {
                    Log::info("Subdomain {$fqdn} is covered by apex cert for {$parent->fqdn}; reusing and skipping issuance.");

                    // Lock the subdomain row so two concurrent SslActivateJobs for sibling
                    // subdomains can't race and overwrite each other's active_ssl_certificate_id.
                    DB::transaction(function () use ($domain, $parentCert): void {
                        $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->first();
                        if ($locked === null) {
                            return;
                        }

                        SslCertificate::where('domain_id', $domain->id)
                            ->where('id', '!=', $parentCert->id)
                            ->update(['auto_renew' => false]);

                        $locked->update(['active_ssl_certificate_id' => $parentCert->id]);
                        $domain->setRawAttributes($locked->getAttributes(), true);
                        $domain->setRelation('activeSslCertificate', $parentCert);
                    });

                    $configService->renderWithTls($domain);
                    $reloadService->reloadCaddy();

                    $this->progress($domain, 100, __('Subdomain reuses apex certificate.'), 'completed');

                    return;
                }
            }

            $this->progress($domain, 5, __('Starting SSL activation for :fqdn...', ['fqdn' => $fqdn]));

            $acmeService->clearCaddyAcmeLocks($domain);
            $this->progress($domain, 10, __('Cleared ACME lock files.'));

            if ($sslMethod === SslMethod::WebrootHttp) {
                Log::info("Switching to HTTP-only Caddyfile for {$fqdn} before webroot validation.");
                $this->progress($domain, 15, __('Preparing HTTP-only configuration for webroot validation...'));
                $configService->renderWithoutTls($domain);
                $reloadService->reloadCaddy();
            }

            $this->progress($domain, 20, __('Requesting certificate via :method...', ['method' => $sslMethod->value]));

            $result = match ($sslMethod) {
                SslMethod::CloudflareDns => $acmeService->requestCertificateDnsCloudflare($domain, fn ($p, $m) => $this->progress($domain, $p, $m)),
                SslMethod::LocalDns => $acmeService->requestCertificateDnsLocal($domain, fn ($p, $m) => $this->progress($domain, $p, $m)),
                SslMethod::WebrootHttp => $acmeService->requestCertificateHttp($domain, fn ($p, $m) => $this->progress($domain, $p, $m)),
                SslMethod::SelfSigned => $acmeService->generateSelfSigned($domain),
                default => AcmeResult::failure("Unsupported SSL method: {$sslMethod->value}"),
            };

            if (! $result->success) {
                Log::error("SSL certificate request failed for {$fqdn}: {$result->error}");

                // If we switched to HTTP-only for webroot validation, restore the
                // previous TLS Caddyfile so the domain keeps serving HTTPS with
                // whatever cert resolveCertPaths finds (existing active cert,
                // inherited apex wildcard, legacy disk cert, or the panel default
                // self-signed fallback). Do NOT generate a fresh self-signed cert
                // on failure — that would clobber the existing certificate and
                // create a new SslCertificate row on every failed attempt.
                if ($sslMethod === SslMethod::WebrootHttp) {
                    try {
                        $configService->regenerateCaddyConfig($domain);
                        $reloadService->reloadCaddy();
                    } catch (\Throwable $e) {
                        Log::warning("Failed to restore TLS Caddyfile for {$fqdn} after HTTP-01 failure: {$e->getMessage()}");
                    }
                }

                $domain->owner?->notify(new DomainNotification(
                    level: 'error',
                    title: $this->isRenewal ? __('SSL Renewal Failed') : __('SSL Activation Failed'),
                    body: __('SSL certificate activation failed for :fqdn. Check the logs for details.', ['fqdn' => $fqdn]),
                    domainId: $domain->id,
                    url: route('domains.show', $domain),
                    icon: 'bx bx-error-circle',
                    notificationType: $this->sslNotificationType(),
                    actorUserId: $this->triggeredBy,
                ));

                AuditLog::create([
                    'user_id' => $this->triggeredBy,
                    'action' => 'ssl_activate_failed',
                    'domain_id' => $domain->id,
                    'summary' => "SSL activation failed for {$fqdn}: {$result->error}",
                    'ip_address' => $this->actorIpAddress,
                    'port' => $this->actorPort,
                ]);

                $this->progress($domain, 100, __('SSL activation failed.'), 'failed');

                return;
            }

            // Certificate obtained successfully — store and activate
            $this->progress($domain, 85, __('Certificate obtained, storing and activating...'));
            $this->storeCertAndActivate($domain, $result, $sslMethod, $configService, $reloadService);

            // Delete ACME challenge DNS records now that the certificate is fully
            // stored on disk and Caddy has reloaded. Doing it here (not inside
            // AcmeService right after validation) ensures the records remain
            // visible to LE's resolvers throughout the entire finalization phase.
            $result->runCleanup();

            $domain->owner?->notify(new DomainNotification(
                level: 'success',
                title: $this->isRenewal ? __('SSL Certificate Renewed') : __('SSL Certificate Activated'),
                body: $this->isRenewal
                    ? __('SSL certificate renewed successfully for :fqdn.', ['fqdn' => $fqdn])
                    : __('SSL certificate activated successfully for :fqdn.', ['fqdn' => $fqdn]),
                domainId: $domain->id,
                url: route('domains.show', $domain),
                icon: $this->isRenewal ? 'bx bx-refresh' : 'bx bx-lock-alt',
                notificationType: $this->sslNotificationType(),
                actorUserId: $this->triggeredBy,
            ));

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'ssl_activated',
                'domain_id' => $domain->id,
                'summary' => "SSL certificate activated successfully for {$fqdn}.",
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);

            $this->progress($domain, 100, __('SSL certificate activated successfully.'), 'completed');
            Log::info("SSL certificate activated for {$fqdn}.");
        } catch (\Throwable $e) {
            Log::error("SSL activation failed for {$fqdn}: {$e->getMessage()}");

            $domain->owner?->notify(new DomainNotification(
                level: 'error',
                title: $this->isRenewal ? __('SSL Renewal Failed') : __('SSL Activation Failed'),
                body: __('SSL certificate operation failed for :fqdn: :error', [
                    'fqdn' => $fqdn,
                    'error' => $e->getMessage(),
                ]),
                domainId: $domain->id,
                url: route('domains.show', $domain),
                icon: 'bx bx-error-circle',
                notificationType: $this->sslNotificationType(),
                actorUserId: $this->triggeredBy,
            ));

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'ssl_operation_failed',
                'domain_id' => $domain->id,
                'summary' => "SSL certificate operation failed for {$fqdn}: {$e->getMessage()}",
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);

            $this->progress($domain, 100, __('SSL activation failed unexpectedly.'), 'failed');
        } finally {
            $lock->release();
        }
    }

    /**
     * Store the certificate from PEM data and activate it for the domain.
     */
    private function storeCertAndActivate(
        Domain $domain,
        AcmeResult $result,
        SslMethod $sslMethod,
        DomainConfigService $configService,
        ReloadService $reloadService,
    ): void {
        $sslCertService = app(SslCertificateService::class);

        $type = match ($sslMethod) {
            SslMethod::SelfSigned => SslCertificateType::SelfSigned,
            default => SslCertificateType::LetsEncrypt,
        };

        $validationMethod = match ($sslMethod) {
            SslMethod::CloudflareDns, SslMethod::LocalDns => 'dns-01',
            SslMethod::WebrootHttp => 'http-01',
            default => null,
        };

        try {
            $meta = $sslCertService->parseCertificatePem($result->certificatePem);

            $cert = SslCertificate::create([
                'domain_id' => $domain->id,
                'type' => $type,
                'label' => $type->label().' - '.($meta['common_name'] ?? $domain->fqdn),
                'common_name' => $meta['common_name'],
                'issuer' => $meta['issuer'],
                'san_domains' => $meta['san_domains'],
                'private_key_pem' => $result->privateKeyPem,
                'certificate_pem' => $result->certificatePem,
                'ca_bundle_pem' => $result->caBundlePem,
                'validation_method' => $validationMethod,
                'not_before' => $meta['not_before'] ? Carbon::parse($meta['not_before']) : null,
                'not_after' => $meta['not_after'] ? Carbon::parse($meta['not_after']) : null,
                'fingerprint_sha256' => $meta['fingerprint_sha256'],
                'is_wildcard' => $meta['is_wildcard'],
                'auto_renew' => $type === SslCertificateType::LetsEncrypt,
            ]);

            $domain->update(['active_ssl_certificate_id' => $cert->id]);
            $domain->setRelation('activeSslCertificate', $cert);

            // Write all disk state first — cert files, Caddyfiles for this domain
            // and all inheriting subdomains, plus the live-path sync for the panel
            // base domain. A single restartCaddy() at the end picks up everything
            // atomically. Calling reload before subdomain rendering would leave
            // subdomain Caddyfiles stale until the next restart.
            $sslCertService->writeCertToDisk($domain, $cert);
            $configService->renderWithTls($domain);

            if ($domain->fqdn === config('panel.base_domain')) {
                $sslCertService->syncToLivePath($domain, $cert);
            }

            if (! $domain->isSubdomain()) {
                $oldCertIds = SslCertificate::where('domain_id', $domain->id)
                    ->where('id', '!=', $cert->id)
                    ->pluck('id');

                $domain->subdomains()
                    ->whereIn('active_ssl_certificate_id', $oldCertIds)
                    ->get()
                    ->each(function (Domain $child) use ($cert, $configService): void {
                        if ($cert->coversFqdn($child->fqdn)) {
                            $child->update(['active_ssl_certificate_id' => $cert->id]);
                            $configService->renderWithTls($child);
                        }
                    });
            }

            // Restart (not reload) after all Caddyfiles are on disk. restartCaddy()
            // is synchronous and reliable — reloadCaddy() is fire-and-forget; a
            // silent WAF compilation failure there leaves sites down until the next
            // manual restart.
            $reloadService->restartCaddy();
        } catch (\Exception $e) {
            Log::warning("Failed to create SslCertificate record for {$domain->fqdn}: {$e->getMessage()}");
        }
    }

    /**
     * Broadcast SSL operation progress to the frontend. Broadcast failures fall
     * back to log lines so operators still get a paper trail when Reverb is down.
     */
    private function progress(Domain $domain, int $percent, string $message, string $status = 'running'): void
    {
        try {
            SslOperationProgress::dispatch($domain, $percent, $message, $status);
        } catch (\Throwable $e) {
            Log::info("[SSL progress] {$domain->fqdn} {$percent}% {$status}: {$message} (broadcast failed: {$e->getMessage()})");
        }
    }

    private function applyLocale(): void
    {
        $supportedLocales = config('app.supported_locales', ['en']);
        $resolvedLocale = in_array($this->locale, $supportedLocales, true)
            ? $this->locale
            : (string) config('app.locale', 'en');

        app()->setLocale($resolvedLocale);
    }
}
