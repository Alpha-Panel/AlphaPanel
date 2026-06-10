<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OAuthAuthorizationCode;
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
            'code' => 'sometimes|nullable|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->webAuthnCredentials()->whereEnabled()->exists()) {
            throw ValidationException::withMessages([
                'email' => [__('Bu hesapta passkey aktif — parola ile giriş engellenmiştir.')],
            ]);
        }

        if ($user->two_factor_confirmed) {
            $code = (string) $request->input('code', '');

            if ($code === '' || ! $user->confirmTwoFactorAuth($code)) {
                throw ValidationException::withMessages([
                    'code' => [__('Invalid Two Factor Authentication code')],
                ]);
            }
        }

        $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES);
        $deviceName = $request->input('device_name', 'alphacenter');

        /**
         * @todo SECURITY (H-4): tokens are minted with full `['*']` abilities.
         * Consider deriving the ability set from the user's roles/permissions so a
         * compromised AlphaCenter token cannot perform every panel action. Left as
         * `['*']` deliberately in this pass to avoid breaking the AlphaCenter
         * integration — change requires a coordinated product decision.
         */
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

        /** @todo SECURITY (H-4): see login() — minted with full ['*'] abilities; revisit as a product decision. */
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

    public function token(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'redirect_uri' => 'required|string',
        ]);

        $authCode = OAuthAuthorizationCode::where('code', $request->input('code'))->first();

        if (! $authCode || ! $authCode->isValid()) {
            return response()->json(['message' => 'Invalid or expired authorization code.'], 401);
        }

        abort_unless($authCode->redirect_uri === $request->input('redirect_uri'), 400, 'redirect_uri mismatch.');

        $authCode->update(['used_at' => now()]);

        $user = $authCode->user;
        $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES);
        /** @todo SECURITY (H-4): see login() — minted with full ['*'] abilities; revisit as a product decision. */
        $sanctumToken = $user->createToken('alphacenter-oauth', ['*'], $expiresAt);

        $rawRefresh = Str::random(64);
        RefreshToken::create([
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
        ]);
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();

        if ($user !== null) {
            // Revoke every active refresh token for this user — otherwise a stolen
            // refresh token can still mint new access tokens after logout.
            RefreshToken::where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $user->currentAccessToken()?->delete();
        }

        return response()->noContent();
    }
}
