<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreCloudflareFirewallRuleRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\CloudflareDnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CloudflareController extends ApiController
{
    private const SETTINGS = [
        'security_level', 'ssl', 'always_use_https', 'automatic_https_rewrites',
        'min_tls_version', 'tls_1_3', 'browser_cache_ttl', 'development_mode',
        'websockets', 'ip_geolocation', 'http3', 'early_hints',
    ];

    public function __construct(private readonly CloudflareDnsService $cloudflare) {}

    public function index(Domain $domain): JsonResponse
    {
        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));

        return response()->json(['data' => $zone]);
    }

    public function summary(Domain $domain): JsonResponse
    {
        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));

        return response()->json(['data' => $zone]);
    }

    public function settings(Domain $domain): JsonResponse
    {
        $fqdn = $this->targetFqdn($domain);
        $zone = $this->cloudflare->getZoneSummary($fqdn);
        $settings = [];

        if (($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null)) {
            $raw = $this->cloudflare->getZoneSettings($zone['zone_id'], self::SETTINGS);
            foreach ($raw as $item) {
                $settings[$item['id']] = $item['value'];
            }
        }

        return response()->json(['data' => ['zone' => $zone, 'settings' => $settings]]);
    }

    public function dnssecStatus(Domain $domain): JsonResponse
    {
        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));
        $status = null;

        if (($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null)) {
            $status = $this->cloudflare->getDnssecStatus($zone['zone_id']);
        }

        return response()->json(['data' => $status]);
    }

    public function firewallRules(Domain $domain): JsonResponse
    {
        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));
        $rules = [];

        if (($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null)) {
            $rules = $this->cloudflare->listFirewallRules($zone['zone_id']);
        }

        return response()->json(['data' => $rules]);
    }

    public function sync(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        $fqdn = $this->targetFqdn($domain);
        $zone = $this->cloudflare->getZoneSummary($fqdn);
        $exists = (bool) ($zone['exists'] ?? false);
        $created = false;

        if (! $exists) {
            try {
                $this->cloudflare->ensureZoneExists($fqdn);
                $created = true;
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $zone = $this->cloudflare->getZoneSummary($fqdn);
            $exists = (bool) ($zone['exists'] ?? false);
        }

        $domain->update(['dns_provider' => $exists ? 'cloudflare' : 'local']);

        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cloudflare_sync', 'domain_id' => $domain->id, 'summary' => $fqdn, 'ip_address' => $request->ip()]);

        return response()->json(['message' => $created ? __('Domain added to Cloudflare successfully.') : __('Cloudflare status synchronized.'), 'data' => $zone]);
    }

    public function add(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        $this->cloudflare->ensureZoneExists($this->targetFqdn($domain));

        return response()->json(['message' => __('Zone added to Cloudflare.')]);
    }

    public function purgeCache(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));
        abort_unless(($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null), 422, 'Zone not on Cloudflare.');

        $this->cloudflare->purgeZoneCache($zone['zone_id']);

        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cloudflare_cache_purged', 'domain_id' => $domain->id, 'summary' => $domain->fqdn, 'ip_address' => $request->ip()]);

        return response()->json(['message' => __('Cache purged.')]);
    }

    public function updateSetting(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate(['setting' => 'required|string', 'value' => 'required']);
        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));
        abort_unless(($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null), 422, 'Zone not on Cloudflare.');

        $this->cloudflare->updateZoneSetting($zone['zone_id'], $validated['setting'], $validated['value']);

        return response()->json(['message' => __('Setting updated.')]);
    }

    public function toggleDnssec(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate(['enabled' => 'required|boolean']);
        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));
        abort_unless(($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null), 422, 'Zone not on Cloudflare.');

        $this->cloudflare->updateDnssecStatus($zone['zone_id'], $validated['enabled'] ? 'active' : 'disabled');

        return response()->json(['message' => __('DNSSEC updated.')]);
    }

    public function storeFirewallRule(StoreCloudflareFirewallRuleRequest $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));
        abort_unless(($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null), 422, 'Zone not on Cloudflare.');

        $rule = $this->cloudflare->createFirewallRule($zone['zone_id'], $request->validated()['description'] ?? '', $request->validated()['expression'] ?? '', $request->validated()['action'] ?? 'block');

        return response()->json(['data' => $rule], 201);
    }

    public function destroyFirewallRule(Request $request, Domain $domain, string $ruleId): Response
    {
        $this->ensureAdmin($request);

        $zone = $this->cloudflare->getZoneSummary($this->targetFqdn($domain));
        abort_unless(($zone['exists'] ?? false) && is_string($zone['zone_id'] ?? null), 422, 'Zone not on Cloudflare.');

        $this->cloudflare->deleteFirewallRule($zone['zone_id'], $ruleId);

        return response()->noContent();
    }

    private function targetFqdn(Domain $domain): string
    {
        return $domain->parentDomain?->fqdn ?? $domain->fqdn;
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}
