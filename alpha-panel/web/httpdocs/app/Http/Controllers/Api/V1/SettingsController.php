<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AcmeSetting;
use App\Models\AlertSetting;
use App\Models\DnsSetting;
use App\Models\DnsTemplate;
use App\Models\LoginIpRule;
use App\Models\SecuritySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SettingsController extends ApiController
{
    public function dns(): JsonResponse
    {
        return response()->json(['data' => DnsSetting::first() ?? []]);
    }

    public function updateDns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ns1' => 'required|string',
            'ns2' => 'required|string',
            'ns3' => 'nullable|string',
            'ns4' => 'nullable|string',
            'default_ttl' => 'nullable|integer|min:60',
            'default_ip' => 'nullable|ip',
            'default_template_id' => 'nullable|integer|exists:dns_templates,id',
            'soa_email' => 'nullable|string',
            'soa_refresh' => 'nullable|integer',
            'soa_retry' => 'nullable|integer',
            'soa_expire' => 'nullable|integer',
            'soa_minimum' => 'nullable|integer',
        ]);

        DnsSetting::updateOrCreate([], $validated);

        return response()->json(['message' => __('DNS settings updated.')]);
    }

    public function acme(): JsonResponse
    {
        return response()->json(['data' => AcmeSetting::first() ?? []]);
    }

    public function updateAcme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'directory_url' => 'nullable|url',
            'eab_kid' => 'nullable|string',
            'eab_hmac_key' => 'nullable|string',
        ]);

        AcmeSetting::updateOrCreate([], $validated);

        return response()->json(['message' => __('ACME settings updated.')]);
    }

    public function dnsTemplates(): JsonResponse
    {
        return response()->json(['data' => DnsTemplate::query()->orderBy('name')->get()]);
    }

    public function storeDnsTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:100', 'records' => 'required|array']);
        $template = DnsTemplate::create($validated);

        return response()->json(['data' => $template], 201);
    }

    public function showDnsTemplate(DnsTemplate $template): JsonResponse
    {
        return response()->json(['data' => $template]);
    }

    public function updateDnsTemplate(Request $request, DnsTemplate $template): JsonResponse
    {
        $validated = $request->validate(['name' => 'sometimes|string|max:100', 'records' => 'sometimes|array']);
        $template->update($validated);

        return response()->json(['data' => $template->fresh()]);
    }

    public function destroyDnsTemplate(DnsTemplate $template): Response
    {
        $template->delete();

        return response()->noContent();
    }

    public function setDefaultDnsTemplate(DnsTemplate $template): JsonResponse
    {
        DnsTemplate::query()->update(['is_default' => false]);
        $template->update(['is_default' => true]);

        return response()->json(['message' => __('Default template set.')]);
    }

    public function antiBot(): JsonResponse
    {
        return response()->json(['data' => SecuritySetting::first() ?? []]);
    }

    public function updateAntiBot(Request $request): JsonResponse
    {
        $validated = $request->validate(['anti_bot_enabled' => 'boolean', 'challenge_threshold' => 'nullable|integer']);
        SecuritySetting::updateOrCreate([], $validated);

        return response()->json(['message' => __('Anti-bot settings updated.')]);
    }

    public function loginIpFilter(): JsonResponse
    {
        return response()->json(['data' => [
            'mode' => SecuritySetting::first()?->login_ip_filter_mode ?? 'disabled',
            'rules' => LoginIpRule::query()->orderBy('created_at')->get(),
        ]]);
    }

    public function updateLoginIpFilterMode(Request $request): JsonResponse
    {
        $validated = $request->validate(['mode' => 'required|string|in:disabled,whitelist,blacklist']);
        SecuritySetting::updateOrCreate([], ['login_ip_filter_mode' => $validated['mode']]);

        return response()->json(['data' => ['mode' => $validated['mode']]]);
    }

    public function storeLoginIpRule(Request $request): JsonResponse
    {
        $validated = $request->validate(['ip_cidr' => 'required|string', 'description' => 'nullable|string']);
        $rule = LoginIpRule::create($validated);

        return response()->json(['data' => $rule], 201);
    }

    public function destroyLoginIpRule(LoginIpRule $rule): Response
    {
        $rule->delete();

        return response()->noContent();
    }

    public function alerts(): JsonResponse
    {
        return response()->json(['data' => AlertSetting::first() ?? []]);
    }

    public function updateAlerts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cpu_threshold' => 'nullable|integer|min:1|max:100',
            'ram_threshold' => 'nullable|integer|min:1|max:100',
            'disk_threshold' => 'nullable|integer|min:1|max:100',
            'enabled' => 'boolean',
        ]);

        AlertSetting::updateOrCreate([], $validated);

        return response()->json(['message' => __('Alert settings updated.')]);
    }
}
