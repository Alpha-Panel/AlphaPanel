<?php

namespace App\Jobs;

use App\Enums\DomainStatus;
use App\Enums\SslMethod;
use App\Events\DomainProvisioned;
use App\Events\DomainProvisionFailed;
use App\Events\DomainProvisionProgress;
use App\Models\ApplyRun;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Notifications\DomainNotification;
use App\Services\CertbotService;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public Domain $domain,
        public ?int $triggeredBy = null,
        public bool $createDnsRecord = false,
        public string $locale = 'en',
        public ?string $dnsTargetIp = null,
        public bool $dnsProxied = false,
        public ?string $actorIpAddress = null,
        public ?int $actorPort = null,
    ) {}

    public function handle(
        DomainConfigService $configService,
        CertbotService $certbotService,
        ReloadService $reloadService,
    ): void {
        $this->applyLocale();
        $domain = $this->domain;
        $applyRun = ApplyRun::create([
            'domain_id' => $domain->id,
            'status' => 'running',
            'progress_percent' => 0,
            'message' => 'Starting provisioning...',
            'started_at' => now(),
            'created_by' => $this->triggeredBy,
        ]);

        try {
            $this->progress($domain, 10, 'Writing config without TLS...');
            $configService->renderWithoutTls($domain);

            $this->progress($domain, 25, 'Reloading Caddy (HTTP only)...');
            $reloadService->reloadCaddy();

            if ($this->createDnsRecord) {
                $this->progress($domain, 30, 'Creating DNS records...');
                dispatch(new DnsSyncJob(
                    $domain,
                    targetIp: $this->dnsTargetIp,
                    triggeredBy: $this->triggeredBy,
                    proxied: $this->dnsProxied,
                    actorIpAddress: $this->actorIpAddress,
                    actorPort: $this->actorPort,
                ));
            }

            $selfSigned = false;
            $sslMethod = $domain->ssl_method ?? SslMethod::CloudflareDns;

            if ($sslMethod === SslMethod::None) {
                $this->progress($domain, 40, 'SSL disabled, keeping HTTP-only config...');
                $certObtained = false;
            } elseif ($domain->isSubdomain()) {
                $this->progress($domain, 40, 'Using parent wildcard certificate...');
                $certObtained = $configService->certExists($domain);
            } elseif ($configService->certExists($domain)) {
                $this->progress($domain, 40, 'SSL certificate already exists, skipping...');
                $certObtained = true;
            } else {
                $this->progress($domain, 40, 'Requesting SSL certificate...');

                $certObtained = match ($sslMethod) {
                    SslMethod::WebrootHttp => $certbotService->requestCertificateWebroot($domain),
                    SslMethod::SelfSigned => $certbotService->generateSelfSigned($domain),
                    default => $certbotService->requestCertificate($domain),
                };

                if ($sslMethod === SslMethod::SelfSigned && $certObtained) {
                    $selfSigned = true;
                }

                if (! $certObtained && $sslMethod !== SslMethod::SelfSigned) {
                    $this->progress($domain, 50, 'Certbot failed, generating self-signed certificate...');
                    $certObtained = $certbotService->generateSelfSigned($domain);
                    $selfSigned = $certObtained;
                }
            }

            if ($sslMethod === SslMethod::None) {
                // HTTP-only mode — no TLS config needed
                $this->progress($domain, 75, 'Reloading services (HTTP only)...');
                $reloadService->reloadCaddy();

                if ($domain->type === \App\Enums\DomainType::ApacheReverseProxy) {
                    $reloadService->reloadApache();
                    if ($domain->phpVersion) {
                        $reloadService->reloadPhpFpm($domain->phpVersion);
                    }
                }

                $domain->update(['status' => DomainStatus::Active]);
                $applyRun->update([
                    'status' => 'completed',
                    'progress_percent' => 100,
                    'message' => 'Provisioning completed (HTTP only).',
                    'finished_at' => now(),
                ]);

                $this->progress($domain, 100, 'Provisioning complete (HTTP only)!');
                DomainProvisioned::dispatch($domain);

                $domain->owner->notify(new DomainNotification(
                    level: 'info',
                    title: __('Domain Provisioned'),
                    body: __('Domain :fqdn provisioned without SSL (HTTP only).', ['fqdn' => $domain->fqdn]),
                    domainId: $domain->id,
                    url: route('domains.show', $domain),
                ));

                AuditLog::create([
                    'user_id' => $this->triggeredBy,
                    'action' => 'provisioned',
                    'domain_id' => $domain->id,
                    'summary' => "Domain {$domain->fqdn} provisioned (HTTP only).",
                    'ip_address' => $this->actorIpAddress,
                    'port' => $this->actorPort,
                ]);
            } elseif ($certObtained && $configService->certExists($domain)) {
                $this->progress($domain, 60, 'Rewriting config with TLS...');
                $configService->renderWithTls($domain);

                $this->progress($domain, 75, 'Reloading services...');
                $reloadService->reloadCaddy();

                if ($domain->type === \App\Enums\DomainType::ApacheReverseProxy) {
                    $reloadService->reloadApache();
                    if ($domain->phpVersion) {
                        $reloadService->reloadPhpFpm($domain->phpVersion);
                    }
                }

                $domain->update(['status' => DomainStatus::Active]);
                $applyRun->update([
                    'status' => 'completed',
                    'progress_percent' => 100,
                    'message' => 'Provisioning completed successfully.',
                    'finished_at' => now(),
                ]);

                $this->progress($domain, 100, 'Provisioning complete!');
                DomainProvisioned::dispatch($domain);

                $notifLevel = $selfSigned ? 'info' : 'success';
                $notifBody = $selfSigned
                    ? __('Domain :fqdn provisioned with self-signed certificate. Activate SSL for a trusted certificate.', ['fqdn' => $domain->fqdn])
                    : __('Domain :fqdn provisioned successfully.', ['fqdn' => $domain->fqdn]);

                $domain->owner->notify(new DomainNotification(
                    level: $notifLevel,
                    title: __('Domain Provisioned'),
                    body: $notifBody,
                    domainId: $domain->id,
                    url: route('domains.show', $domain),
                ));

                AuditLog::create([
                    'user_id' => $this->triggeredBy,
                    'action' => 'provisioned',
                    'domain_id' => $domain->id,
                    'summary' => "Domain {$domain->fqdn} provisioned successfully.",
                    'ip_address' => $this->actorIpAddress,
                    'port' => $this->actorPort,
                ]);
            } else {
                $this->failProvision($domain, $applyRun, 'SSL certificate could not be obtained.');
            }
        } catch (\Throwable $e) {
            Log::error("Provision failed for {$domain->fqdn}: {$e->getMessage()}");
            $this->failProvision($domain, $applyRun, $e->getMessage());
        }
    }

    protected function progress(Domain $domain, int $percent, string $message): void
    {
        DomainProvisionProgress::dispatch($domain, $percent, $message);
    }

    protected function failProvision(Domain $domain, ApplyRun $applyRun, string $error): void
    {
        $domain->update(['status' => DomainStatus::Failed]);
        $applyRun->update([
            'status' => 'failed',
            'message' => $error,
            'finished_at' => now(),
        ]);

        DomainProvisionFailed::dispatch($domain, $error);

        $domain->owner->notify(new DomainNotification(
            level: 'error',
            title: __('Provision Failed'),
            body: __('Domain :fqdn provision failed: :error', [
                'fqdn' => $domain->fqdn,
                'error' => $error,
            ]),
            domainId: $domain->id,
            url: route('domains.show', $domain),
            icon: 'bx bx-error-circle',
        ));

        AuditLog::create([
            'user_id' => $this->triggeredBy,
            'action' => 'provision_failed',
            'domain_id' => $domain->id,
            'summary' => "Domain {$domain->fqdn} provision failed: {$error}",
            'ip_address' => $this->actorIpAddress,
            'port' => $this->actorPort,
        ]);
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
