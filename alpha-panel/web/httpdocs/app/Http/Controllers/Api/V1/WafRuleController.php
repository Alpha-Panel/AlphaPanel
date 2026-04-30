<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WafGlobalIpRule;
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
            'ip_cidr' => 'required|string',
            'action' => 'required|string|in:block,allow',
            'comment' => 'nullable|string|max:255',
        ]);

        $rule = WafGlobalIpRule::create($validated);
        $service->renderGlobalRules();

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, WafGlobalIpRule $rule, WafRulesService $service): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'sometimes|string|in:block,allow',
            'comment' => 'nullable|string|max:255',
            'enabled' => 'boolean',
        ]);

        $rule->update($validated);
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
