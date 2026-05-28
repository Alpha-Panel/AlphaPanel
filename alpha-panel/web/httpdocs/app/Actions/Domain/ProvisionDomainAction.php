<?php

namespace App\Actions\Domain;

use App\Enums\DomainMode;
use App\Jobs\ProvisionDomainJob;
use App\Models\Domain;
use App\Models\User;
use App\Services\CloudflareDnsService;
use App\Services\FtpUserService;
use App\Services\LocalDnsService;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\MailDnsService;
use App\Services\Mail\MailProviderResolver;
use App\Services\ServerNetworkInfoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates everything required to provision a new Domain from validated
 * StoreDomainRequest input: parent resolution, DNS target validation,
 * Cloudflare zone bootstrap, DB row creation, local DNS zone, mail hosting,
 * optional FTP user, and the asynchronous ProvisionDomainJob dispatch.
 *
 * Extracted from DomainController::store to keep the controller thin and to
 * make the provisioning pipeline independently testable.
 */
class ProvisionDomainAction
{
    public function __construct(
        private FtpUserService $ftpUserService,
        private CloudflareDnsService $cloudflareDnsService,
        private LocalDnsService $localDnsService,
        private ServerNetworkInfoService $serverNetworkInfoService,
        private MailProviderResolver $mailProviderResolver,
        private MailDnsService $mailDnsService,
    ) {}

    /**
     * Run the full provisioning pipeline for a single domain.
     *
     * @param  array<string, mixed>  $validated  Output of StoreDomainRequest::validated().
     * @param  User  $owner  The currently authenticated user (used for audit + job dispatch).
     *
     * @throws ProvisionDomainValidationException When post-FormRequest validation fails.
     */
    public function execute(array $validated, User $owner): Domain
    {
        $request = app(Request::class);

        $context = $this->buildContext($validated, $owner);
        $data = $this->normalizeData($validated, $owner, $request, $context);

        $this->ensureCloudflareBootstrap($data, $context);

        $ftpUsername = $data['ftp_username'] ?? null;
        $ftpPassword = $data['ftp_password'] ?? null;
        unset(
            $data['ftp_username'],
            $data['ftp_password'],
            $data['create_dns_record'],
            $data['cloudflare_mode'],
            $data['dns_target_ip'],
            $data['inherit_parent_root_path'],
        );

        $domain = $this->createRecord($data);

        $this->registerLocalDns($domain, $context);
        $this->applyMailHosting($domain);
        $this->createFtpUser($domain, $ftpUsername, $ftpPassword);
        $this->dispatchProvisionJob($domain, $owner, $request, $context);

        return $domain;
    }

    /**
     * Build the immutable provisioning context derived from the request payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array{
     *     parentDomainId: int,
     *     inheritParentRootPath: bool,
     *     cloudflareMode: string,
     *     dnsProvider: string,
     *     requestedSubdomainDnsRecord: bool,
     *     dnsTargetIp: ?string,
     *     dnsTargetScope: ?string,
     *     createDnsRecord: bool,
     *     dnsRecordShouldBeProxied: bool,
     *     isCloudflare: bool,
     *     shouldCreateApexDnsRecords: bool,
     * }
     */
    private function buildContext(array $validated, User $owner): array
    {
        $parentDomainId = (int) ($validated['parent_domain_id'] ?? 0);
        $cloudflareMode = (string) ($validated['cloudflare_mode'] ?? 'skip');
        $dnsProvider = (string) ($validated['dns_provider'] ?? ($cloudflareMode !== 'skip' ? 'cloudflare' : 'local'));

        $dnsTargetIp = isset($validated['dns_target_ip']) ? trim((string) $validated['dns_target_ip']) : '';
        $dnsTargetIp = $dnsTargetIp !== '' ? $dnsTargetIp : null;

        $serverNetworkIps = $this->serverNetworkInfoService->getServerIpAddresses();
        $dnsTargetScope = $dnsTargetIp !== null
            ? $this->resolveDnsTargetScope($dnsTargetIp, $serverNetworkIps)
            : null;

        $isCloudflare = $dnsProvider === 'cloudflare';
        $shouldCreateApexDnsRecords = $parentDomainId === 0 && $isCloudflare && $cloudflareMode === 'add';

        return [
            'parentDomainId' => $parentDomainId,
            'inheritParentRootPath' => (bool) ($validated['inherit_parent_root_path'] ?? false),
            'cloudflareMode' => $cloudflareMode,
            'dnsProvider' => $dnsProvider,
            'requestedSubdomainDnsRecord' => (bool) ($validated['create_dns_record'] ?? false),
            'dnsTargetIp' => $dnsTargetIp,
            'dnsTargetScope' => $dnsTargetScope,
            'createDnsRecord' => false,
            'dnsRecordShouldBeProxied' => false,
            'isCloudflare' => $isCloudflare,
            'shouldCreateApexDnsRecords' => $shouldCreateApexDnsRecords,
        ];
    }

    /**
     * Apply the parent-domain, owner, root-path and DNS-provider rules to the
     * raw validated payload. Mutates $context in-place to record createDnsRecord
     * and dnsRecordShouldBeProxied decisions.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeData(array $validated, User $owner, Request $request, array &$context): array
    {
        $data = $validated;

        // Force fqdn for wildcard catch-all regardless of what was submitted.
        if (($data['mode'] ?? null) === DomainMode::WildcardCatchall->value) {
            $data['fqdn'] = '*';
        }

        if ($context['parentDomainId'] > 0) {
            $parentDomain = Domain::query()
                ->select(['id', 'fqdn', 'owner_user_id', 'dns_provider', 'root_path', 'type'])
                ->findOrFail($context['parentDomainId']);
            // Authorization mirrors the controller's $this->authorize('view', $parentDomain).
            Gate::forUser($owner)->authorize('view', $parentDomain);

            $data['owner_user_id'] = $parentDomain->owner_user_id;

            $requestedRootPath = trim((string) ($data['root_path'] ?? ''));
            if ($context['inheritParentRootPath']) {
                $data['root_path'] = $parentDomain->getWebRootPath();
            } elseif ($requestedRootPath === '') {
                $data['root_path'] = null;
            } else {
                $data['root_path'] = $requestedRootPath;
            }

            $data['dns_provider'] = $parentDomain->dns_provider?->value ?? 'local';
            $parentUsesCloudflare = $parentDomain->usesCloudflare();

            if ($parentUsesCloudflare) {
                $context['createDnsRecord'] = $context['requestedSubdomainDnsRecord'];
            }

            if ($context['createDnsRecord'] && ($context['dnsTargetIp'] === null || $context['dnsTargetScope'] === null)) {
                throw new ProvisionDomainValidationException(
                    'dns_target_ip',
                    __('Selected DNS target IP is not valid for this server.'),
                );
            }

            $context['dnsRecordShouldBeProxied'] = $context['createDnsRecord'] && $context['dnsTargetScope'] === 'public';
        } elseif ($owner->isAdmin() && ! empty($data['owner_user_id'])) {
            // Admin chose an owner — keep submitted value.
        } else {
            $data['owner_user_id'] = $owner->id;
        }

        if ($context['parentDomainId'] === 0) {
            $requestedRootPath = trim((string) ($data['root_path'] ?? ''));
            $data['root_path'] = $requestedRootPath === '' ? null : $requestedRootPath;
        }

        // Addon domains: inherit root_path from linked domain when not explicitly provided.
        if (($data['mode'] ?? null) === DomainMode::Addon->value) {
            $linkedDomainId = $data['linked_domain_id'] ?? null;
            if ($linkedDomainId && ! $request->filled('root_path')) {
                $linkedDomain = Domain::with('linkedDomain')->find($linkedDomainId);
                if ($linkedDomain) {
                    $data['root_path'] = $linkedDomain->getWebRootPath();
                }
            }
        }

        if ($context['parentDomainId'] === 0) {
            $data['dns_provider'] = $context['dnsProvider'];
        }

        $data['modsecurity_enabled'] = true;
        $data['modsecurity_mode'] = 'detection_only';

        return $data;
    }

    /**
     * Validate the DNS target IP and create the Cloudflare zone + bootstrap
     * records when the request asks for apex-level Cloudflare setup.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    private function ensureCloudflareBootstrap(array $data, array $context): void
    {
        if ($context['shouldCreateApexDnsRecords'] && ($context['dnsTargetIp'] === null || $context['dnsTargetScope'] === null)) {
            throw new ProvisionDomainValidationException(
                'dns_target_ip',
                __('Selected DNS target IP is not valid for this server.'),
            );
        }

        if ($context['parentDomainId'] !== 0 || ! $context['isCloudflare'] || $context['cloudflareMode'] !== 'add') {
            return;
        }

        try {
            $this->cloudflareDnsService->ensureZoneExists((string) $data['fqdn']);
        } catch (\Throwable $exception) {
            throw new ProvisionDomainValidationException(
                'cloudflare_mode',
                __('Cloudflare zone could not be added: :message', ['message' => $exception->getMessage()]),
            );
        }

        if (! $context['shouldCreateApexDnsRecords']) {
            return;
        }

        $synced = $this->cloudflareDnsService->syncApexBootstrapRecords(
            (string) $data['fqdn'],
            (string) $context['dnsTargetIp'],
            $context['dnsTargetScope'] === 'public',
        );

        if (! $synced) {
            throw new ProvisionDomainValidationException(
                'dns_target_ip',
                __('Cloudflare DNS records could not be created. Please try again.'),
            );
        }
    }

    /**
     * Persist the Domain row using the normalized attribute array.
     *
     * @param  array<string, mixed>  $data
     */
    private function createRecord(array $data): Domain
    {
        return Domain::create($data);
    }

    /**
     * Create the local DNS zone for apex domains using the local DNS provider.
     *
     * @param  array<string, mixed>  $context
     */
    private function registerLocalDns(Domain $domain, array $context): void
    {
        if ($context['parentDomainId'] !== 0 || ! $domain->usesLocalDns()) {
            return;
        }

        try {
            $this->localDnsService->createZone($domain);
        } catch (\Throwable $e) {
            Log::error("Local DNS zone creation failed for {$domain->fqdn}: {$e->getMessage()}");
        }
    }

    /**
     * Apply provider-side + DNS side effects for a domain's mail hosting choice.
     * Idempotent: re-registering an already-known domain in Mailu or Zimbra is a no-op.
     *
     * NOTE: Duplicated from DomainController::applyMailHosting (used by update()).
     * Per the extraction constraints, shared helpers used by other controller
     * methods are not moved — duplication is preferred over cross-coupling.
     */
    private function applyMailHosting(Domain $domain): void
    {
        try {
            $provider = $this->mailProviderResolver->tryFor($domain);
            $provider?->registerDomain($domain);

            $this->mailDnsService->applyForDomain($domain);
        } catch (MailProviderException $e) {
            Log::warning('mail.hosting.apply_failed', [
                'fqdn' => $domain->fqdn,
                'mail_hosting' => $domain->mail_hosting->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create the optional FTP user when credentials were submitted with the request.
     */
    private function createFtpUser(Domain $domain, ?string $username, ?string $password): void
    {
        if ($username === null || $password === null) {
            return;
        }

        if ($username === '' || $password === '') {
            return;
        }

        $this->ftpUserService->addUser($domain, $username, $password);
    }

    /**
     * Dispatch the queued ProvisionDomainJob with actor metadata.
     *
     * @param  array<string, mixed>  $context
     */
    private function dispatchProvisionJob(Domain $domain, User $owner, Request $request, array $context): void
    {
        ProvisionDomainJob::dispatch(
            $domain,
            triggeredBy: $owner->id,
            createDnsRecord: $context['createDnsRecord'],
            locale: app()->getLocale(),
            dnsTargetIp: $context['createDnsRecord'] ? $context['dnsTargetIp'] : null,
            dnsProxied: $context['dnsRecordShouldBeProxied'],
            actorIpAddress: $request->ip(),
            actorPort: is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        );
    }

    /**
     * Classify a target IP as belonging to the server's public or private
     * network surface (or unknown).
     *
     * @param  array{public: array<int, string>, private: array<int, string>}  $serverNetworkIps
     */
    private function resolveDnsTargetScope(string $dnsTargetIp, array $serverNetworkIps): ?string
    {
        if (in_array($dnsTargetIp, $serverNetworkIps['public'] ?? [], true)) {
            return 'public';
        }

        if (in_array($dnsTargetIp, $serverNetworkIps['private'] ?? [], true)) {
            return 'private';
        }

        return null;
    }
}
