<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCloudflareFirewallRuleRequest;
use App\Http\Requests\UpdateCloudflareDnssecRequest;
use App\Http\Requests\UpdateCloudflareSettingRequest;
use App\Models\AuditLog;
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
        $this->authorize('viewCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        return Inertia::render('Domains/Cloudflare', [
            'domain' => [
                'id' => $targetDomain->id,
                'fqdn' => $targetDomain->fqdn,
                'cloudflare_enabled' => $targetDomain->usesCloudflare(),
            ],
            'cloudflare_zone' => $zoneSummary,
        ]);
    }

    public function summary(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('viewCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        return response()->json(
            $this->buildBasePayload($targetDomain, $zoneSummary),
        );
    }

    public function settings(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('viewCloudflare', $domain);
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
        $this->authorize('viewCloudflare', $domain);
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
        $this->authorize('viewCloudflare', $domain);
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
        $this->authorize('viewCloudflare', $domain);
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
        $this->authorize('manageCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $beforeState = [
            'cloudflare_enabled' => $targetDomain->usesCloudflare(),
            'zone_exists' => (bool) ($zoneSummary['exists'] ?? false),
            'zone_id' => $zoneSummary['zone_id'] ?? null,
        ];
        $exists = (bool) ($zoneSummary['exists'] ?? false);
        $zoneCreatedBySync = false;

        if (! $exists) {
            try {
                $cloudflare->ensureZoneExists($targetDomain->fqdn);
                $zoneCreatedBySync = true;
            } catch (\Throwable $exception) {
                $this->logCloudflareAudit(
                    $request,
                    $targetDomain,
                    'cloudflare_sync_failed',
                    $beforeState,
                    [
                        ...$beforeState,
                        'error' => $exception->getMessage(),
                    ],
                );

                return response()->json([
                    'status' => 'error',
                    'message' => __('Cloudflare zone could not be added: :message', ['message' => $exception->getMessage()]),
                ], 422);
            }

            $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
            $exists = (bool) ($zoneSummary['exists'] ?? false);
        }

        $targetDomain->update([
            'dns_provider' => $exists ? 'cloudflare' : 'local',
        ]);

        $this->logCloudflareAudit(
            $request,
            $targetDomain,
            'cloudflare_sync',
            $beforeState,
            [
                'cloudflare_enabled' => $targetDomain->usesCloudflare(),
                'zone_exists' => $exists,
                'zone_id' => $zoneSummary['zone_id'] ?? null,
                'zone_created_by_sync' => $zoneCreatedBySync,
            ],
        );

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
            'cloudflare_enabled' => $targetDomain->usesCloudflare(),
            'zone' => $zoneSummary,
        ]);
    }

    public function add(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('manageCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $beforeZoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $beforeState = [
            'cloudflare_enabled' => $targetDomain->usesCloudflare(),
            'zone_exists' => (bool) ($beforeZoneSummary['exists'] ?? false),
            'zone_id' => $beforeZoneSummary['zone_id'] ?? null,
        ];

        try {
            $cloudflare->ensureZoneExists($targetDomain->fqdn);
        } catch (\Throwable $exception) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_zone_add_failed',
                $beforeState,
                [
                    ...$beforeState,
                    'error' => $exception->getMessage(),
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone could not be added: :message', ['message' => $exception->getMessage()]),
            ], 422);
        }

        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $targetDomain->update([
            'dns_provider' => 'cloudflare',
        ]);

        $this->logCloudflareAudit(
            $request,
            $targetDomain,
            'cloudflare_zone_add',
            $beforeState,
            [
                'cloudflare_enabled' => true,
                'zone_exists' => (bool) ($zoneSummary['exists'] ?? false),
                'zone_id' => $zoneSummary['zone_id'] ?? null,
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => __('Domain added to Cloudflare successfully.'),
            'cloudflare_enabled' => true,
            'zone' => $zoneSummary,
        ]);
    }

    public function purgeCache(Request $request, Domain $domain, CloudflareDnsService $cloudflare): JsonResponse
    {
        $this->authorize('manageCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);
        $beforeState = [
            'zone_exists' => (bool) ($zoneSummary['exists'] ?? false),
            'zone_id' => $zoneSummary['zone_id'] ?? null,
            'cache_purge' => 'idle',
        ];

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_cache_purge_failed',
                $beforeState,
                [
                    ...$beforeState,
                    'error' => 'zone_not_found',
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $purged = $cloudflare->purgeZoneCache($zoneSummary['zone_id']);
        if (! $purged) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_cache_purge_failed',
                $beforeState,
                [
                    ...$beforeState,
                    'cache_purge' => 'failed',
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare cache purge failed.'),
            ], 422);
        }

        $this->logCloudflareAudit(
            $request,
            $targetDomain,
            'cloudflare_cache_purge',
            $beforeState,
            [
                ...$beforeState,
                'cache_purge' => 'requested',
            ],
        );

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
        $this->authorize('manageCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_setting_update_failed',
                ['zone_exists' => false, 'zone_id' => null],
                ['error' => 'zone_not_found'],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $validated = $request->validated();
        $setting = (string) $validated['setting'];
        $value = $validated['value'] ?? null;
        $requestedSetting = $setting;

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

        $previousSetting = $cloudflare->getZoneSetting($zoneSummary['zone_id'], $setting);
        $beforeState = [
            'setting' => $setting,
            'requested_setting' => $requestedSetting,
            'value' => $previousSetting['value'] ?? null,
        ];

        $updatedSetting = $cloudflare->updateZoneSetting($zoneSummary['zone_id'], $setting, $value);
        if (! is_array($updatedSetting)) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_setting_update_failed',
                $beforeState,
                [
                    'setting' => $setting,
                    'requested_setting' => $requestedSetting,
                    'value' => $value,
                    'error' => 'update_failed',
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare setting could not be updated.'),
            ], 422);
        }

        if ($setting === 'security_level') {
            Cache::forget("dashboard:under-attack:{$domain->id}");
        }

        $this->logCloudflareAudit(
            $request,
            $targetDomain,
            'cloudflare_setting_update',
            $beforeState,
            [
                'setting' => $setting,
                'requested_setting' => $requestedSetting,
                'value' => $updatedSetting['value'] ?? null,
            ],
        );

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
        $this->authorize('manageCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_dnssec_update_failed',
                ['zone_exists' => false, 'zone_id' => null],
                ['error' => 'zone_not_found'],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $previousDnssec = $cloudflare->getDnssecStatus($zoneSummary['zone_id']);
        $requestedStatus = (string) $request->validated('status');
        $beforeState = [
            'status' => $previousDnssec['status'] ?? null,
        ];

        $result = $cloudflare->updateDnssecStatus($zoneSummary['zone_id'], $requestedStatus);
        if (! is_array($result)) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_dnssec_update_failed',
                $beforeState,
                [
                    'status' => $requestedStatus,
                    'error' => 'update_failed',
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare DNSSEC setting could not be updated.'),
            ], 422);
        }

        $this->logCloudflareAudit(
            $request,
            $targetDomain,
            'cloudflare_dnssec_update',
            $beforeState,
            [
                'status' => $result['status'] ?? null,
            ],
        );

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
        $this->authorize('manageCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_firewall_rule_create_failed',
                ['zone_exists' => false, 'zone_id' => null],
                ['error' => 'zone_not_found'],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $validated = $request->validated();
        $beforeRules = $cloudflare->listFirewallRules($zoneSummary['zone_id']);
        $created = $cloudflare->createFirewallRule(
            $zoneSummary['zone_id'],
            (string) $validated['expression'],
            (string) $validated['action'],
            $validated['description'] ?? null,
            isset($validated['priority']) ? (int) $validated['priority'] : null,
        );

        if (! $created) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_firewall_rule_create_failed',
                [
                    'rules_count' => count($beforeRules),
                ],
                [
                    'rules_count' => count($beforeRules),
                    'requested_rule' => $validated,
                    'error' => 'create_failed',
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare firewall rule could not be created.'),
            ], 422);
        }

        $afterRules = $cloudflare->listFirewallRules($zoneSummary['zone_id']);
        $this->logCloudflareAudit(
            $request,
            $targetDomain,
            'cloudflare_firewall_rule_create',
            [
                'rules_count' => count($beforeRules),
            ],
            [
                'rules_count' => count($afterRules),
                'requested_rule' => $validated,
            ],
        );

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
        $this->authorize('manageCloudflare', $domain);
        $targetDomain = $this->resolveTargetDomain($domain);
        $zoneSummary = $cloudflare->getZoneSummary($targetDomain->fqdn);

        if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'])) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_firewall_rule_delete_failed',
                ['zone_exists' => false, 'zone_id' => null, 'rule_id' => $ruleId],
                ['error' => 'zone_not_found'],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare zone was not found for this domain.'),
            ], 422);
        }

        $beforeRules = $cloudflare->listFirewallRules($zoneSummary['zone_id']);
        $beforeRule = $this->findFirewallRuleById($beforeRules, $ruleId);
        $deleted = $cloudflare->deleteFirewallRule($zoneSummary['zone_id'], $ruleId);
        if (! $deleted) {
            $this->logCloudflareAudit(
                $request,
                $targetDomain,
                'cloudflare_firewall_rule_delete_failed',
                [
                    'rule_id' => $ruleId,
                    'rule' => $beforeRule,
                    'rules_count' => count($beforeRules),
                ],
                [
                    'rule_id' => $ruleId,
                    'rules_count' => count($beforeRules),
                    'error' => 'delete_failed',
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Cloudflare firewall rule could not be deleted.'),
            ], 422);
        }

        $afterRules = $cloudflare->listFirewallRules($zoneSummary['zone_id']);
        $this->logCloudflareAudit(
            $request,
            $targetDomain,
            'cloudflare_firewall_rule_delete',
            [
                'rule_id' => $ruleId,
                'rule' => $beforeRule,
                'rules_count' => count($beforeRules),
            ],
            [
                'rule_id' => $ruleId,
                'rule_deleted' => $this->findFirewallRuleById($afterRules, $ruleId) === null,
                'rules_count' => count($afterRules),
            ],
        );

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
        $effectiveCloudflareEnabled = $targetDomain->usesCloudflare();
        if (! $effectiveCloudflareEnabled && $targetDomain->dns_provider === null) {
            $effectiveCloudflareEnabled = (bool) ($zoneSummary['exists'] ?? false);
        }

        return [
            'domain_id' => $targetDomain->id,
            'domain_fqdn' => $targetDomain->fqdn,
            'cloudflare_enabled' => $targetDomain->usesCloudflare(),
            'cloudflare_effective_enabled' => $effectiveCloudflareEnabled,
            'zone' => $zoneSummary,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function findFirewallRuleById(array $rules, string $ruleId): ?array
    {
        foreach ($rules as $rule) {
            if ((string) ($rule['id'] ?? '') === $ruleId) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $oldState
     * @param  array<string, mixed>  $newState
     */
    private function logCloudflareAudit(
        Request $request,
        Domain $targetDomain,
        string $action,
        array $oldState,
        array $newState,
    ): void {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'domain_id' => $targetDomain->id,
            'summary' => sprintf(
                'Old: %s | New: %s',
                $this->encodeAuditState($oldState),
                $this->encodeAuditState($newState),
            ),
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function encodeAuditState(array $state): string
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }
}
