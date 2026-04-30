<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreDnsRecordRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\LocalDnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DnsController extends ApiController
{
    public function index(Domain $domain, LocalDnsService $dns): JsonResponse
    {
        $records = $dns->getRecords($domain);

        return response()->json(['data' => $records]);
    }

    public function store(StoreDnsRecordRequest $request, Domain $domain, LocalDnsService $dns): JsonResponse
    {
        $record = $dns->createRecord($domain, $request->validated());

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'dns_record_created',
            'domain_id' => $domain->id,
            'summary' => "{$request->input('type')} {$request->input('name')}",
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $record], 201);
    }

    public function destroy(Request $request, Domain $domain, LocalDnsService $dns): Response
    {
        $request->validate(['record_id' => 'required|integer']);
        $dns->deleteRecord($domain, (int) $request->input('record_id'));

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'dns_record_deleted',
            'domain_id' => $domain->id,
            'summary' => "record #{$request->input('record_id')}",
            'ip_address' => $request->ip(),
        ]);

        return response()->noContent();
    }

    public function switchProvider(Request $request, Domain $domain): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'provider' => 'required|string|in:local,cloudflare,none',
        ]);

        $domain->update(['dns_provider' => $validated['provider']]);

        return response()->json(['data' => $domain->fresh()]);
    }

    public function bulkDestroy(Request $request, Domain $domain, LocalDnsService $dns): JsonResponse
    {
        $this->ensureAdmin($request);

        $request->validate(['record_ids' => 'required|array', 'record_ids.*' => 'integer']);

        $count = 0;
        foreach ($request->input('record_ids') as $id) {
            $dns->deleteRecord($domain, (int) $id);
            $count++;
        }

        return response()->json(['message' => __(':count DNS records deleted.', ['count' => $count])]);
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}
