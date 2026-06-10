<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WafGlobalIpRule;
use App\Rules\IpOrCidr;
use App\Services\WafRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WafRuleController extends ApiController
{
    public function index(WafRulesService $service): JsonResponse
    {
        return response()->json(['data' => WafGlobalIpRule::query()->orderByDesc('id')->get()]);
    }

    public function store(Request $request, WafRulesService $service): JsonResponse
    {
        $validated = $request->validate([
            'ip_or_cidr' => ['required', 'string', 'max:64', new IpOrCidr],
            'action' => ['required', 'string', 'in:allow,deny'],
            'note' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $rule = WafGlobalIpRule::create([
            'ip_or_cidr' => trim($validated['ip_or_cidr']),
            'action' => $validated['action'],
            'note' => trim((string) ($validated['note'] ?? '')) ?: null,
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ]);
        $service->renderGlobalRules();

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, WafGlobalIpRule $rule, WafRulesService $service): JsonResponse
    {
        $validated = $request->validate([
            'ip_or_cidr' => ['sometimes', 'string', 'max:64', new IpOrCidr],
            'action' => ['sometimes', 'string', 'in:allow,deny'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $payload = [];
        if (array_key_exists('ip_or_cidr', $validated)) {
            $payload['ip_or_cidr'] = trim($validated['ip_or_cidr']);
        }
        if (array_key_exists('action', $validated)) {
            $payload['action'] = $validated['action'];
        }
        if (array_key_exists('note', $validated)) {
            $payload['note'] = trim((string) ($validated['note'] ?? '')) ?: null;
        }
        if (array_key_exists('enabled', $validated)) {
            $payload['enabled'] = (bool) $validated['enabled'];
        }

        $rule->update($payload);
        $service->renderGlobalRules();

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroy(WafGlobalIpRule $rule, WafRulesService $service): Response
    {
        $rule->delete();
        $service->renderGlobalRules();

        return response()->noContent();
    }
}
