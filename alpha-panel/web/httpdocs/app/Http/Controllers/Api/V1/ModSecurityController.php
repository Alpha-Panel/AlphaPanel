<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Domain;
use App\Services\WafLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModSecurityController extends ApiController
{
    private const FIELDS = [
        'modsecurity_enabled',
        'modsecurity_mode',
        'modsecurity_ip_allowlist',
        'modsecurity_ip_blocklist',
        'modsecurity_disabled_rule_ids',
        'modsecurity_custom_rules',
    ];

    public function show(Domain $domain): JsonResponse
    {
        return response()->json(['data' => $domain->only(self::FIELDS)]);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'modsecurity_enabled' => 'boolean',
            'modsecurity_mode' => 'nullable|string|in:detection_only,on',
            'modsecurity_ip_allowlist' => 'nullable|array',
            'modsecurity_ip_allowlist.*' => 'string',
            'modsecurity_ip_blocklist' => 'nullable|array',
            'modsecurity_ip_blocklist.*' => 'string',
            'modsecurity_disabled_rule_ids' => 'nullable|array',
            'modsecurity_disabled_rule_ids.*' => 'string',
            'modsecurity_custom_rules' => 'nullable|string',
        ]);

        $domain->update($validated);

        return response()->json(['data' => $domain->fresh()->only(self::FIELDS)]);
    }

    public function logs(Domain $domain, WafLogService $wafLogService): JsonResponse
    {
        $logs = $wafLogService->getLogsForDomain($domain);

        return response()->json(['data' => $logs]);
    }
}
