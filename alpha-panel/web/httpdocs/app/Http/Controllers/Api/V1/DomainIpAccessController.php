<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreDomainIpRuleRequest;
use App\Models\Domain;
use App\Models\DomainIpRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DomainIpAccessController extends ApiController
{
    public function index(Domain $domain): JsonResponse
    {
        return response()->json([
            'data' => [
                'mode' => $domain->ip_access_mode,
                'rules' => $domain->ipRules()->orderBy('created_at')->get(),
            ],
        ]);
    }

    public function updateMode(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate(['mode' => 'required|string|in:disabled,whitelist,blacklist']);
        $domain->update(['ip_access_mode' => $validated['mode']]);

        return response()->json(['data' => ['mode' => $domain->fresh()->ip_access_mode]]);
    }

    public function store(StoreDomainIpRuleRequest $request, Domain $domain): JsonResponse
    {
        $rule = $domain->ipRules()->create($request->validated());

        return response()->json(['data' => $rule], 201);
    }

    public function destroy(Domain $domain, DomainIpRule $rule): Response
    {
        abort_unless($rule->domain_id === $domain->id, 404);
        $rule->delete();

        return response()->noContent();
    }
}
