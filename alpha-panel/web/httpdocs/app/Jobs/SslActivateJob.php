<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\SslCertificateType;
use App\Enums\SslMethod;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Notifications\DomainNotification;
use App\Services\CertbotService;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use App\Services\SslCertificateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SslActivateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 900;

    public function __construct(
        public Domain $domain,
        public ?int $triggeredBy = null,
        public string $locale = 'en',
        public ?string $actorIpAddress = null,
        public ?int $actorPort = null,
    ) {}

    public function handle(
        CertbotService $certbotService,
        DomainConfigService $configService,
        ReloadService $reloadService,
    ): void {
        $this->applyLocale();
        $domain = $this->domain;
        $fqdn = $domain->fqdn;
        $isRenewal = $certbotService->certbotRenewalExists($domain);

        try {
            $sslMethod = $domain->ssl_method ?? SslMethod::CloudflareDns;

            if ($sslMethod === SslMethod::None) {
                Log::info("SSL method is 'none' for {$fqdn}, skipping.");

                return;
            }

            // Clear stale Caddy ACME lock files before any cert operation.
            // Lock files from crashed/killed Caddy processes block every subsequent reload.
            $certbotService->clearCaddyAcmeLocks($domain);

            // For webroot HTTP-01: switch to HTTP-only Caddyfile before certbot runs.
            // renderWithoutTls writes only a :80 block with ACME challenge handler —
            // no :443 block that would break when cleanCertDirectories removes self-signed files.
            // After certbot succeeds, renderWithTls is called below with the real cert.
            if ($sslMethod === SslMethod::WebrootHttp) {
                Log::info("Switching to HTTP-only Caddyfile for {$fqdn} before webroot validation.");
                $configService->renderWithoutTls($domain);
                $reloadService->reloadCaddy();
            }

            if ($sslMethod === SslMethod::SelfSigned) {
                Log::info("Generating self-signed certificate for {$fqdn}.");
                $success = $certbotService->generateSelfSigned($domain);
            } elseif ($isRenewal) {
                Log::info("Renewing SSL certificate for {$fqdn} using {$sslMethod->value}.");
                $success = match ($sslMethod) {
                    SslMethod::WebrootHttp => $certbotService->renewCertificateWebroot($domain),
                    default => $certbotService->renewCertificate($domain),
                };
            } else {
                Log::info("Requesting new SSL certificate for {$fqdn} using {$sslMethod->value}.");
                $success = match ($sslMethod) {
                    SslMethod::WebrootHttp => $certbotService->requestCertificateWebroot($domain),
                    default => $certbotService->requestCertificate($domain),
                };
            }

            if (! $success) {
                // Webroot flow deleted self-signed cert and switched to HTTP-only.
                // Restore HTTPS with a fresh self-signed cert so the site isn't left without TLS.
                if ($sslMethod === SslMethod::WebrootHttp) {
                    Log::info("Webroot failed for {$fqdn}, restoring self-signed certificate.");
                    $certbotService->generateSelfSigned($domain);

                    if ($configService->certExists($domain)) {
                        $configService->renderWithTls($domain);
                        $reloadService->reloadCaddy();
                    }
                }

                $domain->owner->notify(new DomainNotification(
                    level: 'error',
                    title: $isRenewal ? __('SSL Renewal Failed') : __('SSL Activation Failed'),
                    body: $isRenewal
                        ? __('SSL certificate renewal failed for :fqdn. Check the logs for details.', ['fqdn' => $fqdn])
                        : __('SSL certificate activation failed for :fqdn. Check the logs for details.', ['fqdn' => $fqdn]),
                    domainId: $domain->id,
                    url: route('domains.show', $domain),
                    icon: 'bx bx-error-circle',
                    notificationType: NotificationType::SslCertificate,
                ));

                AuditLog::create([
                    'user_id' => $this->triggeredBy,
                    'action' => $isRenewal ? 'ssl_renew_failed' : 'ssl_activate_failed',
                    'domain_id' => $domain->id,
                    'summary' => $isRenewal
                        ? "SSL renewal failed for {$fqdn}."
                        : "SSL activation failed for {$fqdn}.",
                    'ip_address' => $this->actorIpAddress,
                    'port' => $this->actorPort,
                ]);

                return;
            }

            if ($configService->certExists($domain)) {
                $certPaths = $configService->resolveCertPaths($domain);

                if ($certPaths) {
                    $sslCertService = app(SslCertificateService::class);
                    $type = match ($sslMethod) {
                        SslMethod::SelfSigned => SslCertificateType::SelfSigned,
                        default => SslCertificateType::LetsEncrypt,
                    };
                    $validationMethod = match ($sslMethod) {
                        SslMethod::CloudflareDns => 'dns-01',
                        SslMethod::WebrootHttp => 'http-01',
                        default => null,
                    };

                    try {
                        $cert = $sslCertService->createFromDiskCert(
                            $domain,
                            $type,
                            $certPaths['cert'],
                            $certPaths['key'],
                            $validationMethod,
                        );
                        $domain->update(['active_ssl_certificate_id' => $cert->id]);
                        $domain->setRelation('activeSslCertificate', $cert);
                    } catch (\Exception $e) {
                        Log::warning("Failed to create SslCertificate record for {$fqdn}: {$e->getMessage()}");
                    }
                }

                $configService->renderWithTls($domain);
                $reloadService->reloadCaddy();
            }

            $domain->owner->notify(new DomainNotification(
                level: 'success',
                title: $isRenewal ? __('SSL Certificate Renewed') : __('SSL Certificate Activated'),
                body: $isRenewal
                    ? __('SSL certificate renewed successfully for :fqdn.', ['fqdn' => $fqdn])
                    : __('SSL certificate activated successfully for :fqdn.', ['fqdn' => $fqdn]),
                domainId: $domain->id,
                url: route('domains.show', $domain),
                icon: 'bx bx-lock-alt',
                notificationType: NotificationType::SslCertificate,
            ));

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => $isRenewal ? 'ssl_renewed' : 'ssl_activated',
                'domain_id' => $domain->id,
                'summary' => $isRenewal
                    ? "SSL certificate renewed successfully for {$fqdn}."
                    : "SSL certificate activated successfully for {$fqdn}.",
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);

            Log::info($isRenewal ? "SSL certificate renewed for {$fqdn}." : "SSL certificate activated for {$fqdn}.");
        } catch (\Throwable $e) {
            Log::error("SSL activation failed for {$fqdn}: {$e->getMessage()}");

            $domain->owner->notify(new DomainNotification(
                level: 'error',
                title: __('SSL Activation Failed'),
                body: __('SSL certificate operation failed for :fqdn: :error', [
                    'fqdn' => $fqdn,
                    'error' => $e->getMessage(),
                ]),
                domainId: $domain->id,
                url: route('domains.show', $domain),
                icon: 'bx bx-error-circle',
                notificationType: NotificationType::SslCertificate,
            ));

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'ssl_operation_failed',
                'domain_id' => $domain->id,
                'summary' => "SSL certificate operation failed for {$fqdn}: {$e->getMessage()}",
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);
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
