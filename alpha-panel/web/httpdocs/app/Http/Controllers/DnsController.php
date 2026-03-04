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
        $this->authorize('manageDns', $domain);

        return Inertia::render('Dns/Index', compact('domain'));
    }

    public function listRecords(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDns', $domain);

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

        try {
            $validated = $request->validated();
            $apexDomain = $domain->getApexDomain();
            $zoneId = $this->cloudflare->getZoneId($apexDomain);
            $data = $this->cloudflare->buildRecordData($validated);

            $dnsId = $request->input('dns_id');

            if ($dnsId) {
                $this->cloudflare->updateRecord($zoneId, $dnsId, $data);
                $message = 'DNS record updated successfully.';
                $action = 'dns_updated';
            } else {
                $this->cloudflare->addRecord($zoneId, $data);
                $message = 'DNS record created successfully.';
                $action = 'dns_created';
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => $action,
                'domain_id' => $domain->id,
                'summary' => $this->buildRecordSummary($action, $domain, $validated, $dnsId),
                'ip_address' => $request->ip(),
                'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
            ]);

            return response()->json(['status' => 'success', 'message' => $message]);
        } catch (EndpointException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDns', $domain);
        $validated = $request->validate([
            'dns_id' => ['required', 'string'],
        ]);

        try {
            $apexDomain = $domain->getApexDomain();
            $zoneId = $this->cloudflare->getZoneId($apexDomain);
            $dnsId = $validated['dns_id'];
            $record = null;

            if ($dnsId !== '') {
                try {
                    $record = $this->cloudflare->getRecordDetails($zoneId, $dnsId);
                } catch (EndpointException) {
                    $record = null;
                }
            }

            $this->cloudflare->deleteRecord($zoneId, $dnsId);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'dns_deleted',
                'domain_id' => $domain->id,
                'summary' => $this->buildDeleteSummary($domain, $dnsId, $record),
                'ip_address' => $request->ip(),
                'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
            ]);

            return response()->json(['status' => 'success', 'message' => 'DNS record deleted.']);
        } catch (EndpointException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildRecordSummary(string $action, Domain $domain, array $validated, ?string $dnsId): string
    {
        $verb = $action === 'dns_created' ? 'created' : 'updated';
        $recordType = (string) ($validated['record_type'] ?? 'UNKNOWN');
        $recordName = (string) ($validated['name'] ?? '@');
        $recordContent = (string) ($validated['content'] ?? '');

        $suffix = $dnsId ? " (ID: {$dnsId})" : '';

        return "DNS {$recordType} record {$recordName} -> {$recordContent} {$verb} for {$domain->fqdn}{$suffix}.";
    }

    private function buildDeleteSummary(Domain $domain, string $dnsId, ?object $record): string
    {
        $recordType = is_object($record) && isset($record->type) ? (string) $record->type : 'UNKNOWN';
        $recordName = is_object($record) && isset($record->name) ? (string) $record->name : '@';
        $recordContent = is_object($record) && isset($record->content) ? (string) $record->content : '';

        if ($dnsId !== '') {
            return "DNS {$recordType} record {$recordName} -> {$recordContent} deleted for {$domain->fqdn} (ID: {$dnsId}).";
        }

        return "DNS {$recordType} record {$recordName} -> {$recordContent} deleted for {$domain->fqdn}.";
    }
}
