<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDomainIpRuleRequest;
use App\Http\Requests\UpdateDomainIpAccessModeRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainIpRule;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainIpRuleController extends Controller
{
    public function __construct(
        private DomainConfigService $configService,
        private ReloadService $reloadService,
    ) {}

    public function index(Request $request, Domain $domain): Response
    {
        $rules = $domain->ipRules()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Domains/IpAccess', [
            'domain' => $domain,
            'rules' => $rules,
        ]);
    }

    public function updateMode(UpdateDomainIpAccessModeRequest $request, Domain $domain): JsonResponse
    {
        $oldMode = $domain->ip_access_mode?->value ?? 'none';
        $newMode = $request->validated('ip_access_mode');

        $domain->update(['ip_access_mode' => $newMode]);

        $this->regenerateAndReload($domain);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ip_access_mode_updated',
            'domain_id' => $domain->id,
            'summary' => "Changed IP access mode for {$domain->fqdn} from {$oldMode} to {$newMode}.",
        ]);

        return response()->json(['message' => __('IP access mode updated successfully.')]);
    }

    public function store(StoreDomainIpRuleRequest $request, Domain $domain): JsonResponse
    {
        $rule = $domain->ipRules()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        $rule->load('creator:id,name');

        $this->regenerateAndReload($domain);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ip_rule_added',
            'domain_id' => $domain->id,
            'summary' => "Added IP rule {$rule->ip_address} to {$domain->fqdn}.",
            'details' => json_encode([
                'ip_address' => $rule->ip_address,
                'note' => $rule->note,
            ], JSON_THROW_ON_ERROR),
        ]);

        return response()->json([
            'message' => __('IP rule added successfully.'),
            'rule' => $rule,
        ]);
    }

    public function destroy(Request $request, Domain $domain, DomainIpRule $rule): JsonResponse
    {
        $ipAddress = $rule->ip_address;

        $rule->delete();

        $this->regenerateAndReload($domain);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ip_rule_removed',
            'domain_id' => $domain->id,
            'summary' => "Removed IP rule {$ipAddress} from {$domain->fqdn}.",
        ]);

        return response()->json(['message' => __('IP rule removed successfully.')]);
    }

    private function regenerateAndReload(Domain $domain): void
    {
        $domain->load('ipRules');
        $this->configService->renderWithTls($domain);
        $this->reloadService->reloadCaddy();
    }
}
