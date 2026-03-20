<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFirewallRuleRequest;
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
            'firewall' => $firewall->getRules(),
        ]);
    }

    public function data(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return response()->json($firewall->getRules());
    }

    public function store(StoreFirewallRuleRequest $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $result = $firewall->addRule($request->validated());

        return response()->json([
            'success' => $result->isSuccessful(),
            'output' => $result->output,
            'error' => $result->errorOutput,
        ], $result->isSuccessful() ? 200 : 422);
    }

    public function destroy(Request $request, FirewallService $firewall): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'chain' => ['required', Rule::in(['INPUT', 'OUTPUT'])],
            'rule_number' => ['required', 'integer', 'min:1'],
        ]);

        $result = $firewall->deleteRule($validated['chain'], (int) $validated['rule_number']);

        return response()->json([
            'success' => $result->isSuccessful(),
            'output' => $result->output,
            'error' => $result->errorOutput,
        ], $result->isSuccessful() ? 200 : 422);
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

        $result = $firewall->setPolicy($validated['chain'], $validated['policy']);

        return response()->json([
            'success' => $result->isSuccessful(),
            'output' => $result->output,
            'error' => $result->errorOutput,
        ], $result->isSuccessful() ? 200 : 422);
    }
}
