<?php

namespace App\Jobs;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\NotificationType;
use App\Enums\SslMethod;
use App\Events\DomainProvisioned;
use App\Events\DomainProvisionFailed;
use App\Events\DomainProvisionProgress;
use App\Models\ApplyRun;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Notifications\DomainNotification;
use App\Services\Acme\AcmeService;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ProvisionDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 900;

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
        AcmeService $acmeService,
        ReloadService $reloadService,
    ): void {
        $this->applyLocale();
        $domain = $this->domain;

        $lock = Cache::lock("provision:{$domain->id}", $this->timeout);

        if (! $lock->get()) {
            Log::warning("Provision already in progress for {$domain->fqdn}, skipping duplicate dispatch.");

            return;
        }

        $applyRun = ApplyRun::create([
            'domain_id' => $domain->id,
            'status' => 'running',
            'progress_percent' => 0,
            'message' => 'Starting provisioning...',
            'started_at' => now(),
            'created_by' => $this->triggeredBy,
        ]);

        try {
            // Ensure the panel default self-signed cert exists up front so Caddy
            // always has a last-resort TLS cert available for the new domain.
            $this->progress($domain, 5, 'Ensuring panel default certificate...');
            try {
                $acmeService->ensurePanelDefaultSelfSigned();
            } catch (\Throwable $e) {
                Log::warning("Panel default cert ensure failed: {$e->getMessage()}");
            }

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

            // SslMethod::None — skip TLS entirely, stay on HTTP-only
            if ($sslMethod === SslMethod::None) {
                $this->progress($domain, 40, 'No SSL requested, writing HTTP-only config...');
                $configService->renderWithoutTls($domain);

                $this->progress($domain, 60, 'Restarting Caddy (HTTP only)...');
                $reloadService->restartCaddy();

                $domain->update(['status' => DomainStatus::Active]);
                $applyRun->update([
                    'status' => 'completed',
                    'progress_percent' => 100,
                    'message' => 'Provisioning completed (HTTP-only).',
                    'finished_at' => now(),
                ]);
                $this->progress($domain, 100, 'Provisioning complete (HTTP-only)!');
                DomainProvisioned::dispatch($domain);

                $domain->owner->notify(new DomainNotification(
                    level: 'success',
                    title: __('Domain Provisioned'),
                    body: __('Domain :fqdn provisioned successfully (HTTP-only).', ['fqdn' => $domain->fqdn]),
                    domainId: $domain->id,
                    url: route('domains.show', $domain),
                    notificationType: NotificationType::DomainProvisioned,
                    actorUserId: $this->triggeredBy,
                ));

                AuditLog::create([
                    'user_id' => $this->triggeredBy,
                    'action' => 'provisioned',
                    'domain_id' => $domain->id,
                    'summary' => "Domain {$domain->fqdn} provisioned (HTTP-only).",
                    'ip_address' => $this->actorIpAddress,
                    'port' => $this->actorPort,
                ]);

                return;
            }

            if ($domain->isSubdomain()) {
                $this->progress($domain, 40, 'Using parent wildcard certificate...');
            } elseif ($configService->certExists($domain)) {
                $this->progress($domain, 40, 'SSL certificate already exists, skipping...');
            } else {
                // Attempt to provision a domain-specific self-signed cert on initial
                // creation. The domain may not be pointed at this server yet, so real
                // ACME/DNS challenges would fail. The user triggers real SSL later.
                //
                // If this fails, we do NOT fail the job — resolveCertPaths() will
                // fall back to the panel default self-signed cert so HTTPS still works.
                $this->progress($domain, 40, 'Generating self-signed certificate...');
                $selfSignedResult = $acmeService->generateSelfSigned($domain);

                if ($selfSignedResult->success) {
                    $selfSignedDir = config('panel.letsencrypt_selfsigned_base').'/'.$domain->fqdn;
                    if (! File::isDirectory($selfSignedDir)) {
                        File::makeDirectory($selfSignedDir, 0755, true);
                    }
                    File::put("{$selfSignedDir}/fullchain.pem", $selfSignedResult->fullchainPem);
                    File::put("{$selfSignedDir}/privkey.pem", $selfSignedResult->privateKeyPem);
                    File::chmod("{$selfSignedDir}/privkey.pem", 0600);
                    $selfSigned = true;
                } else {
                    Log::warning("Self-signed cert generation failed for {$domain->fqdn}, falling back to panel default cert.");
                }
            }

            // resolveCertPaths() always returns a path now (falls back to the
            // panel default cert), so renderWithTls() is always safe to call.
            $certObtained = $configService->certExists($domain);

            if ($certObtained) {
                $this->progress($domain, 60, 'Writing TLS config...');
                $configService->renderWithTls($domain);

                $this->progress($domain, 75, 'Restarting Caddy...');
                $reloadService->restartCaddy();

                if ($domain->type === DomainType::ApacheReverseProxy) {
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
                    notificationType: NotificationType::DomainProvisioned,
                    actorUserId: $this->triggeredBy,
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
        } finally {
            $lock->release();
        }
    }

    protected function progress(Domain $domain, int $percent, string $message): void
    {
        try {
            DomainProvisionProgress::dispatch($domain, $percent, $message);
        } catch (\Throwable $e) {
            Log::warning("Progress broadcast failed (non-fatal): {$e->getMessage()}");
        }
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
            notificationType: NotificationType::DomainProvisioned,
            actorUserId: $this->triggeredBy,
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
