<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiTokenIpRule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->orderByDesc('created_at')
            ->get();

        $tokenIds = $tokens->pluck('id');
        $ipRuleCounts = ApiTokenIpRule::query()
            ->whereIn('personal_access_token_id', $tokenIds)
            ->selectRaw('personal_access_token_id, count(*) as count')
            ->groupBy('personal_access_token_id')
            ->pluck('count', 'personal_access_token_id');

        $data = $tokens->map(fn (PersonalAccessToken $token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'ip_rule_count' => $ipRuleCounts->get($token->id, 0),
            'created_at' => $token->created_at?->toIso8601String(),
            'tokenable_id' => $token->tokenable_id,
        ]);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'abilities' => 'required|array|min:1',
            'abilities.*' => 'string',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $user = $request->user();
        $token = $user->createToken(
            $validated['name'],
            $validated['abilities'],
            isset($validated['expires_at']) ? now()->parse($validated['expires_at']) : null,
        );

        return response()->json([
            'data' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'abilities' => $token->accessToken->abilities,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'token' => $token->plainTextToken,
            ],
        ], 201);
    }

    public function destroy(Request $request, int $tokenId): Response
    {
        $this->ensureAdmin($request);

        $token = PersonalAccessToken::findOrFail($tokenId);
        $token->delete();

        return response()->noContent();
    }

    public function ipRules(Request $request, int $tokenId): JsonResponse
    {
        $this->ensureAdmin($request);

        PersonalAccessToken::findOrFail($tokenId);
        $rules = ApiTokenIpRule::where('personal_access_token_id', $tokenId)->get();

        return response()->json(['data' => $rules]);
    }

    public function storeIpRule(Request $request, int $tokenId): JsonResponse
    {
        $this->ensureAdmin($request);

        $token = PersonalAccessToken::findOrFail($tokenId);

        $validated = $request->validate([
            'ip_cidr' => ['required', 'string', 'max:50', function (string $attr, mixed $value, \Closure $fail): void {
                if (! preg_match('/^[\d.:a-fA-F\/]+$/', (string) $value)) {
                    $fail('Invalid CIDR format.');
                }
            }],
            'description' => 'nullable|string|max:255',
        ]);

        $rule = ApiTokenIpRule::create([
            'personal_access_token_id' => $token->id,
            'ip_cidr' => $validated['ip_cidr'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json(['data' => $rule], 201);
    }

    public function destroyIpRule(Request $request, int $tokenId, ApiTokenIpRule $rule): Response
    {
        $this->ensureAdmin($request);
        abort_unless($rule->personal_access_token_id === $tokenId, 404);

        $rule->delete();

        return response()->noContent();
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}
