<?php

namespace App\Jobs;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Events\DomainProvisionProgress;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Notifications\DomainNotification;
use App\Services\DomainConfigService;
use App\Services\FtpUserService;
use App\Services\PortainerService;
use App\Services\ReloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RenameDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public Domain $domain,
        public string $oldFqdn,
        public ?int $triggeredBy = null,
        public string $locale = 'en',
        public ?string $actorIpAddress = null,
        public ?int $actorPort = null,
    ) {}

    public function handle(
        DomainConfigService $configService,
        ReloadService $reloadService,
        PortainerService $portainer,
        FtpUserService $ftpUserService,
    ): void {
        $this->applyLocale();
        $domain = $this->domain;
        $oldFqdn = $this->oldFqdn;
        $newFqdn = $domain->fqdn;

        try {
            Log::info("Starting domain rename: {$oldFqdn} → {$newFqdn}");

            // Step 1: Remove old Caddyfile/Apache/FPM configs
            $this->progress($domain, 10, 'Removing old configuration files...');
            $configService->removeConfigsByFqdn($oldFqdn, $domain->phpVersion);

            // Step 2: Rename vhost directory on server
            $this->progress($domain, 25, 'Renaming vhost directory...');
            $this->renameVhostDirectory($portainer, $oldFqdn, $newFqdn);

            // Step 3: Re-render subdomain configs (their base path changed)
            $this->progress($domain, 40, 'Updating subdomain configurations...');
            $this->updateSubdomainConfigs($domain, $configService);

            // Step 4: Update FTP user home path if exists
            $this->progress($domain, 55, 'Updating FTP user configuration...');
            $domain->load('ftpUser');
            if ($domain->ftpUser) {
                $ftpUserService->updateHomedir($domain->ftpUser, $domain->getBasePath());
            }

            // Step 5: Write new domain config (HTTP-only first, SSL will be handled by ProvisionDomainJob)
            $this->progress($domain, 70, 'Writing new configuration...');
            $configService->renderWithoutTls($domain);

            // Step 6: Reload Caddy
            $this->progress($domain, 85, 'Reloading web server...');
            $reloadService->reloadCaddy();

            if ($domain->type === DomainType::ApacheReverseProxy) {
                $reloadService->reloadApache();
                if ($domain->phpVersion) {
                    $reloadService->reloadPhpFpm($domain->phpVersion);
                }
            }

            $this->progress($domain, 95, 'Requesting SSL certificate for new domain...');

            // Step 7: Dispatch ProvisionDomainJob for SSL (new FQDN needs new cert)
            ProvisionDomainJob::dispatch(
                $domain,
                triggeredBy: $this->triggeredBy,
                createDnsRecord: false,
                locale: $this->locale,
                actorIpAddress: $this->actorIpAddress,
                actorPort: $this->actorPort,
            );

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'renamed',
                'domain_id' => $domain->id,
                'summary' => "Domain renamed from {$oldFqdn} to {$newFqdn}.",
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);

            Log::info("Domain rename completed: {$oldFqdn} → {$newFqdn}");
        } catch (\Throwable $e) {
            Log::error("Domain rename failed ({$oldFqdn} → {$newFqdn}): {$e->getMessage()}");

            $domain->update(['status' => DomainStatus::Failed]);

            $domain->owner->notify(new DomainNotification(
                level: 'error',
                title: __('Domain Rename Failed'),
                body: __('Rename from :old to :new failed: :error', [
                    'old' => $oldFqdn,
                    'new' => $newFqdn,
                    'error' => $e->getMessage(),
                ]),
                domainId: $domain->id,
                url: route('domains.show', $domain),
                icon: 'bx bx-error-circle',
            ));

            throw $e;
        }
    }

    /**
     * Rename the vhost directory using Portainer exec in the frankenphp container.
     */
    private function renameVhostDirectory(PortainerService $portainer, string $oldFqdn, string $newFqdn): void
    {
        $oldPath = "/var/www/vhosts/{$oldFqdn}";
        $newPath = "/var/www/vhosts/{$newFqdn}";

        $result = $portainer->execInContainer(
            config('panel.frankenphp_container', 'frankenphp'),
            ['mv', $oldPath, $newPath],
            timeout: 30,
        );

        if (! $result->isSuccessful()) {
            throw new \RuntimeException("Failed to rename vhost directory: {$result->errorOutput}");
        }

        Log::info("Vhost directory renamed: {$oldPath} → {$newPath}");
    }

    /**
     * Re-render configs for all subdomains whose base path changed.
     */
    private function updateSubdomainConfigs(Domain $domain, DomainConfigService $configService): void
    {
        $subdomains = $domain->subdomains()->get();

        foreach ($subdomains as $subdomain) {
            if ($configService->certExists($subdomain)) {
                $configService->renderWithTls($subdomain);
            } else {
                $configService->renderWithoutTls($subdomain);
            }

            Log::info("Updated subdomain config: {$subdomain->fqdn}");
        }
    }

    private function progress(Domain $domain, int $percent, string $message): void
    {
        DomainProvisionProgress::dispatch($domain, $percent, $message);
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
