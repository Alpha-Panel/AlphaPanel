<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    private const ACCESS_TOKEN_TTL_MINUTES = 15;

    private const REFRESH_TOKEN_TTL_DAYS = 30;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'sometimes|string|max:255',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES);
        $deviceName = $request->input('device_name', 'alphacenter');

        $sanctumToken = $user->createToken($deviceName, ['*'], $expiresAt);

        $rawRefresh = Str::random(64);
        $refreshToken = RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawRefresh),
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'access_token' => $sanctumToken->plainTextToken,
            'refresh_token' => $rawRefresh,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);

        $hash = hash('sha256', $request->input('refresh_token'));
        $tokenRow = RefreshToken::where('token_hash', $hash)->first();

        if (! $tokenRow || ! $tokenRow->isValid()) {
            return response()->json(['message' => 'Invalid or expired refresh token.'], 401);
        }

        $user = $tokenRow->user;
        $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES);

        // Revoke old token and issue a new one (rotation)
        $tokenRow->update(['revoked_at' => now(), 'last_used_at' => now()]);

        $sanctumToken = $user->createToken('alphacenter-refresh', ['*'], $expiresAt);

        $rawRefresh = Str::random(64);
        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawRefresh),
            'alpha_center_webhook_url' => $tokenRow->alpha_center_webhook_url,
            'alpha_center_webhook_secret' => $tokenRow->alpha_center_webhook_secret,
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'access_token' => $sanctumToken->plainTextToken,
            'refresh_token' => $rawRefresh,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
