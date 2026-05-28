<?php

namespace App\Http\Controllers;

use App\Exceptions\CloudflareException;
use App\Http\Requests\StoreDnsRecordRequest;
use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Services\CloudflareDnsService;
use App\Services\LocalDnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    public function __construct(
        private CloudflareDnsService $cloudflare,
        private LocalDnsService $localDns,
    ) {}

    public function index(Request $request, Domain $domain): Response
    {
        $this->authorize('viewDns', $domain);

        return Inertia::render('Dns/Index', [
            'domain' => $domain,
            'dns_provider' => $domain->dns_provider?->value ?? 'local',
        ]);
    }

    public function listRecords(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('viewDns', $domain);

        if ($domain->usesLocalDns()) {
            return $this->listLocalRecords($request, $domain);
        }

        return $this->listCloudflareRecords($request, $domain);
    }

    private function listLocalRecords(Request $request, Domain $domain): JsonResponse
    {
        $zone = $domain->dnsZone;

        if (! $zone) {
            return response()->json([
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ]);
        }

        $search = $request->input('search.value') ?? '';
        $records = $this->localDns->listRecords($zone, $search !== '' ? $search : null);

        $data = $records->map(fn (DnsRecord $record) => [
            'id' => $record->id,
            'type' => $record->type,
            'name' => $record->name,
            'content' => $record->type === 'MX'
                ? "{$record->priority} {$record->content}"
                : $record->content,
            'ttl' => $record->ttl,
            'proxied' => false,
            'status' => '',
            'is_managed' => $record->is_managed,
            'all_data' => $record,
        ])->values()->all();

        return response()->json([
            'recordsTotal' => count($data),
            'recordsFiltered' => count($data),
            'data' => $data,
        ]);
    }

    private function listCloudflareRecords(Request $request, Domain $domain): JsonResponse
    {
        try {
            $apexDomain = $domain->getApexDomain();
            $zoneId = $this->cloudflare->getZoneId($apexDomain);

            $search = $request->input('search.value') ?? '';
            $records = $this->cloudflare->listRecords($zoneId, $search);

            $data = [];
            foreach ($records as $record) {
                $content = $record->type === 'MX'
                    ? "{$record->priority} {$record->content}"
                    : $record->content;

                $proxiedIcon = $record->proxied
                    ? '<img src="'.asset('img/cf-proxied.svg').'" class="cloud" alt="Proxied">'
                    : '<img src="'.asset('img/cf-dns-only.svg').'" class="cloud" alt="DNS Only">';

                $data[] = [
                    'id' => $record->id,
                    'type' => $record->type,
                    'name' => $record->name,
                    'content' => $content,
                    'ttl' => $record->ttl == 1 ? 'Auto' : $record->ttl,
                    'proxied' => $record->proxied,
                    'status' => $proxiedIcon,
                    'all_data' => $record,
                ];
            }

            return response()->json([
                'recordsTotal' => count($data),
                'recordsFiltered' => count($data),
                'data' => $data,
            ]);
        } catch (CloudflareException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function store(StoreDnsRecordRequest $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDns', $domain);

        if ($domain->usesLocalDns()) {
            return $this->storeLocalRecord($request, $domain);
        }

        return $this->storeCloudflareRecord($request, $domain);
    }

    private function storeLocalRecord(StoreDnsRecordRequest $request, Domain $domain): JsonResponse
    {
        $validated = $request->validated();
        $zone = $domain->dnsZone;

        if (! $zone) {
            $zone = $this->localDns->createZone($domain);
        }

        $dnsIdInput = $request->input('dns_id');
        $dnsId = is_numeric($dnsIdInput) ? (int) $dnsIdInput : null;
        $isUpdate = $dnsId !== null && $dnsId > 0;

        try {
            $recordData = [
                'name' => $validated['name'] ?? '',
                'type' => strtoupper($validated['type'] ?? 'A'),
                'content' => $validated['content'] ?? '',
                'ttl' => (int) ($validated['ttl'] ?? 3600),
                'priority' => isset($validated['priority']) ? (int) $validated['priority'] : null,
            ];

            if ($isUpdate) {
                $record = DnsRecord::where('domain_id', $zone->id)->findOrFail($dnsId);
                $this->localDns->updateRecord($record, $recordData);
                $action = 'dns_updated';
                $message = __('DNS record updated successfully.');
            } else {
                $this->localDns->addRecord($zone, $recordData);
                $action = 'dns_created';
                $message = __('DNS record created successfully.');
            }

            // Best-effort sync to Cloudflare if zone exists there
            $this->syncRecordToCloudflare($domain, $recordData, $isUpdate);

            $this->createDnsAuditLog($request, $domain, $action, [], ['submitted' => $recordData]);

            return response()->json(['status' => 'success', 'message' => $message]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function storeCloudflareRecord(StoreDnsRecordRequest $request, Domain $domain): JsonResponse
    {
        $validated = $request->validated();
        $apexDomain = $domain->getApexDomain();
        $dnsIdInput = $request->input('dns_id');
        $dnsId = is_string($dnsIdInput) ? trim($dnsIdInput) : null;
        $isUpdate = $dnsId !== null && $dnsId !== '';
        $oldState = [
            'record' => null,
            'dns_id' => $dnsId,
        ];
        $submittedData = $validated;

        try {
            $zoneId = $this->cloudflare->getZoneId($apexDomain);
            $data = $this->cloudflare->buildRecordData($validated);
            $submittedData = $data;

            if ($isUpdate && $dnsId !== null) {
                $existingRecord = $this->fetchRecordSafely($zoneId, $dnsId);
                $oldState = [
                    'record' => $this->normalizeRecordForAudit($existingRecord),
                    'dns_id' => $dnsId,
                ];
                $this->cloudflare->updateRecord($zoneId, $dnsId, $data);
                $updatedRecord = $this->fetchRecordSafely($zoneId, $dnsId);

                $this->createDnsAuditLog(
                    $request,
                    $domain,
                    'dns_updated',
                    $oldState,
                    [
                        'record' => $this->normalizeRecordForAudit($updatedRecord),
                        'dns_id' => $dnsId,
                        'submitted' => $data,
                    ],
                );

                return response()->json([
                    'status' => 'success',
                    'message' => __('DNS record updated successfully.'),
                ]);
            } else {
                $this->cloudflare->addRecord($zoneId, $data);
                $createdRecord = $this->findMatchingRecord($zoneId, $data);

                // Best-effort sync to local zone if it exists
                $this->syncRecordToLocal($domain, $data);

                $this->createDnsAuditLog(
                    $request,
                    $domain,
                    'dns_created',
                    $oldState,
                    [
                        'record' => $this->normalizeRecordForAudit($createdRecord),
                        'submitted' => $data,
                    ],
                );

                return response()->json([
                    'status' => 'success',
                    'message' => __('DNS record created successfully.'),
                ]);
            }
        } catch (CloudflareException $exception) {
            $this->createDnsAuditLog(
                $request,
                $domain,
                $isUpdate ? 'dns_update_failed' : 'dns_create_failed',
                $oldState,
                [
                    'record' => $oldState['record'],
                    'dns_id' => $dnsId,
                    'submitted' => $submittedData,
                    'error' => $exception->getMessage(),
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDns', $domain);

        if ($domain->usesLocalDns()) {
            return $this->destroyLocalRecord($request, $domain);
        }

        return $this->destroyCloudflareRecord($request, $domain);
    }

    private function destroyLocalRecord(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'dns_id' => ['required', 'integer'],
        ]);

        $zone = $domain->dnsZone;
        if (! $zone) {
            return response()->json(['status' => 'error', 'message' => __('DNS zone not found.')], 404);
        }

        $record = DnsRecord::where('domain_id', $zone->id)->findOrFail((int) $validated['dns_id']);

        try {
            $this->localDns->deleteRecord($record);
            $this->createDnsAuditLog($request, $domain, 'dns_deleted', ['record' => $record->toArray()], []);

            return response()->json(['status' => 'success', 'message' => __('DNS record deleted.')]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    private function destroyCloudflareRecord(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'dns_id' => ['required', 'string'],
        ]);
        $dnsId = trim((string) $validated['dns_id']);
        $oldState = [
            'record' => null,
            'dns_id' => $dnsId,
        ];

        try {
            $apexDomain = $domain->getApexDomain();
            $zoneId = $this->cloudflare->getZoneId($apexDomain);
            $record = $this->fetchRecordSafely($zoneId, $dnsId);
            $oldState = [
                'record' => $this->normalizeRecordForAudit($record),
                'dns_id' => $dnsId,
            ];

            $this->cloudflare->deleteRecord($zoneId, $dnsId);
            $afterRecord = $this->fetchRecordSafely($zoneId, $dnsId);

            $this->createDnsAuditLog(
                $request,
                $domain,
                'dns_deleted',
                $oldState,
                [
                    'record' => $this->normalizeRecordForAudit($afterRecord),
                    'dns_id' => $dnsId,
                    'deleted' => $afterRecord === null,
                ],
            );

            return response()->json(['status' => 'success', 'message' => __('DNS record deleted.')]);
        } catch (CloudflareException $exception) {
            $this->createDnsAuditLog(
                $request,
                $domain,
                'dns_delete_failed',
                $oldState,
                [
                    'record' => $oldState['record'],
                    'dns_id' => $dnsId,
                    'error' => $exception->getMessage(),
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * Switch DNS provider for a domain.
     * When switching from Cloudflare to Local: imports CF records into local zone.
     * When switching from Local to Cloudflare: keeps local zone but changes provider.
     */
    public function switchProvider(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDns', $domain);

        $validated = $request->validate([
            'dns_provider' => ['required', 'string', 'in:cloudflare,local'],
            'import_records' => ['boolean'],
        ]);

        $newProvider = $validated['dns_provider'];
        $importRecords = (bool) ($validated['import_records'] ?? false);
        $currentProvider = $domain->dns_provider?->value ?? 'local';

        if ($currentProvider === $newProvider) {
            return response()->json([
                'status' => 'info',
                'message' => __('DNS provider is already set to :provider.', ['provider' => $newProvider]),
            ]);
        }

        try {
            // Cloudflare → Local: optionally import CF records
            if ($currentProvider === 'cloudflare' && $newProvider === 'local') {
                if ($importRecords) {
                    $apexDomain = $domain->getApexDomain();
                    $zoneId = $this->cloudflare->getZoneId($apexDomain);
                    $cfRecords = $this->cloudflare->listRecords($zoneId);
                    $this->localDns->importFromCloudflare($domain, $cfRecords);
                } else {
                    // Create empty zone with default template
                    if (! $domain->dnsZone) {
                        $this->localDns->createZone($domain);
                    }
                }
            }

            $domain->update(['dns_provider' => $newProvider]);

            $this->createDnsAuditLog(
                $request,
                $domain,
                'dns_provider_switched',
                ['provider' => $currentProvider],
                ['provider' => $newProvider, 'imported' => $importRecords],
            );

            return response()->json([
                'status' => 'success',
                'message' => __('DNS provider switched to :provider successfully.', ['provider' => $newProvider === 'local' ? __('Local DNS') : __('Cloudflare DNS')]),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('Failed to switch DNS provider: :error', ['error' => $e->getMessage()]),
            ], 422);
        }
    }

    /**
     * Bulk delete DNS records (local DNS only).
     */
    public function bulkDestroy(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDns', $domain);

        if (! $domain->usesLocalDns()) {
            return response()->json([
                'status' => 'error',
                'message' => __('Bulk delete is only available for Local DNS.'),
            ], 422);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $zone = $domain->dnsZone;
        if (! $zone) {
            return response()->json(['status' => 'error', 'message' => __('DNS zone not found.')], 404);
        }

        $records = DnsRecord::query()
            ->where('domain_id', $zone->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        if ($records->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => __('No matching records found.')], 404);
        }

        try {
            foreach ($records as $record) {
                $this->localDns->deleteRecord($record);
            }

            $this->createDnsAuditLog(
                $request,
                $domain,
                'dns_bulk_deleted',
                ['count' => $records->count()],
                ['deleted_ids' => $records->pluck('id')->all()],
            );

            return response()->json([
                'status' => 'success',
                'message' => __(':count DNS records deleted.', ['count' => $records->count()]),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Best-effort sync a record to Cloudflare when adding via local DNS.
     *
     * @param  array<string, mixed>  $recordData
     */
    private function syncRecordToCloudflare(Domain $domain, array $recordData, bool $isUpdate = false): void
    {
        if ($isUpdate) {
            return; // Update sync is complex (need CF record ID), skip for now
        }

        try {
            $apexDomain = $domain->getApexDomain();
            $zoneId = $this->cloudflare->getZoneId($apexDomain);

            $this->cloudflare->addRecord($zoneId, [
                'type' => $recordData['type'],
                'name' => $recordData['name'],
                'content' => $recordData['content'],
                'ttl' => $recordData['ttl'] ?? 1,
                'priority' => $recordData['priority'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Best-effort: CF zone may not exist or API may be down — keep
            // the primary write that succeeded against the local provider,
            // but surface the secondary failure so operators can investigate.
            Log::info('Best-effort CF record sync skipped', [
                'domain' => $domain->fqdn,
                'record_type' => $recordData['type'] ?? null,
                'record_name' => $recordData['name'] ?? null,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Best-effort sync a record to local zone when adding via Cloudflare.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncRecordToLocal(Domain $domain, array $data): void
    {
        $zone = $domain->dnsZone;
        if (! $zone) {
            return;
        }

        $type = strtoupper((string) ($data['type'] ?? ''));
        $name = (string) ($data['name'] ?? '');
        $content = (string) ($data['content'] ?? '');

        if (in_array($type, ['SOA', 'NS'], true)) {
            return;
        }

        // Skip if already exists
        $exists = DnsRecord::query()
            ->where('domain_id', $zone->id)
            ->where('name', $name)
            ->where('type', $type)
            ->where('content', $content)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            $this->localDns->addRecord($zone, [
                'name' => $name,
                'type' => $type,
                'content' => $content,
                'ttl' => (int) ($data['ttl'] ?? 3600),
                'priority' => isset($data['priority']) ? (int) $data['priority'] : null,
            ]);
        } catch (\Throwable $e) {
            // Best-effort: local zone may not exist — primary write was via
            // Cloudflare, keep the operation green but record the divergence.
            Log::info('Best-effort local DNS sync skipped', [
                'domain' => $domain->fqdn,
                'record_type' => $type,
                'record_name' => $name,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeRecordForAudit(?object $record): ?array
    {
        if (! is_object($record)) {
            return null;
        }

        $normalized = [
            'id' => isset($record->id) ? (string) $record->id : null,
            'type' => isset($record->type) ? (string) $record->type : null,
            'name' => isset($record->name) ? (string) $record->name : null,
            'content' => isset($record->content) ? (string) $record->content : null,
            'ttl' => isset($record->ttl) ? (int) $record->ttl : null,
            'proxied' => isset($record->proxied) ? (bool) $record->proxied : null,
        ];

        if (isset($record->priority) && is_numeric($record->priority)) {
            $normalized['priority'] = (int) $record->priority;
        }

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null);
    }

    private function fetchRecordSafely(string $zoneId, string $recordId): ?object
    {
        if ($recordId === '') {
            return null;
        }

        try {
            return $this->cloudflare->getRecordDetails($zoneId, $recordId);
        } catch (CloudflareException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findMatchingRecord(string $zoneId, array $data): ?object
    {
        $recordName = trim((string) ($data['name'] ?? ''));
        if ($recordName === '') {
            return null;
        }

        try {
            $records = $this->cloudflare->listRecords($zoneId, $recordName);
        } catch (CloudflareException) {
            return null;
        }

        $recordType = strtoupper(trim((string) ($data['type'] ?? '')));
        $recordContent = trim((string) ($data['content'] ?? ''));

        foreach ($records as $record) {
            if (! is_object($record)) {
                continue;
            }

            $candidateType = strtoupper(trim((string) ($record->type ?? '')));
            $candidateName = trim((string) ($record->name ?? ''));
            $candidateContent = trim((string) ($record->content ?? ''));

            if ($candidateType !== $recordType) {
                continue;
            }

            if ($candidateName !== $recordName) {
                continue;
            }

            if ($candidateContent !== $recordContent) {
                continue;
            }

            return $record;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $oldState
     * @param  array<string, mixed>  $newState
     */
    private function createDnsAuditLog(
        Request $request,
        Domain $domain,
        string $action,
        array $oldState,
        array $newState,
    ): void {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'domain_id' => $domain->id,
            'summary' => $this->buildAuditSummary($oldState, $newState),
        ]);
    }

    /**
     * @param  array<string, mixed>  $oldState
     * @param  array<string, mixed>  $newState
     */
    private function buildAuditSummary(array $oldState, array $newState): string
    {
        return sprintf(
            'Old: %s | New: %s',
            $this->encodeAuditState($oldState),
            $this->encodeAuditState($newState),
        );
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
