<?php

namespace App\Jobs;

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

class SslActivateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

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
        $isRenewal = $certbotService->certFilesExist($domain);

        try {
            if ($isRenewal) {
                Log::info("Renewing SSL certificate for {$fqdn}.");
                $success = $certbotService->renewCertificate($domain);
            } else {
                Log::info("Requesting new SSL certificate for {$fqdn}.");
                $success = $certbotService->requestCertificate($domain);
            }

            if (! $success) {
                $domain->owner->notify(new DomainNotification(
                    level: 'error',
                    title: $isRenewal ? __('SSL Renewal Failed') : __('SSL Activation Failed'),
                    body: $isRenewal
                        ? __('SSL certificate renewal failed for :fqdn. Ensure the domain is pointed to Cloudflare.', ['fqdn' => $fqdn])
                        : __('SSL certificate activation failed for :fqdn. Ensure the domain is pointed to Cloudflare.', ['fqdn' => $fqdn]),
                    domainId: $domain->id,
                    url: route('domains.show', $domain),
                    icon: 'bx bx-error-circle',
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
