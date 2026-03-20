<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFirewallRuleRequest;
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
            'firewall' => $firewall->getDbRules(),
        ]);
    }

    public function data(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return response()->json($firewall->getDbRules());
    }

    public function store(StoreFirewallRuleRequest $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $count = $firewall->addDbRules($request->validated(), $user->id);

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

        $updated = $firewall->updateDbRule($rule->id, $validated);

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

        $firewall->deleteDbRule((int) $validated['id']);

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

        return response()->json(['success' => true]);
    }

    public function apply(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $firewall->apply();

        return response()->json(['success' => true, 'message' => 'Firewall rules are being applied.']);
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

        return response()->json(['success' => true]);
    }

    public function seed(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $count = $firewall->seedFromLive($user->id);

        return response()->json([
            'success' => true,
            'imported' => $count,
        ]);
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

        return response()->json(['success' => true]);
    }
}
