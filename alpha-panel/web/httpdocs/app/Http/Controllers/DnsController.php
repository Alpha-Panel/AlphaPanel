<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDnsRecordRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\CloudflareDnsService;
use Cloudflare\API\Endpoints\EndpointException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    public function __construct(
        private CloudflareDnsService $cloudflare,
    ) {}

    public function index(Request $request, Domain $domain): Response
    {
        $this->authorize('viewDns', $domain);

        return Inertia::render('Dns/Index', compact('domain'));
    }

    public function listRecords(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('viewDns', $domain);

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
                    ? '<img src="https://www.cloudflare.com/img/logo-cloudflare-dark.svg" class="cloud" alt="Proxied">'
                    : '<span class="text-muted">DNS only</span>';

                $data[] = [
                    'id' => $record->id,
                    'type' => $record->type,
                    'name' => $record->name,
                    'content' => $content,
                    'ttl' => $record->ttl == 1 ? 'Auto' : $record->ttl,
                    'proxied' => $record->proxied,
                    'status' => $proxiedIcon,
                    'all_data' => $record,
                    'action' => '<a href="javascript:void(0)" class="btn btn-sm btn-primary edit"><i class="fa-solid fa-pen"></i></a> '
                        .'<a href="javascript:void(0)" class="btn btn-sm btn-danger delete"><i class="fa-solid fa-trash"></i></a>',
                ];
            }

            return response()->json([
                'recordsTotal' => count($data),
                'recordsFiltered' => count($data),
                'data' => $data,
            ]);
        } catch (EndpointException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function store(StoreDnsRecordRequest $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDns', $domain);
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
                    'message' => 'DNS record updated successfully.',
                ]);
            } else {
                $this->cloudflare->addRecord($zoneId, $data);
                $createdRecord = $this->findMatchingRecord($zoneId, $data);

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
                    'message' => 'DNS record created successfully.',
                ]);
            }
        } catch (EndpointException $exception) {
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

            return response()->json(['status' => 'success', 'message' => 'DNS record deleted.']);
        } catch (EndpointException $exception) {
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
        } catch (EndpointException) {
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
        } catch (EndpointException) {
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
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
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
