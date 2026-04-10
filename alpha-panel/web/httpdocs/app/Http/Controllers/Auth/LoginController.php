<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckLoginIp;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Models\WebAuthn;
use App\Services\LoginSecurityService;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Honeypot\ProtectAgainstSpam;

class LoginController extends Controller implements HasMiddleware
{
    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     */
    protected $redirectTo = '/';

    public static function middleware(): array
    {
        return [
            new Middleware('guest', except: ['logout']),
            new Middleware('auth', only: ['logout']),
            new Middleware(CheckLoginIp::class, only: ['login']),
            new Middleware(ProtectAgainstSpam::class, only: ['login']),
        ];
    }

    /**
     * Show the application's login form.
     */
    public function showLoginForm(): Response
    {
        $settings = SecuritySetting::instance();
        $captcha = null;

        if ($settings->isCaptchaEnabled()) {
            $captcha = [
                'provider' => $settings->captcha_provider,
                'site_key' => $settings->captcha_provider === 'turnstile'
                    ? $settings->turnstile_site_key
                    : $settings->recaptcha_site_key,
            ];

            if ($settings->captcha_provider === 'recaptcha') {
                $captcha['recaptcha_version'] = $settings->recaptcha_version ?? 'v2';
            }
        }

        $honeypotConfig = null;

        if ($settings->honeypot_enabled && config('honeypot.enabled')) {
            $honeypotConfig = [
                'name_field' => config('honeypot.name_field_name', 'my_name'),
                'valid_from_field' => config('honeypot.valid_from_field_name', 'my_time'),
                'encrypted_valid_from' => (string) \Spatie\Honeypot\EncryptedTime::create(now()),
            ];
        }

        return Inertia::render('Auth/Login', [
            'captcha' => $captcha,
            'honeypot' => $honeypotConfig,
        ]);
    }

    public function methods(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
        ]);

        $login = trim($validated['login']);
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::query()->where($field, $login)->first();

        // Return identical structure for non-existent users to prevent enumeration
        if (! $user) {
            return response()->json([
                'has_webauthn' => false,
                'has_totp' => false,
                'email' => null,
            ]);
        }

        $hasWebauthn = WebAuthn::query()
            ->where('authenticatable_id', $user->id)
            ->exists();

        return response()->json([
            'has_webauthn' => $hasWebauthn,
            'has_totp' => (bool) $user->two_factor_confirmed,
            'email' => $hasWebauthn ? $user->email : null,
        ]);
    }

    /**
     * Get the login username to be used by the controller.
     */
    public function username(): string
    {
        return 'login';
    }

    /**
     * Validate the user login request.
     */
    protected function validateLogin(Request $request): void
    {
        $rules = [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];

        $settings = SecuritySetting::instance();

        if ($settings->isCaptchaEnabled()) {
            $rules['captcha_token'] = ['required', 'string'];
        }

        $request->validate($rules);

        if ($settings->isCaptchaEnabled()) {
            $service = app(LoginSecurityService::class);

            if (! $service->verifyCaptcha($request->input('captcha_token'), $request->ip())) {
                throw ValidationException::withMessages([
                    'captcha_token' => [__('Captcha verification failed. Please try again.')],
                ]);
            }
        }
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array<string, string>
     */
    protected function credentials(Request $request): array
    {
        $login = $request->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $field => $login,
            'password' => $request->input('password'),
        ];
    }
}
