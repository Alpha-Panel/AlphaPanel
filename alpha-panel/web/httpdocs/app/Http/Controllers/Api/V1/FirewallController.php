<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreFirewallRuleRequest;
use App\Models\FirewallRule;
use App\Services\FirewallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FirewallController extends ApiController
{
    public function index(Request $request, FirewallService $service): JsonResponse
    {
        return response()->json(['data' => $service->getDbRules($request->ip())]);
    }

    public function data(Request $request, FirewallService $service): JsonResponse
    {
        return response()->json(['rules' => $service->getDbRules($request->ip())]);
    }

    public function toggle(FirewallRule $rule, FirewallService $service): JsonResponse
    {
        $updated = $service->updateDbRule($rule->id, ['enabled' => ! $rule->enabled]);

        return response()->json(['data' => $updated]);
    }

    public function store(StoreFirewallRuleRequest $request, FirewallService $service): JsonResponse
    {
        $count = $service->addDbRules($request->validated(), $request->user()->id);

        return response()->json(['data' => ['created' => $count]], 201);
    }

    public function update(Request $request, FirewallRule $rule, FirewallService $service): JsonResponse
    {
        $validated = $request->validate([
            'comment' => 'nullable|string|max:255',
            'action' => 'sometimes|string|in:ACCEPT,DROP,REJECT',
            'protocol' => 'nullable|string',
            'sources' => 'nullable|array',
            'ports' => 'nullable|array',
        ]);

        $updated = $service->updateDbRule($rule->id, $validated);

        return response()->json(['data' => $updated]);
    }

    public function destroy(FirewallRule $rule, FirewallService $service): Response
    {
        $service->deleteDbRule($rule->id);

        return response()->noContent();
    }

    public function destroyAll(FirewallService $service): Response
    {
        foreach (FirewallRule::all() as $rule) {
            $service->deleteDbRule($rule->id);
        }

        return response()->noContent();
    }

    public function updatePolicy(Request $request, FirewallService $service): JsonResponse
    {
        $validated = $request->validate(['chain' => 'required|string', 'policy' => 'required|string|in:ACCEPT,DROP']);
        $service->setPolicy($validated['chain'], $validated['policy']);

        return response()->json(['message' => __(':chain policy changed to :policy.', $validated)]);
    }

    public function reorder(Request $request, FirewallService $service): JsonResponse
    {
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);
        $service->reorderRules($request->input('order'));

        return response()->json(['message' => 'Reordered.']);
    }
}
