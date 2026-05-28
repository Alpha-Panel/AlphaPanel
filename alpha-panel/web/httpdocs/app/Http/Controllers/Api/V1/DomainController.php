<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MailHosting;
use App\Jobs\DeleteDomainJob;
use App\Jobs\ProvisionDomainJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\CloudflareDnsService;
use App\Services\FtpUserService;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class DomainController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Domain::query()
            ->with(['owner:id,name,email', 'phpVersion:id,slug'])
            ->when(! $user->isAdmin(), function ($q) use ($user): void {
                $q->where(function ($q) use ($user): void {
                    $q->where('owner_user_id', $user->id)
                        ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
                });
            })
            ->when($request->boolean('root_only'), fn ($q) => $q->whereNull('parent_domain_id'))
            ->orderBy('fqdn');

        return response()->json($this->paginate($query));
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'fqdn' => 'required|string|max:255|unique:domains,fqdn',
            'type' => 'required|string|in:legacy,modern',
            'owner_user_id' => 'nullable|integer|exists:users,id',
            'parent_domain_id' => 'nullable|integer|exists:domains,id',
            'root_path' => 'nullable|string|max:500',
            'php_version_id' => 'nullable|integer|exists:php_versions,id',
            'dns_provider' => 'nullable|string|in:local,cloudflare,none',
            'enable_www_redirect' => 'boolean',
            'ftp_username' => 'nullable|string|max:100',
            'ftp_password' => 'nullable|string|min:8',
            'mail_hosting' => ['nullable', 'string', Rule::in($this->allowedMailHostingValues())],
            'mail_remote_mx_host' => 'nullable|string|max:255|required_if:mail_hosting,remote',
            'mail_remote_mx_priority' => 'nullable|integer|between:0,65535',
        ]);

        $validated['owner_user_id'] = $validated['owner_user_id'] ?? $request->user()->id;
        $validated['modsecurity_enabled'] = true;
        $validated['modsecurity_mode'] = 'detection_only';
        $ftpUsername = $validated['ftp_username'] ?? null;
        $ftpPassword = $validated['ftp_password'] ?? null;
        unset($validated['ftp_username'], $validated['ftp_password']);

        $domain = Domain::create($validated);

        if ($ftpUsername && $ftpPassword) {
            app(FtpUserService::class)->createForDomain($domain, $ftpUsername, $ftpPassword);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'domain_created',
            'domain_id' => $domain->id,
            'summary' => $domain->fqdn,
        ]);

        return response()->json(['data' => $domain->fresh(['owner', 'phpVersion'])], 201);
    }

    public function show(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureCanViewDomain($request, $domain);

        $domain->load(['owner:id,name,email', 'phpVersion:id,version', 'ftpUser:id,username,domain_id', 'activeSslCertificate', 'subdomains:id,fqdn,type,status,parent_domain_id,php_version_id']);

        return response()->json(['data' => $domain]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureCanManageDomain($request, $domain);

        $validated = $request->validate([
            'type' => 'sometimes|string|in:legacy,modern',
            'status' => 'sometimes|string|in:active,disabled,pending_cert,failed',
            'root_path' => 'nullable|string|max:500',
            'php_version_id' => 'nullable|integer|exists:php_versions,id',
            'dns_provider' => 'nullable|string|in:local,cloudflare,none',
            'enable_www_redirect' => 'boolean',
            'additional_hostnames' => 'nullable|array',
            'additional_hostnames.*' => 'string',
            'enable_worker' => 'boolean',
            'worker_num' => 'nullable|integer|min:1|max:256',
            'custom_caddy_directives' => 'nullable|string',
            'cors_enabled' => 'boolean',
            'cors_allowed_origins' => 'nullable|string',
            'bypass_reverse_proxy' => 'boolean',
            'mail_hosting' => ['nullable', 'string', Rule::in($this->allowedMailHostingValues())],
            'mail_remote_mx_host' => 'nullable|string|max:255|required_if:mail_hosting,remote',
            'mail_remote_mx_priority' => 'nullable|integer|between:0,65535',
        ]);

        $previousMailHosting = $domain->mail_hosting;
        $domain->update($validated);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'domain_updated',
            'domain_id' => $domain->id,
            'summary' => $domain->fqdn,
        ]);

        if ($domain->wasChanged(['mail_hosting', 'mail_remote_mx_host', 'mail_remote_mx_priority'])) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'domain_mail_hosting_changed',
                'domain_id' => $domain->id,
                'summary' => $domain->fqdn.': '.($previousMailHosting?->value ?? 'null').' → '.($domain->mail_hosting?->value ?? 'null'),
            ]);
        }

        return response()->json(['data' => $domain->fresh()]);
    }

    public function destroy(Request $request, Domain $domain): Response
    {
        $this->ensureAdmin($request);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'domain_deleted',
            'summary' => $domain->fqdn,
        ]);

        dispatch(new DeleteDomainJob($domain));

        return response()->noContent();
    }

    public function provision(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        dispatch(new ProvisionDomainJob($domain));

        return response()->json(['message' => __('Provisioning started.')]);
    }

    public function updateFtp(Request $request, Domain $domain, FtpUserService $ftpUserService): JsonResponse
    {
        $this->ensureCanManageDomain($request, $domain);

        $validated = $request->validate([
            'username' => 'required|string|max:100',
            'password' => 'nullable|string|min:8',
        ]);

        $ftpUser = $domain->ftpUser;

        if ($ftpUser) {
            $ftpUserService->updateUsername($ftpUser, $validated['username']);
            if (! empty($validated['password'])) {
                $ftpUserService->updatePassword($ftpUser, $validated['password']);
            }
        } else {
            $ftpUserService->createForDomain($domain, $validated['username'], $validated['password'] ?? '');
        }

        return response()->json(['data' => $domain->fresh('ftpUser')->ftpUser]);
    }

    public function fixFtpPermissions(Request $request, Domain $domain, FtpUserService $ftpUserService): JsonResponse
    {
        $this->ensureCanManageDomain($request, $domain);

        $ftpUserService->fixPermissions($domain);

        return response()->json(['message' => __('FTP permissions fixed.')]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = (string) $request->input('q', '');
        $user = $request->user();

        $results = Domain::query()
            ->when($q !== '', fn ($query) => $query->where('fqdn', 'like', "%{$q}%"))
            ->when(! $user->isAdmin(), function ($query) use ($user): void {
                $query->where(function ($q) use ($user): void {
                    $q->where('owner_user_id', $user->id)
                        ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
                });
            })
            ->orderBy('fqdn')
            ->limit(20)
            ->get(['id', 'fqdn', 'type', 'status', 'parent_domain_id']);

        return response()->json(['data' => $results]);
    }

    public function underAttackStatuses(Request $request, CloudflareDnsService $cloudflare): JsonResponse
    {
        $user = $request->user();

        $domains = Domain::query()
            ->when(! $user->isAdmin(), function ($q) use ($user): void {
                $q->where(function ($q) use ($user): void {
                    $q->where('owner_user_id', $user->id)
                        ->orWhereHas('authorizedUsers', fn ($q) => $q->where('user_id', $user->id));
                });
            })
            ->where('dns_provider', 'cloudflare')
            ->get(['id', 'fqdn']);

        $statuses = $domains->map(function (Domain $domain) use ($cloudflare): array {
            $underAttack = Cache::remember(
                "dashboard:under-attack:{$domain->id}",
                now()->addMinutes(2),
                function () use ($domain, $cloudflare): ?bool {
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
                }
            );

            return [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
                'under_attack' => $underAttack,
            ];
        });

        return response()->json(['data' => $statuses]);
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403, 'Admin access required.');
        }
    }

    private function ensureCanViewDomain(Request $request, Domain $domain): void
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return;
        }
        if ($domain->owner_user_id === $user->id) {
            return;
        }
        if ($domain->authorizedUsers()->where('user_id', $user->id)->exists()) {
            return;
        }
        abort(403);
    }

    private function ensureCanManageDomain(Request $request, Domain $domain): void
    {
        $this->ensureCanViewDomain($request, $domain);
    }

    /**
     * @return list<string> Mail hosting values the panel currently accepts.
     */
    private function allowedMailHostingValues(): array
    {
        return array_map(
            fn (MailHosting $h) => $h->value,
            app(MailSettingsService::class)->availableHostings(),
        );
    }
}
