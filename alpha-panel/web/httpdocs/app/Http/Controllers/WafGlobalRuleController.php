<?php

namespace App\Http\Controllers;

use App\Models\WafGlobalIpRule;
use App\Services\ReloadService;
use App\Services\WafRulesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WafGlobalRuleController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return Inertia::render('Security/WafGlobalRules', [
            'rules' => WafGlobalIpRule::query()->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request, WafRulesService $wafRules, ReloadService $reloadService): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'ip_or_cidr' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'in:allow,deny'],
            'note' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        WafGlobalIpRule::create([
            'ip_or_cidr' => trim($validated['ip_or_cidr']),
            'action' => $validated['action'],
            'note' => trim((string) ($validated['note'] ?? '')) ?: null,
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ]);

        $wafRules->renderAll();
        $reloadService->reloadCaddy();

        return redirect()->route('security.waf-global.index')->with('success', __('Global WAF rule added.'));
    }

    public function update(Request $request, WafGlobalIpRule $rule, WafRulesService $wafRules, ReloadService $reloadService): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'ip_or_cidr' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'in:allow,deny'],
            'note' => ['nullable', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
        ]);

        $rule->update([
            'ip_or_cidr' => trim($validated['ip_or_cidr']),
            'action' => $validated['action'],
            'note' => trim((string) ($validated['note'] ?? '')) ?: null,
            'enabled' => (bool) $validated['enabled'],
        ]);

        $wafRules->renderAll();
        $reloadService->reloadCaddy();

        return redirect()->route('security.waf-global.index')->with('success', __('Global WAF rule updated.'));
    }

    public function destroy(Request $request, WafGlobalIpRule $rule, WafRulesService $wafRules, ReloadService $reloadService): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $rule->delete();
        $wafRules->renderAll();
        $reloadService->reloadCaddy();

        return redirect()->route('security.waf-global.index')->with('success', __('Global WAF rule removed.'));
    }
}
