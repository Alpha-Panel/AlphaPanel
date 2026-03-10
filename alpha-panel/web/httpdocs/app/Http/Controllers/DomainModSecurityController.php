<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDomainModSecurityRequest;
use App\Jobs\ProvisionDomainJob;
use App\Models\Domain;
use App\Models\WafGlobalIpRule;
use App\Services\ReloadService;
use App\Services\WafLogService;
use App\Services\WafRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DomainModSecurityController extends Controller
{
    public function index(Domain $domain): Response
    {
        $this->authorize('viewModSecurity', $domain);

        return Inertia::render('Domains/ModSecurity', [
            'domain' => $domain,
            'globalRules' => WafGlobalIpRule::query()
                ->where('enabled', true)
                ->orderBy('action')
                ->orderBy('ip_or_cidr')
                ->get(['id', 'ip_or_cidr', 'action', 'note']),
        ]);
    }

    public function update(
        UpdateDomainModSecurityRequest $request,
        Domain $domain,
        WafRulesService $wafRules,
        ReloadService $reloadService,
    ): RedirectResponse {
        $this->authorize('manageModSecurity', $domain);

        $validated = $request->validated();
        $enabled = (bool) ($validated['modsecurity_enabled'] ?? false);
        $mode = $enabled && ($validated['modsecurity_mode'] ?? null) === 'detection_only'
            ? 'detection_only'
            : 'active';

        $domain->update([
            'modsecurity_enabled' => $enabled,
            'modsecurity_mode' => $enabled ? $mode : null,
            'modsecurity_ip_allowlist' => $enabled ? array_values(array_unique($validated['modsecurity_ip_allowlist'] ?? [])) : [],
            'modsecurity_ip_blocklist' => $enabled ? array_values(array_unique($validated['modsecurity_ip_blocklist'] ?? [])) : [],
            'modsecurity_disabled_rule_ids' => $enabled ? array_values(array_unique(array_map('intval', $validated['modsecurity_disabled_rule_ids'] ?? []))) : [],
            'modsecurity_custom_rules' => $enabled ? trim((string) ($validated['modsecurity_custom_rules'] ?? '')) : null,
        ]);

        $wafRules->renderGlobalRules();
        $wafRules->renderDomainRules($domain);
        $reloadService->reloadCaddy();

        if ($domain->wasChanged(['modsecurity_enabled', 'modsecurity_mode'])) {
            ProvisionDomainJob::dispatch(
                $domain,
                $request->user()->id,
                false,
                app()->getLocale(),
                actorIpAddress: $request->ip(),
                actorPort: is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
            );
        }

        return redirect()
            ->route('domains.modsecurity.index', $domain)
            ->with('success', __('ModSecurity settings updated successfully.'));
    }

    public function logs(Domain $domain, WafLogService $logService): JsonResponse
    {
        $this->authorize('viewModSecurity', $domain);

        $entries = $logService->getDomainEntries($domain, [
            'ip' => request()->string('ip')->toString(),
            'rule_id' => request()->string('rule_id')->toString(),
            'q' => request()->string('q')->toString(),
            'blocked_only' => request()->boolean('blocked_only'),
            'since' => request()->string('since')->toString(),
            'max_lines' => request()->integer('max_lines', 4000),
        ]);

        return response()->json([
            'entries' => $entries,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
