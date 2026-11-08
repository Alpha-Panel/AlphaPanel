<?php

namespace App\Http\Controllers\WebAuthn;

use App\Models\SecuritySetting;
use App\Models\User;
use App\Services\LoginSecurityService;
use App\Support\Passkeys\BrowserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredentialRequestOptions;

use function response;

class WebAuthnLoginController
{
    private const OPTIONS_KEY = 'passkey.verification_options';

    /**
     * Returns the assertion challenge; with an email it lists that user's passkeys (needed for
     * non-discoverable security keys), otherwise the browser offers discoverable passkeys.
     */
    public function options(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $validated = $request->validate(['email' => 'sometimes|nullable|email|string']);

        $user = isset($validated['email']) ? User::where('email', $validated['email'])->first() : null;

        $options = $generate($user);

        $request->session()->put(self::OPTIONS_KEY, WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options));
    }

    /**
     * Log the user in.
     */
    public function login(Request $request, VerifyPasskey $verify): Response
    {
        $settings = SecuritySetting::instance();

        if ($settings->isCaptchaEnabled()) {
            $token = (string) $request->input('captcha_token', '');

            if ($token === '') {
                throw ValidationException::withMessages([
                    'captcha_token' => [__('Captcha verification failed. Please try again.')],
                ]);
            }

            $service = app(LoginSecurityService::class);

            if (! $service->verifyCaptcha($token, $request->ip())) {
                throw ValidationException::withMessages([
                    'captcha_token' => [__('Captcha verification failed. Please try again.')],
                ]);
            }
        }

        $credential = BrowserCredential::fromRequest($request);
        $options = BrowserCredential::pullOptions($request, self::OPTIONS_KEY, PublicKeyCredentialRequestOptions::class);

        try {
            $passkey = $verify($credential, $options);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // webauthn-lib throws assorted exceptions (bad signature, origin, RP ID, malformed
            // data): answer 422 like before, not 500, and keep the reason for diagnosing.
            Log::info('Passkey login failed.', ['error' => $e->getMessage()]);

            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }

        Auth::guard('web')->login($passkey->user, remember: true);

        $request->session()->regenerate();
        $request->session()->put('otp', true);

        return response()->noContent();
    }
}
