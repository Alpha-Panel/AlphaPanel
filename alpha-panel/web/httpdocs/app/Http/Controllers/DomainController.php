<?php

namespace App\Http\Controllers;

use App\Actions\Domain\ProvisionDomainAction;
use App\Actions\Domain\ProvisionDomainValidationException;
use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Enums\MailHosting;
use App\Enums\NotificationType;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Requests\UpdateDomainRequest;
use App\Jobs\DeleteDomainJob;
use App\Jobs\ProvisionDomainJob;
use App\Jobs\RenameDomainJob;
use App\Jobs\SslActivateJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Models\User;
use App\Notifications\DomainNotification;
use App\Services\CloudflareDnsService;
use App\Services\DomainConfigService;
use App\Services\FtpUserService;
use App\Services\LaravelPackageDetector;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\MailDnsService;
use App\Services\Mail\MailProviderResolver;
use App\Services\PortainerService;
use App\Services\ServerNetworkInfoService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(Request $request, ServerNetworkInfoService $serverNetworkInfoService): Response
    {
        $phpVersions = PhpVersion::where('is_enabled', true)->orderBy('sort_order')->get();
        $users = $request->user()->isAdmin()
            ? User::query()->orderBy('name')->get(['id', 'name', 'email'])
            : collect();
        $serverNetworkIps = $serverNetworkInfoService->getServerIpAddresses();

        return Inertia::render('Domains/Index', [
            'phpVersions' => $phpVersions,
            'users' => $users,
            'server_network_ips' => $serverNetworkIps,
            'wildcardCatchallExists' => Domain::where('fqdn', '*')->exists(),
            'canCreateCatchall' => $request->user()->isAdmin(),
            'linkableDomains' => $request->user()->isAdmin()
                ? Domain::whereIn('mode', [DomainMode::Main->value, DomainMode::Addon->value])
                    ->orderBy('fqdn')
                    ->get(['id', 'fqdn', 'mode', 'root_path'])
                : Domain::where('owner_user_id', $request->user()->id)
                    ->whereIn('mode', [DomainMode::Main->value, DomainMode::Addon->value])
                    ->orderBy('fqdn')
                    ->get(['id', 'fqdn', 'mode', 'root_path']),
        ]);
    }

    public function create(Request $request, ServerNetworkInfoService $serverNetworkInfoService): Response
    {
        $user = $request->user();
        $phpVersions = PhpVersion::where('is_enabled', true)->orderBy('sort_order')->get();
        $serverNetworkIps = $serverNetworkInfoService->getServerIpAddresses();

        $parentDomains = Domain::query()
            ->whereNull('parent_domain_id')
            ->when(! $user->isAdmin(), fn ($q) => $q->where(function ($q) use ($user) {
                $q->where('owner_user_id', $user->id)
                    ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
            }))
            ->orderBy('fqdn')
            ->get();

        $users = $user->isAdmin() ? User::orderBy('name')->get() : collect();

        return Inertia::render('Domains/Create', [
            'phpVersions' => $phpVersions,
            'parentDomains' => $parentDomains,
            'users' => $users,
            'server_network_ips' => $serverNetworkIps,
            'wildcardCatchallExists' => Domain::where('fqdn', '*')->exists(),
            'canCreateCatchall' => $request->user()->isAdmin(),
            'linkableDomains' => $request->user()->isAdmin()
                ? Domain::whereIn('mode', [DomainMode::Main->value, DomainMode::Addon->value])
                    ->orderBy('fqdn')
                    ->get(['id', 'fqdn', 'mode', 'root_path'])
                : Domain::where('owner_user_id', $request->user()->id)
                    ->whereIn('mode', [DomainMode::Main->value, DomainMode::Addon->value])
                    ->orderBy('fqdn')
                    ->get(['id', 'fqdn', 'mode', 'root_path']),
        ]);
    }

    public function store(
        StoreDomainRequest $request,
        ProvisionDomainAction $provision,
    ): RedirectResponse|JsonResponse {
        try {
            $domain = $provision->execute($request->validated(), $request->user());
        } catch (ProvisionDomainValidationException $exception) {
            return $this->storeValidationErrorResponse(
                $request,
                field: $exception->field,
                message: $exception->userMessage,
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'queued' => true,
                'domain_id' => $domain->id,
                'domain_fqdn' => $domain->fqdn,
            ]);
        }

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', "Domain {$domain->fqdn} created successfully.");
    }

    public function show(
        Request $request,
        Domain $domain,
        CloudflareDnsService $cloudflareDnsService,
        ServerNetworkInfoService $serverNetworkInfoService,
    ): Response|RedirectResponse {
        $this->authorize('view', $domain);

        if ($domain->isSubdomain()) {
            $domain->loadMissing('parentDomain');

            if ($domain->parentDomain) {
                $this->authorize('view', $domain->parentDomain);

                return redirect()->route('domains.show', $domain->parentDomain);
            }
        }

        $domain->load([
            'owner',
            'phpVersion',
            'subdomains',
            'ftpUser',
            'applyRuns' => fn ($q) => $q->latest()->limit(10),
            'managedDatabases.databaseUsers',
        ]);

        $phpVersions = PhpVersion::where('is_enabled', true)->orderBy('sort_order')->get();
        $cloudflareZone = $domain->usesCloudflare()
            ? $cloudflareDnsService->getZoneSummary($domain->getApexDomain())
            : null;
        $serverNetworkIps = $serverNetworkInfoService->getServerIpAddresses();

        return Inertia::render('Domains/Show', [
            'domain' => $domain,
            'phpVersions' => $phpVersions,
            'cloudflare_zone' => $cloudflareZone,
            'server_network_ips' => $serverNetworkIps,
        ]);
    }

    public function edit(Request $request, Domain $domain, LaravelPackageDetector $packageDetector): Response
    {
        $this->authorize('update', $domain);

        $user = $request->user();
        $domain->load('ftpUser');
        $phpVersions = PhpVersion::where('is_enabled', true)->orderBy('sort_order')->get();
        $users = $user->isAdmin() ? User::orderBy('name')->get() : collect();

        return Inertia::render('Domains/Edit', [
            'domain' => $domain,
            'phpVersions' => $phpVersions,
            'users' => $users,
            'octane_configured' => $packageDetector->isOctaneConfigured($domain),
        ]);
    }

    public function update(UpdateDomainRequest $request, Domain $domain): RedirectResponse
    {
        $this->authorize('update', $domain);

        $oldFqdn = $domain->fqdn;
        $oldPhpVersionId = $domain->php_version_id;
        $oldPhpVersion = $domain->phpVersion;
        $validated = $request->validated();

        if ($domain->parent_domain_id !== null) {
            $validated['owner_user_id'] = (int) ($domain->parentDomain()->value('owner_user_id') ?? $domain->owner_user_id);
        } elseif (! $request->user()->isAdmin() || empty($validated['owner_user_id'])) {
            unset($validated['owner_user_id']);
        }

        if (($validated['type'] ?? null) !== 'apache_reverse_proxy') {
            $validated['php_version_id'] = null;
        }

        $previousMailHosting = $domain->mail_hosting;
        $domain->update($validated);
        if ($domain->wasChanged('mail_hosting') || $domain->wasChanged('mail_remote_mx_host') || $domain->wasChanged('mail_remote_mx_priority')) {
            $this->applyMailHosting($domain, previous: $previousMailHosting);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'domain_mail_hosting_changed',
                'domain_id' => $domain->id,
                'summary' => $domain->fqdn.': '.($previousMailHosting?->value ?? 'null').' → '.($domain->mail_hosting?->value ?? 'null'),
            ]);
        }

        // Clean up old FPM config if PHP version changed or domain switched away from Apache
        if ($oldPhpVersionId && $oldPhpVersionId !== $domain->php_version_id && $oldPhpVersion) {
            app(DomainConfigService::class)->removePhpFpmConfig($oldFqdn, $oldPhpVersion);
        }

        $fqdnChanged = $oldFqdn !== $domain->fqdn;

        $configChanged = $fqdnChanged || $domain->wasChanged([
            'type', 'root_path', 'enable_www_redirect', 'additional_hostnames',
            'enable_worker', 'worker_num', 'worker_watch', 'worker_max_requests',
            'forwarded_port', 'php_version_id',
            'ssl_method', 'bypass_reverse_proxy', 'custom_caddy_directives',
            'cors_enabled', 'cors_allowed_origins',
        ]);

        if ($fqdnChanged) {
            RenameDomainJob::dispatch(
                $domain,
                $oldFqdn,
                $request->user()->id,
                app()->getLocale(),
                $request->ip(),
                is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
            );
        } elseif ($configChanged) {
            ProvisionDomainJob::dispatch(
                $domain,
                $request->user()->id,
                false,
                app()->getLocale(),
                actorIpAddress: $request->ip(),
                actorPort: is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
            );
        }

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', "Domain {$domain->fqdn} updated successfully.");
    }

    public function destroy(Request $request, Domain $domain): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $domain);

        $fqdn = $domain->fqdn;
        $deleteDnsRecord = $request->boolean('delete_dns_record');

        DeleteDomainJob::dispatch(
            $domain,
            $request->user()->id,
            app()->getLocale(),
            $deleteDnsRecord,
            $request->ip(),
            is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        );

        if (! $request->header('X-Inertia') && ($request->ajax() || $request->wantsJson())) {
            return response()->json(['message' => __('Domain :fqdn deletion in progress.', ['fqdn' => $fqdn])]);
        }

        return redirect()
            ->route('domains.index')
            ->with('success', __('Domain :fqdn deletion in progress.', ['fqdn' => $fqdn]));
    }

    /**
     * Update FTP user for a domain (password change or create).
     */
    public function updateFtp(Request $request, Domain $domain, FtpUserService $ftpUserService): RedirectResponse
    {
        $this->authorize('manageFtp', $domain);

        $validated = $request->validate([
            'ftp_username' => ['nullable', 'string', 'max:32', 'alpha_dash'],
            'ftp_password' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        $domain->load('ftpUser');

        if ($domain->ftpUser) {
            $ftpUserService->updateUser(
                $domain->ftpUser,
                password: $validated['ftp_password'],
                username: $validated['ftp_username'] ?? null,
            );
            $message = __('FTP password updated successfully.');
        } else {
            $username = $validated['ftp_username'] ?? str_replace('.', '', $domain->fqdn);
            $ftpUserService->addUser($domain, $username, $validated['ftp_password']);
            $message = __('FTP user created successfully.');
        }

        $request->user()->notify(new DomainNotification(
            level: 'success',
            title: __('FTP Updated'),
            body: "{$message} ({$domain->fqdn})",
            domainId: $domain->id,
            url: route('domains.show', $domain),
            icon: 'bx bx-user-check',
            notificationType: NotificationType::FtpChanges,
            actorUserId: $request->user()->id,
        ));

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', $message);
    }

    /**
     * Fix file permissions (chown) for a domain's base directory.
     */
    public function fixPermissions(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('manageFtp', $domain);

        $domain->loadMissing('ftpUser');

        if (! $domain->ftpUser) {
            return response()->json([
                'status' => 'error',
                'message' => __('No FTP user exists for this domain.'),
            ], 422);
        }

        $username = $domain->ftpUser->username;
        $basePath = escapeshellarg($domain->getBasePath());

        $container = $domain->type === DomainType::ApacheReverseProxy
            ? 'php-code-server'
            : 'frankenphp';

        $userIniPath = escapeshellarg("{$domain->getWebRootPath()}/.user.ini");

        try {
            // Unlock .user.ini before bulk chown (immutable flag prevents ownership change)
            $portainer->execInContainer(
                $container,
                ['sh', '-c', "chattr -i {$userIniPath} 2>/dev/null || true"],
            );

            $result = $portainer->execInContainer(
                $container,
                ['sh', '-c', "chown {$username}:www-data -R {$basePath}"],
                300,
            );

            if (! $result->isSuccessful()) {
                $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);
                if ($error === '') {
                    $error = 'Unknown error.';
                }

                throw new \RuntimeException($error);
            }

            // Relock .user.ini — root-owned and immutable so site owner cannot tamper
            $portainer->execInContainer(
                $container,
                ['sh', '-c', "chown root:root {$userIniPath} && chmod 444 {$userIniPath} && chattr +i {$userIniPath} 2>/dev/null || true"],
            );

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'ftp_permissions_fixed',
                'domain_id' => $domain->id,
                'summary' => "chown {$username}:www-data -R on {$domain->getBasePath()}",
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('File permissions fixed successfully.'),
            ]);
        } catch (\Throwable $exception) {
            Log::error("Fix permissions failed for {$domain->fqdn}: {$exception->getMessage()}");

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'ftp_permissions_fix_failed',
                'domain_id' => $domain->id,
                'summary' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to fix file permissions: :error', ['error' => $exception->getMessage()]),
            ], 500);
        }
    }

    /**
     * Activate or renew SSL certificate for a domain.
     *
     * @deprecated Use SslCertificateController::storeLetsEncrypt() instead.
     */
    public function sslActivate(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_queued',
            'domain_id' => $domain->id,
            'summary' => "SSL certificate operation queued for {$domain->fqdn}.",
        ]);

        SslActivateJob::dispatch(
            $domain,
            $request->user()->id,
            app()->getLocale(),
            $request->ip(),
            is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        );

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', __('SSL certificate operation started. You will be notified when complete.'));
    }

    /**
     * Lightweight search endpoint for global domain lookup in header.
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = trim((string) $request->input('q', ''));
        $limit = max(1, min((int) $request->input('limit', 10), 20));

        if ($query === '') {
            return response()->json(['data' => []]);
        }

        $domains = null;

        try {
            $builder = Domain::search($query);

            if (! $user->isAdmin()) {
                $builder->where('owner_user_id', $user->id);
            }

            $domains = $builder->take($limit)->get();
        } catch (\Throwable $e) {
            Log::warning('Meilisearch search failed in top bar, falling back to SQL LIKE', [
                'error' => $e->getMessage(),
            ]);
        }

        if ($domains === null) {
            $domains = Domain::query()
                ->select(['id', 'fqdn', 'type', 'status', 'parent_domain_id'])
                ->when(! $user->isAdmin(), fn ($builder) => $builder->where(function ($q) use ($user) {
                    $q->where('owner_user_id', $user->id)
                        ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
                }))
                ->where('fqdn', 'like', "%{$query}%")
                ->orderByRaw(
                    'CASE WHEN fqdn = ? THEN 0 WHEN fqdn LIKE ? THEN 1 ELSE 2 END',
                    [$query, "{$query}%"],
                )
                ->orderBy('fqdn')
                ->limit($limit)
                ->get();
        }

        $data = $domains->map(fn (Domain $domain) => [
            'id' => $domain->id,
            'fqdn' => $domain->fqdn,
            'type' => $domain->type->value,
            'type_label' => $domain->type->label(),
            'status' => $domain->status->value,
            'status_label' => $domain->status->label(),
            'is_subdomain' => $domain->isSubdomain(),
            'show_url' => route('domains.show', $domain->parent_domain_id ?: $domain->id),
        ]);

        return response()->json(['data' => $data]);
    }

    public function underAttackStatuses(Request $request): JsonResponse
    {
        $user = $request->user();
        $rawDomainIds = $request->input('domain_ids', []);

        if (is_string($rawDomainIds)) {
            $rawDomainIds = explode(',', $rawDomainIds);
        }

        if (! is_array($rawDomainIds)) {
            $rawDomainIds = [];
        }

        $domainIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): int => (int) $value, $rawDomainIds),
            static fn (int $value): bool => $value > 0,
        )));

        $domainIds = array_slice($domainIds, 0, 100);

        if ($domainIds === []) {
            return response()->json(['data' => []]);
        }

        $statusMap = [];
        foreach ($domainIds as $domainId) {
            $statusMap[$domainId] = null;
        }

        $domains = Domain::query()
            ->select(['id', 'fqdn', 'dns_provider'])
            ->whereNull('parent_domain_id')
            ->whereIn('id', $domainIds)
            ->when(! $user->isAdmin(), fn ($query) => $query->where('owner_user_id', $user->id))
            ->get();

        $cloudflare = app(CloudflareDnsService::class);
        $underAttackMap = $this->getUnderAttackStatuses($domains, $cloudflare);

        foreach ($underAttackMap as $domainId => $status) {
            $statusMap[(int) $domainId] = $status;
        }

        return response()->json([
            'data' => $statusMap,
        ]);
    }

    /**
     * JSON endpoint for DataTables (server-side processing).
     */
    public function json(Request $request): JsonResponse
    {
        $user = $request->user();
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $searchValue = $request->input('search.value', '');

        $columnMap = ['fqdn', 'type', 'status', 'php_version', 'worker', 'created_at', 'actions'];

        $totalQuery = Domain::query()
            ->whereNull('parent_domain_id')
            ->when(! $user->isAdmin(), fn ($q) => $q->where(function ($q) use ($user) {
                $q->where('owner_user_id', $user->id)
                    ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
            }));
        $recordsTotal = $totalQuery->count();

        if ($searchValue !== '') {
            $ids = null;

            try {
                $builder = Domain::search($searchValue);

                if (! $user->isAdmin()) {
                    $builder->where('owner_user_id', $user->id);
                }

                $searchResults = $builder->take(10000)->get();
                $ids = $searchResults->pluck('id')->toArray();
            } catch (\Throwable $e) {
                Log::warning('Meilisearch search failed, falling back to SQL LIKE', [
                    'error' => $e->getMessage(),
                ]);
            }

            if ($ids !== null) {
                $recordsFiltered = count($ids);

                if (empty($ids)) {
                    return response()->json([
                        'draw' => $draw,
                        'recordsTotal' => $recordsTotal,
                        'recordsFiltered' => 0,
                        'data' => [],
                    ]);
                }

                $query = Domain::query()
                    ->with(['owner', 'phpVersion'])
                    ->whereIn('id', $ids);

                $orderColumn = (int) $request->input('order.0.column', 0);
                $orderDir = $request->input('order.0.dir', 'asc') === 'desc' ? 'desc' : 'asc';

                if (isset($columnMap[$orderColumn]) && in_array($columnMap[$orderColumn], ['fqdn', 'type', 'status', 'created_at'])) {
                    $query->orderBy($columnMap[$orderColumn], $orderDir);
                } else {
                    $query->orderByRaw('FIELD(id, '.implode(',', $ids).')');
                }
            } else {
                $query = Domain::query()
                    ->with(['owner', 'phpVersion'])
                    ->whereNull('parent_domain_id')
                    ->where(function ($q) use ($searchValue) {
                        $q->where('fqdn', 'like', "%{$searchValue}%")
                            ->orWhereHas('subdomains', fn ($sub) => $sub->where('fqdn', 'like', "%{$searchValue}%"));
                    })
                    ->when(! $user->isAdmin(), fn ($q) => $q->where(function ($q) use ($user) {
                        $q->where('owner_user_id', $user->id)
                            ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
                    }));

                $recordsFiltered = $query->count();

                $orderColumn = (int) $request->input('order.0.column', 0);
                $orderDir = $request->input('order.0.dir', 'asc') === 'desc' ? 'desc' : 'asc';

                if (isset($columnMap[$orderColumn]) && in_array($columnMap[$orderColumn], ['fqdn', 'type', 'status', 'created_at'])) {
                    $query->orderBy($columnMap[$orderColumn], $orderDir);
                } else {
                    $query->latest();
                }
            }
        } else {
            $query = Domain::query()
                ->with(['owner', 'phpVersion'])
                ->whereNull('parent_domain_id')
                ->when(! $user->isAdmin(), fn ($q) => $q->where(function ($q) use ($user) {
                    $q->where('owner_user_id', $user->id)
                        ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
                }));

            $recordsFiltered = $query->count();

            $orderColumn = (int) $request->input('order.0.column', 0);
            $orderDir = $request->input('order.0.dir', 'asc') === 'desc' ? 'desc' : 'asc';

            if (isset($columnMap[$orderColumn]) && in_array($columnMap[$orderColumn], ['fqdn', 'type', 'status', 'created_at'])) {
                $query->orderBy($columnMap[$orderColumn], $orderDir);
            } else {
                $query->latest();
            }
        }

        $domains = $query->skip($start)->take($length)->get();

        $data = $domains->map(fn (Domain $domain) => [
            'id' => $domain->id,
            'fqdn' => $domain->fqdn,
            'mode' => $domain->mode->value,
            'type' => $domain->type->value,
            'type_label' => $domain->type->label(),
            'status' => $domain->status->value,
            'status_label' => $domain->status->label(),
            'status_badge' => $domain->status->badgeHtml(),
            'type_badge' => $domain->type->badgeHtml(),
            'php_version' => $domain->phpVersion->slug ?? '-',
            'worker' => $domain->type === DomainType::CaddyWebServer
                ? ($domain->enable_worker ? __('Enabled') : __('Disabled'))
                : '-',
            'created_at' => $domain->created_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')) ?? '-',
            'owner_name' => $domain->owner->name ?? '-',
            'cloudflare_enabled' => $domain->usesCloudflare(),
            'under_attack' => null,
            'show_url' => route('domains.show', $domain),
            'edit_url' => route('domains.edit', $domain),
            'destroy_url' => route('domains.destroy', $domain),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * @param  Collection<int, Domain>  $domains
     * @return array<int, bool|null>
     */
    private function getUnderAttackStatuses($domains, CloudflareDnsService $cloudflare): array
    {
        $map = [];

        foreach ($domains as $domain) {
            if (! $domain->usesCloudflare()) {
                $map[$domain->id] = null;

                continue;
            }

            $cacheKey = "dashboard:under-attack:{$domain->id}";

            $map[$domain->id] = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($domain, $cloudflare): ?bool {
                try {
                    $zoneSummary = $cloudflare->getZoneSummary($domain->fqdn);

                    if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'] ?? null)) {
                        return null;
                    }

                    $setting = $cloudflare->getZoneSetting($zoneSummary['zone_id'], 'security_level');

                    return ($setting['value'] ?? null) === 'under_attack';
                } catch (\Throwable) {
                    return null;
                }
            });
        }

        return $map;
    }

    private function storeValidationErrorResponse(
        Request $request,
        string $field,
        string $message,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ], 422);
        }

        return back()
            ->withInput()
            ->withErrors([
                $field => $message,
            ]);
    }

    /**
     * Apply provider-side + DNS side effects for a domain's mail hosting choice.
     * Idempotent: re-registering an already-known domain in Mailu or Zimbra is a no-op.
     */
    private function applyMailHosting(Domain $domain, ?MailHosting $previous = null): void
    {
        $resolver = app(MailProviderResolver::class);
        $dns = app(MailDnsService::class);

        try {
            if ($previous !== null && $previous !== $domain->mail_hosting && $previous->isManaged()) {
                // Tear down the previous provider's registration first.
                $previousDomain = clone $domain;
                $previousDomain->mail_hosting = $previous;
                $previousProvider = $resolver->tryFor($previousDomain);
                $previousProvider?->deregisterDomain($previousDomain);
            }

            $provider = $resolver->tryFor($domain);
            $provider?->registerDomain($domain);

            $dns->applyForDomain($domain);
        } catch (MailProviderException $e) {
            Log::warning('mail.hosting.apply_failed', [
                'fqdn' => $domain->fqdn,
                'mail_hosting' => $domain->mail_hosting->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
