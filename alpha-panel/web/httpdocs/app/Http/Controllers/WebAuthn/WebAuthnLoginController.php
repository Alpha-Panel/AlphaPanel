<?php

namespace App\Http\Controllers\WebAuthn;

use App\Models\SecuritySetting;
use App\Services\LoginSecurityService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

use function response;

class WebAuthnLoginController
{
    /**
     * Returns the challenge to assertion.
     */
    public function options(AssertionRequest $request): Responsable
    {
        return $request->toVerify($request->validate(['email' => 'sometimes|email|string']));
    }

    /**
     * Log the user in.
     */
    public function login(AssertedRequest $request): Response
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

        $success = $request->login(remember: true);

        if ($success) {
            session()->put('otp', true);
        }

        return response()->noContent($success ? 204 : 422);
    }
}
