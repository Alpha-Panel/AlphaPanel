<?php

namespace App\Jobs;

use App\Enums\DomainType;
use App\Events\DomainDeleted;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Notifications\DomainNotification;
use App\Services\CloudflareDnsService;
use App\Services\DomainConfigService;
use App\Services\FtpUserService;
use App\Services\ReloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DeleteDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    private string $fqdn;

    private int $ownerUserId;

    private bool $removedAnyFtpUser = false;

    public function __construct(
        public Domain $domain,
        public ?int $triggeredBy = null,
        public string $locale = 'en',
        public bool $deleteDnsRecords = false,
        public ?string $actorIpAddress = null,
        public ?int $actorPort = null,
    ) {
        $this->fqdn = $domain->fqdn;
        $this->ownerUserId = $domain->owner_user_id;
    }

    public function handle(
        DomainConfigService $configService,
        ReloadService $reloadService,
        FtpUserService $ftpUserService,
        CloudflareDnsService $cloudflareDnsService,
    ): void {
        $this->applyLocale();
        $domain = $this->domain;
        $fqdn = $this->fqdn;

        try {
            Log::info("Starting deletion of domain {$fqdn}");

            $domain->loadMissing(['ftpUser', 'phpVersion']);
            $requiresApacheReload = $this->deleteSubdomains($domain, $configService, $cloudflareDnsService);
            $requiresApacheReload = $requiresApacheReload || $domain->type === DomainType::ApacheReverseProxy;

            $this->removeDomainResources($domain, $configService, $cloudflareDnsService);

            $reloadService->reloadCaddy();

            if ($requiresApacheReload) {
                $reloadService->reloadApache();
            }

            $domain->delete();

            if ($this->removedAnyFtpUser) {
                $ftpUserService->syncUsersEnv();
                $ftpUserService->recreateFtpContainers();
            }

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'deleted',
                'domain_id' => null,
                'summary' => "Domain {$fqdn} deleted successfully.",
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);

            $owner = User::find($this->ownerUserId);
            if ($owner) {
                $owner->notify(new DomainNotification(
                    level: 'info',
                    title: __('Domain Deleted'),
                    body: __('Domain :fqdn has been deleted successfully.', ['fqdn' => $fqdn]),
                    icon: 'bx bx-trash',
                ));
            }

            DomainDeleted::dispatch($this->ownerUserId, $fqdn);

            Log::info("Domain {$fqdn} deleted successfully.");
        } catch (\Throwable $e) {
            Log::error("Failed to delete domain {$fqdn}: {$e->getMessage()}");

            $owner = User::find($this->ownerUserId);
            if ($owner) {
                $owner->notify(new DomainNotification(
                    level: 'error',
                    title: __('Deletion Failed'),
                    body: __('Failed to delete domain :fqdn: :error', [
                        'fqdn' => $fqdn,
                        'error' => $e->getMessage(),
                    ]),
                    domainId: $domain->exists ? $domain->id : null,
                    icon: 'bx bx-error-circle',
                ));
            }

            throw $e;
        }
    }

    private function deleteSubdomains(
        Domain $parent,
        DomainConfigService $configService,
        CloudflareDnsService $cloudflareDnsService,
    ): bool {
        $subdomains = $parent->subdomains()
            ->with(['ftpUser', 'phpVersion'])
            ->get();

        $requiresApacheReload = false;

        foreach ($subdomains as $subdomain) {
            if ($this->deleteSubdomains($subdomain, $configService, $cloudflareDnsService)) {
                $requiresApacheReload = true;
            }

            if ($subdomain->type === DomainType::ApacheReverseProxy) {
                $requiresApacheReload = true;
            }

            $this->removeDomainResources($subdomain, $configService, $cloudflareDnsService);
            $subdomain->delete();

            Log::info("Subdomain {$subdomain->fqdn} deleted as part of {$this->fqdn} deletion.");
        }

        return $requiresApacheReload;
    }

    private function removeDomainResources(
        Domain $domain,
        DomainConfigService $configService,
        CloudflareDnsService $cloudflareDnsService,
    ): void {
        if ($this->deleteDnsRecords && $domain->isSubdomain()) {
            $deletedCount = $cloudflareDnsService->deleteSubdomainARecords($domain->getApexDomain(), $domain->fqdn);

            if ($deletedCount > 0) {
                AuditLog::create([
                    'user_id' => $this->triggeredBy,
                    'action' => 'dns_deleted',
                    'domain_id' => $domain->id,
                    'summary' => "Deleted {$deletedCount} Cloudflare A record(s) for {$domain->fqdn}.",
                    'ip_address' => $this->actorIpAddress,
                    'port' => $this->actorPort,
                ]);
            }
        }

        $configService->removeConfigs($domain);
        $this->removeCertificateFiles($domain->fqdn);

        if ($domain->ftpUser) {
            $domain->ftpUser->delete();
            $this->removedAnyFtpUser = true;
        }
    }

    /**
     * Remove Let's Encrypt certificate files for the domain.
     *
     * Cleans up:
     * - /etc/letsencrypt/live/{fqdn}
     * - /etc/letsencrypt/archive/{fqdn}
     * - /etc/letsencrypt/renewal/{fqdn}.conf
     */
    private function removeCertificateFiles(string $fqdn): void
    {
        $letsEncryptRoot = dirname(config('panel.letsencrypt_base'));

        $livePath = "{$letsEncryptRoot}/live/{$fqdn}";
        if (File::isDirectory($livePath)) {
            File::deleteDirectory($livePath);
            Log::info("Removed certificate live directory: {$livePath}");
        }

        $archivePath = "{$letsEncryptRoot}/archive/{$fqdn}";
        if (File::isDirectory($archivePath)) {
            File::deleteDirectory($archivePath);
            Log::info("Removed certificate archive directory: {$archivePath}");
        }

        $renewalFile = "{$letsEncryptRoot}/renewal/{$fqdn}.conf";
        if (File::isFile($renewalFile)) {
            File::delete($renewalFile);
            Log::info("Removed certificate renewal config: {$renewalFile}");
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
