<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCloudflareFirewallRuleRequest;
use App\Http\Requests\UpdateCloudflareDnssecRequest;
use App\Http\Requests\UpdateCloudflareSettingRequest;
use App\Models\Domain;
use App\Services\CloudflareDnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DomainCloudflareController extends Controller
{
    private const SETTINGS = [
        'security_level',
        'ssl',
        'always_use_https',
        'automatic_https_rewrites',
        'min_tls_version',
        'tls_1_3',
        'browser_cache_ttl',
        'development_mode',
        'websockets',
        'ip_geolocation',
        'opportunistic_onion',
        'http3',
        'early_hints',
        'security_header',
    ];

    public function manage(Request $request, Domain $domain, CloudflareDnsService $cloudflare): Response
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        return Inertia::render('Domains/Cloudflare', [
            'domain' => [
                'id' => $targetDomain->id,
                'fqdn' => $targetDomain->fqdn,
                'cloudflare_enabled' => $targetDomain->cloudflare_enabled,
            ],
            'cloudflare_zone' => $zoneSummary,
        ]);
    }

    public function summary(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        return response()->json(
            $this->buildBasePayload($targetDomain, $zoneSummary),
        );
    }

    public function settings(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $settings = [];

        if (($zoneSummary['exists'] ?? false) && is_string($zoneSummary['zone_id'])) {
            $rawSettings = $cloudflare->getZoneSettings($zoneSummary['zone_id'], self::SETTINGS);
            $settings = $this->normalizeSettings($rawSettings);
        }

        $settings['under_attack'] = ($settings['security_level'] ?? null) === 'under_attack';

        return response()->json([
            ...$this->buildBasePayload($targetDomain, $zoneSummary),
            'settings' => $settings,
        ]);
    }

    public function dnssecStatus(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $dnssec = null;

        if (($zoneSummary['exists'] ?? false) && is_string($zoneSummary['zone_id'])) {
            $dnssec = $cloudflare->getDnssecStatus($zoneSummary['zone_id']);
        }

        return response()->json([
            ...$this->buildBasePayload($targetDomain, $zoneSummary),
            'dnssec' => $dnssec,
        ]);
    }

    public function firewallRules(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $firewallRules = [];

        if (($zoneSummary['exists'] ?? false) && is_string($zoneSummary['zone_id'])) {
            $firewallRules = $cloudflare->listFirewallRules($zoneSummary['zone_id']);
        }

        return response()->json([
            ...$this->buildBasePayload($targetDomain, $zoneSummary),
            'firewall_rules' => $firewallRules,
        ]);
    }

    public function status(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        $settings = [];
        $dnssec = null;
        $firewallRules = [];

        if (($zoneSummary['exists'] ?? false) && is_string($zoneSummary['zone_id'])) {
            $zoneId = $zoneSummary['zone_id'];
            $rawSettings = $cloudflare->getZoneSettings($zoneId, self::SETTINGS);
            $settings = $this->normalizeSettings($rawSettings);
            $dnssec = $cloudflare->getDnssecStatus($zoneId);
            $firewallRules = $cloudflare->listFirewallRules($zoneId);
        }

        $settings['under_attack'] = ($settings['security_level'] ?? null) === 'under_attack';

        return response()->json([
            ...$this->buildBasePayload($targetDomain, $zoneSummary),
            'settings' => $settings,
            'dnssec' => $dnssec,
            'firewall_rules' => $firewallRules,
        ]);
    }

    public function sync(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $exists = (bool) ($zoneSummary['exists'] ?? false);
        $zoneCreatedBySync = false;

        if (! $exists) {
            try {
                $cloudflare->ensureZoneExists($targetDomain->fqdn);
                $zoneCreatedBySync = true;
            } catch (\Throwable $exception) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('Cloudflare zone could not be added: :message', ['message' => $exception->getMessage()]),
                ], 422);
            }

            $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
            $exists = (bool) ($zoneSummary['exists'] ?? false);
        }

        $targetDomain->update([
            'cloudflare_enabled' => $exists,
        ]);

        if ($zoneCreatedBySync && $exists) {
            $message = __('Domain added to Cloudflare successfully.');
        } elseif ($exists) {
            $message = __('Cloudflare status synchronized. Domain exists on Cloudflare.');
        } else {
            $message = __('Cloudflare status synchronized. Domain is not on Cloudflare.');
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'created_by_sync' => $zoneCreatedBySync,
            'cloudflare_enabled' => $targetDomain->cloudflare_enabled,
            'zone' => $zoneSummary,
        ]);
    }

    public function add(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);

        try {
            $cloudflare->ensureZoneExists($targetDomain->fqdn);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone could not be added: :message', ['message' => $exception->getMessage()]),
            ], 422);
        }

        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $targetDomain->update([
            'cloudflare_enabled' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => __('Domain added to Cloudflare successfully.'),
            'cloudflare_enabled' => true,
            'zone' => $zoneSummary,
        ]);
    }

    public function purgeCache(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $purged = $cloudflare->purgeZoneCache($zoneSummary['zone_id']);
        if (! $purged) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare cache purge failed.'),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => __('Cloudflare cache purge started.'),
        ]);
    }

    public function updateSetting(
        UpdateCloudflareSettingRequest $request,
        Domain $domain,
        CloudflareDnsService $cloudflare,
    ): JsonResponse {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $validated = $request->validated();
        $setting = (string) $validated['setting'];
        $value = $validated['value'] ?? null;

        if ($setting === 'under_attack') {
            $setting = 'security_level';
            $value = (bool) $value ? 'under_attack' : 'medium';
        } elseif ($setting === 'security_header') {
            $value = [
                'enabled' => (bool) ($value['enabled'] ?? false),
                'max_age' => max((int) ($value['max_age'] ?? 0), 0),
                'include_subdomains' => (bool) ($value['include_subdomains'] ?? false),
                'preload' => (bool) ($value['preload'] ?? false),
                'nosniff' => (bool) ($value['nosniff'] ?? false),
            ];
        } elseif ($setting === 'browser_cache_ttl') {
            $value = (int) $value;
        } elseif (is_bool($value)) {
            $value = $value ? 'on' : 'off';
        }

        $updatedSetting = $cloudflare->updateZoneSetting($zoneSummary['zone_id'], $setting, $value);
        if (! is_array($updatedSetting)) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare setting could not be updated.'),
            ], 422);
        }

        if ($setting === 'security_level') {
            Cache::forget("dashboard:under-attack:{$domain->id}");
        }

        return response()->json([
            'status' => 'success',
            'message' => __('Cloudflare setting updated successfully.'),
            'setting' => $setting,
            'value' => $updatedSetting['value'] ?? null,
        ]);
    }

    public function updateDnssec(
        UpdateCloudflareDnssecRequest $request,
        Domain $domain,
        CloudflareDnsService $cloudflare,
    ): JsonResponse {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $result = $cloudflare->updateDnssecStatus($zoneSummary['zone_id'], (string) $request->validated('status'));
        if (! is_array($result)) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare DNSSEC setting could not be updated.'),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => __('Cloudflare DNSSEC updated successfully.'),
            'dnssec' => $result,
        ]);
    }

    public function storeFirewallRule(
        StoreCloudflareFirewallRuleRequest $request,
        Domain $domain,
        CloudflareDnsService $cloudflare,
    ): JsonResponse {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $validated = $request->validated();
        $created = $cloudflare->createFirewallRule(
            $zoneSummary['zone_id'],
            (string) $validated['expression'],
            (string) $validated['action'],
            $validated['description'] ?? null,
            isset($validated['priority']) ? (int) $validated['priority'] : null,
        );

        if (! $created) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare firewall rule could not be created.'),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => __('Cloudflare firewall rule created successfully.'),
        ]);
    }

    public function deleteFirewallRule(
        Request $request,
        Domain $domain,
        string $ruleId,
        CloudflareDnsService $cloudflare,
    ): JsonResponse {
        $this->authorize('view', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $deleted = $cloudflare->deleteFirewallRule($zoneSummary['zone_id'], $ruleId);
        if (! $deleted) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare firewall rule could not be deleted.'),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => __('Cloudflare firewall rule deleted successfully.'),
        ]);
    }

    private function resolveTargetDomain(Domain $domain): Domain
    {
        if (! $domain->isSubdomain()) {
            return $domain;
        }

        $domain->loadMissing('parentDomain');

        return $domain->parentDomain ?? $domain;
    }

    /**
     * @param  array<string, mixed>  $rawSettings
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $rawSettings): array
    {
        $normalized = [];

        foreach ($rawSettings as $setting => $payload) {
            if (! is_array($payload)) {
                $normalized[$setting] = null;

                continue;
            }

            $settingValue = $payload['value'] ?? null;

            if ($setting === 'security_header') {
                $normalized[$setting] = $this->normalizeSecurityHeaderSetting($settingValue);

                continue;
            }

            $normalized[$setting] = $settingValue;
        }

        return $normalized;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     max_age: int,
     *     include_subdomains: bool,
     *     preload: bool,
     *     nosniff: bool
     * }
     */
    private function normalizeSecurityHeaderSetting(mixed $settingValue): array
    {
        $defaultPayload = [
            'enabled' => false,
            'max_age' => 0,
            'include_subdomains' => false,
            'preload' => false,
            'nosniff' => false,
        ];

        if (! is_array($settingValue)) {
            if (is_bool($settingValue) || is_numeric($settingValue) || is_string($settingValue)) {
                $defaultPayload['enabled'] = $this->normalizeCloudflareBoolean($settingValue);
            }

            return $defaultPayload;
        }

        $strictTransportSecurity = $settingValue['strict_transport_security'] ?? $settingValue;
        if (! is_array($strictTransportSecurity)) {
            return $defaultPayload;
        }

        $maxAge = max((int) ($strictTransportSecurity['max_age'] ?? 0), 0);

        return [
            'enabled' => $this->normalizeCloudflareBoolean($strictTransportSecurity['enabled'] ?? false),
            'max_age' => $maxAge,
            'include_subdomains' => $this->normalizeCloudflareBoolean($strictTransportSecurity['include_subdomains'] ?? false),
            'preload' => $this->normalizeCloudflareBoolean($strictTransportSecurity['preload'] ?? false),
            'nosniff' => $this->normalizeCloudflareBoolean($strictTransportSecurity['nosniff'] ?? false),
        ];
    }

    private function normalizeCloudflareBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['on', 'true', '1', 'yes', 'enabled', 'active'], true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $zoneSummary
     * @return array<string, mixed>
     */
    private function buildBasePayload(Domain $targetDomain, array $zoneSummary): array
    {
        $effectiveCloudflareEnabled = $targetDomain->cloudflare_enabled;
        if ($effectiveCloudflareEnabled === null) {
            $effectiveCloudflareEnabled = (bool) ($zoneSummary['exists'] ?? false);
        }

        return [
            'domain_id' => $targetDomain->id,
            'domain_fqdn' => $targetDomain->fqdn,
            'cloudflare_enabled' => $targetDomain->cloudflare_enabled,
            'cloudflare_effective_enabled' => $effectiveCloudflareEnabled,
            'zone' => $zoneSummary,
        ];
    }
}
