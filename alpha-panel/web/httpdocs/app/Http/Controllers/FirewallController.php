<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFirewallRuleRequest;
use App\Models\AuditLog;
use App\Models\FirewallRule;
use App\Models\User;
use App\Services\FirewallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FirewallController extends Controller
{
    public function index(Request $request, FirewallService $firewall): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return Inertia::render('Security/Firewall', [
            'firewall' => $firewall->getDbRules($request->ip()),
        ]);
    }

    public function data(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return response()->json($firewall->getDbRules($request->ip()));
    }

    public function store(StoreFirewallRuleRequest $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validated();
        $count = $firewall->addDbRules($validated, $user->id);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'firewall_rule_created',
            'summary' => sprintf(
                'Firewall rule created: %s %s %s%s%s',
                $validated['chain'],
                $validated['action'],
                $validated['protocol'] ?? 'all',
                ! empty($validated['sources']) ? ' from '.implode(',', $validated['sources']) : '',
                ! empty($validated['ports']) ? ' ports '.implode(',', $validated['ports']) : '',
            ),
            'details' => json_encode($validated),
        ]);

        return response()->json([
            'success' => true,
            'count' => $count,
        ]);
    }

    public function update(Request $request, FirewallRule $rule, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'chain' => ['required', Rule::in(['INPUT', 'OUTPUT'])],
            'action' => ['required', Rule::in(['ACCEPT', 'DROP', 'REJECT'])],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'icmp', 'all'])],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/'],
            'ports' => ['nullable', 'array'],
            'ports.*' => ['integer', 'min:1', 'max:65535'],
            'comment' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $oldValues = $rule->only(['chain', 'action', 'protocol', 'sources', 'ports', 'comment', 'enabled']);
        $updated = $firewall->updateDbRule($rule->id, $validated);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'firewall_rule_updated',
            'summary' => sprintf('Firewall rule #%d updated: %s %s', $rule->id, $validated['chain'], $validated['action']),
            'details' => json_encode(['old' => $oldValues, 'new' => $validated]),
        ]);

        return response()->json([
            'success' => true,
            'rule' => $updated,
        ]);
    }

    public function destroy(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'id' => ['required', 'integer', 'exists:firewall_rules,id'],
        ]);

        $rule = FirewallRule::find($validated['id']);
        $ruleData = $rule ? $rule->only(['chain', 'action', 'protocol', 'sources', 'ports', 'comment']) : [];

        $firewall->deleteDbRule((int) $validated['id']);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'firewall_rule_deleted',
            'summary' => sprintf(
                'Firewall rule #%d deleted: %s %s%s',
                $validated['id'],
                $ruleData['chain'] ?? '',
                $ruleData['action'] ?? '',
                ! empty($ruleData['comment']) ? " ({$ruleData['comment']})" : '',
            ),
            'details' => json_encode($ruleData),
        ]);

        return response()->json(['success' => true]);
    }

    public function policy(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'chain' => ['required', Rule::in(['INPUT', 'OUTPUT'])],
            'policy' => ['required', Rule::in(['ACCEPT', 'DROP'])],
        ]);

        $firewall->setPolicy($validated['chain'], $validated['policy']);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'firewall_policy_changed',
            'summary' => sprintf('Firewall %s policy changed to %s', $validated['chain'], $validated['policy']),
            'details' => json_encode($validated),
        ]);

        return response()->json(['success' => true]);
    }

    public function preview(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return response()->json([
            'script' => $firewall->buildApplyScript(),
        ]);
    }

    public function reorder(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*' => ['required', 'integer', 'exists:firewall_rules,id'],
        ]);

        $firewall->reorderRules($validated['rules']);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'firewall_rules_reordered',
            'summary' => sprintf('Firewall rules reordered (%d rules)', count($validated['rules'])),
            'details' => json_encode(['order' => $validated['rules']]),
        ]);

        return response()->json(['success' => true]);
    }

    public function toggle(Request $request, FirewallRule $rule): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $rule->update(['enabled' => $validated['enabled']]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'firewall_rule_toggled',
            'summary' => sprintf(
                'Firewall rule #%d %s: %s %s%s',
                $rule->id,
                $validated['enabled'] ? 'enabled' : 'disabled',
                $rule->chain,
                $rule->action,
                $rule->comment ? " ({$rule->comment})" : '',
            ),
            'details' => json_encode(['rule_id' => $rule->id, 'enabled' => $validated['enabled']]),
        ]);

        return response()->json(['success' => true]);
    }
}
