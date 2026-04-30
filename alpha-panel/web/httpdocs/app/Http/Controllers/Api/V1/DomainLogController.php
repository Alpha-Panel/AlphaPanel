<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Domain;
use App\Services\DomainRequestLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainLogController extends ApiController
{
    public function index(Domain $domain): JsonResponse
    {
        return response()->json(['data' => ['fqdn' => $domain->fqdn]]);
    }

    public function entries(Request $request, Domain $domain, DomainRequestLogService $logService): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:1000',
            'since' => 'nullable|string',
            'filter' => 'nullable|string',
        ]);

        $entries = $logService->getEntries(
            $domain,
            (int) ($validated['limit'] ?? 100),
            $validated['since'] ?? null,
            $validated['filter'] ?? null,
        );

        return response()->json(['data' => $entries]);
    }

    public function stream(Request $request, Domain $domain, DomainRequestLogService $logService): JsonResponse
    {
        $validated = $request->validate(['since' => 'nullable|string']);
        $entries = $logService->getNewEntries($domain, $validated['since'] ?? null);

        return response()->json(['data' => $entries]);
    }
}
