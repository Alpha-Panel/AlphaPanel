<?php

namespace App\Jobs;

use App\Enums\DomainType;
use App\Enums\NotificationType;
use App\Events\DomainDeleted;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Models\User;
use App\Notifications\DomainNotification;
use App\Services\CloudflareDnsService;
use App\Services\DomainConfigService;
use App\Services\FtpUserService;
use App\Services\LocalDnsService;
use App\Services\PortainerService;
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

    public int $timeout = 900;

    private string $fqdn;

    private int $ownerUserId;

    /** @var list<array{username: string, uid: int|null}> */
    private array $removedFtpUsers = [];

    /** @var array<int, PhpVersion> */
    private array $phpVersionsNeedingFpmReload = [];

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
        LocalDnsService $localDnsService,
    ): void {
        $this->applyLocale();
        $domain = $this->domain;
        $fqdn = $this->fqdn;

        // Idempotency — if the model is already gone (manual cleanup or earlier retry
        // finished before failing), don't double-process and don't duplicate audit logs.
        if (! Domain::query()->whereKey($domain->id)->exists()) {
            Log::info("Domain {$fqdn} already deleted; skipping job.");

            return;
        }

        try {
            Log::info("Starting deletion of domain {$fqdn}");

            $domain->loadMissing(['ftpUser', 'phpVersion']);
            $requiresApacheReload = $this->deleteSubdomains($domain, $configService, $cloudflareDnsService);
            $requiresApacheReload = $requiresApacheReload || $domain->type === DomainType::ApacheReverseProxy;

            $this->removeDomainResources($domain, $configService, $cloudflareDnsService, $localDnsService);

            $reloadService->reloadCaddy();

            if ($requiresApacheReload) {
                $reloadService->reloadApache();
            }

            // The pool files are gone from disk, but a running PHP-FPM master keeps
            // serving the deleted domain's pool until it is reloaded. The compose
            // recreate this job used to perform did that as a side effect.
            foreach ($this->phpVersionsNeedingFpmReload as $phpVersion) {
                $reloadService->reloadPhpFpm($phpVersion);
            }

            $domain->delete();

            if ($this->removedFtpUsers !== []) {
                $ftpUserService->syncUsersEnv();

                foreach ($this->removedFtpUsers as $removed) {
                    $ftpUserService->removeSystemUser($removed['username'], $removed['uid']);
                }
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
                    notificationType: NotificationType::DomainDeleted,
                    actorUserId: $this->triggeredBy,
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
                    notificationType: NotificationType::DomainDeleted,
                    actorUserId: $this->triggeredBy,
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

            $subdomain->setRelation('parentDomain', $parent);
            $this->removeDomainResources($subdomain, $configService, $cloudflareDnsService, app(LocalDnsService::class));
            $subdomain->delete();

            Log::info("Subdomain {$subdomain->fqdn} deleted as part of {$this->fqdn} deletion.");
        }

        return $requiresApacheReload;
    }

    private function removeDomainResources(
        Domain $domain,
        DomainConfigService $configService,
        CloudflareDnsService $cloudflareDnsService,
        LocalDnsService $localDnsService,
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

        if ($domain->usesLocalDns() && ! $domain->isSubdomain()) {
            try {
                $localDnsService->deleteZone($domain);
            } catch (\Throwable $e) {
                Log::warning("Failed to delete local DNS zone for {$domain->fqdn}: {$e->getMessage()}");
            }
        }

        // Unlock immutable .user.ini files so the directory can be cleaned up
        $this->unlockUserIniFiles($domain);

        $configService->removeConfigs($domain);

        if ($domain->phpVersion && in_array($domain->type, [DomainType::ApacheReverseProxy, DomainType::CaddyFastCgi], true)) {
            $this->phpVersionsNeedingFpmReload[$domain->phpVersion->id] = $domain->phpVersion;
        }

        // Certificates are keyed by fqdn, never shared with the parent even when the
        // web root is (SslActivateJob only reuses the apex cert when it actually
        // covers the subdomain), so this runs unconditionally.
        $this->removeCertificateFiles($domain->fqdn);

        if ($domain->sharesWebRootWithParent()) {
            Log::info("Skipped filesystem cleanup for {$domain->fqdn} because it shares web root with parent domain.");
        } else {
            $this->removeWebRoot($domain);
        }

        if ($domain->ftpUser) {
            $this->removedFtpUsers[] = [
                'username' => (string) $domain->ftpUser->username,
                'uid' => $domain->ftpUser->uid !== null ? (int) $domain->ftpUser->uid : null,
            ];
            $domain->ftpUser->delete();
        }
    }

    /**
     * Remove the domain's own files under the vhost root.
     *
     * DomainConfigService only removes generated config, so until now every
     * deletion left an orphaned /var/www/vhosts/<domain> behind.
     * `unlockUserIniFiles()` above exists precisely to make this directory
     * removable, and the `sharesWebRootWithParent()` guard on the caller exists so
     * a subdomain never takes its parent's files with it.
     */
    private function removeWebRoot(Domain $domain): void
    {
        $basePath = $this->deletableWebRootPath($domain);

        if ($basePath === null) {
            return;
        }

        $blocker = $this->findDomainServingFrom($domain, $basePath);
        if ($blocker !== null) {
            Log::warning(
                "Refusing to remove web root {$basePath} for {$domain->fqdn}: ".
                "domain {$blocker->fqdn} is still served from inside it."
            );

            return;
        }

        if (! File::isDirectory($basePath)) {
            return;
        }

        if (File::deleteDirectory($basePath)) {
            Log::info("Removed web root directory: {$basePath}");
        } else {
            Log::warning("Failed to remove web root directory: {$basePath}");
        }
    }

    /**
     * The directory that may be deleted for this domain, or null when a guard refuses.
     *
     * Pure — no database and no filesystem access — so every refusal path is cheap to
     * test exhaustively. The cross-domain check lives in findDomainServingFrom().
     */
    private function deletableWebRootPath(Domain $domain): ?string
    {
        if ($domain->isCatchall()) {
            Log::info("Skipped web root removal for {$domain->fqdn}: catch-all uses the shared wildcard directory.");

            return null;
        }

        $reserved = array_map('strtolower', config('panel.system_reserved_domains', []));
        if (in_array(strtolower($domain->fqdn), $reserved, true)) {
            Log::warning("Refusing to remove web root for system-reserved domain: {$domain->fqdn}");

            return null;
        }

        $basePath = $domain->getBasePath();
        $vhostRoot = '/var/www/vhosts';

        // Never let a malformed path turn this into a recursive delete of the whole
        // vhost root or of anything outside it.
        if (str_contains($basePath, '..')
            || rtrim($basePath, '/') === $vhostRoot
            || ! str_starts_with($basePath, $vhostRoot.'/')
        ) {
            Log::warning("Refusing to remove unexpected web root path for {$domain->fqdn}: {$basePath}");

            return null;
        }

        return $basePath;
    }

    /**
     * Find a surviving domain whose files live inside the directory about to be deleted.
     *
     * `sharesWebRootWithParent()` only ever compares a domain with its own parent, so
     * it cannot see two other real cases:
     *
     * - an addon domain bound through `linked_domain_id`, whose web root resolves to
     *   this domain's tree (the FK is nullOnDelete, so nothing blocks the delete);
     * - the slug collision between a wildcard subdomain `*.example.com` and a literal
     *   `wildcard.example.com`, which `getSubdomainSlug()` maps to the same directory.
     *
     * In both cases the other domain is still enabled and still has its vhost
     * deployed, so deleting the directory would take a live site down permanently.
     * Subdomains of the domain being deleted are already removed from the database by
     * `deleteSubdomains()` before this runs, so they never appear here.
     */
    private function findDomainServingFrom(Domain $domain, string $basePath): ?Domain
    {
        $target = rtrim($basePath, '/');

        return Domain::query()
            ->whereKeyNot($domain->getKey())
            ->with(['parentDomain', 'linkedDomain.parentDomain'])
            ->get()
            ->first(function (Domain $other) use ($target): bool {
                foreach ([$other->getBasePath(), $other->getWebRootPath()] as $path) {
                    $path = rtrim($path, '/');

                    if ($path === $target || str_starts_with($path, $target.'/')) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Remove every certificate artefact belonging to the domain.
     *
     * Cleans up:
     * - /etc/letsencrypt/live|archive/{fqdn} and renewal/{fqdn}.conf (legacy certbot)
     * - /etc/letsencrypt/custom/{fqdn}      (where SslCertificateService writes the
     *   private keys the panel actually serves — this was never cleaned, so every
     *   deleted domain left its privkey.pem on disk forever)
     * - /etc/letsencrypt/selfsigned/{fqdn}  (bootstrap cert from ProvisionDomainJob)
     * - /etc/letsencrypt/csr/{fqdn}
     */
    private function removeCertificateFiles(string $fqdn): void
    {
        foreach (['letsencrypt_custom_base', 'letsencrypt_selfsigned_base', 'letsencrypt_csr_base'] as $configKey) {
            $base = (string) config("panel.{$configKey}");

            if ($base === '') {
                continue;
            }

            $path = rtrim($base, '/')."/{$fqdn}";
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
                Log::info("Removed certificate directory: {$path}");
            }
        }

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

    /**
     * Unlock immutable .user.ini files in the domain's base path.
     *
     * .user.ini files are locked with chattr +i to prevent site owners from
     * modifying open_basedir restrictions. Before deletion, we must remove
     * the immutable flag so the directory can be cleaned up.
     */
    private function unlockUserIniFiles(Domain $domain): void
    {
        $basePath = escapeshellarg($domain->getBasePath());
        $container = (string) config('panel.frankenphp_container', 'frankenphp');

        try {
            $portainer = app(PortainerService::class);
            $portainer->execInContainer($container, [
                'sh', '-c', "find {$basePath} -name '.user.ini' -exec chattr -i {} \\; 2>/dev/null || true",
            ]);

            Log::info("Unlocked .user.ini files in {$domain->getBasePath()} for {$domain->fqdn}");
        } catch (\Exception $e) {
            Log::warning("Failed to unlock .user.ini for {$domain->fqdn}: {$e->getMessage()}");
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
