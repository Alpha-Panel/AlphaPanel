<?php

namespace App\Http\Controllers;

use App\Models\SecuritySetting;
use App\Models\WebAuthn;
use App\Services\LoginSecurityService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class TwoFactorAuthController extends Controller
{
    public function challenge(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->otp || $request->session()->get('otp')) {
            return redirect()->route('home');
        }

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

        return Inertia::render('Auth/OtpChallenge', [
            'webauthn' => WebAuthn::query()
                ->where('authenticatable_id', $user->id)
                ->exists(),
            'totp' => (bool) $user->two_factor_confirmed,
            'name' => $user->name,
            'email' => $user->email,
            'gravatar_url' => sprintf(
                'https://www.gravatar.com/avatar/%s?d=https://www.gravatar.com/avatar&s=120',
                md5(strtolower(trim((string) $user->email))),
            ),
            'captcha' => $captcha,
        ]);
    }

    public function TOTP(): Factory|View|Application
    {
        return view('auth.2fa');
    }

    public function confirm(Request $request)
    {
        $confirmed = $this->confirm_verify($request);
        if (! $confirmed) {
            return back()->withErrors('Invalid Two Factor Authentication code');
        }

        return back();
    }

    public function verify(Request $request): JsonResponse
    {
        $settings = SecuritySetting::instance();

        if ($settings->isCaptchaEnabled()) {
            $token = (string) $request->input('captcha_token', '');

            if ($token === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => __('Captcha verification failed. Please try again.'),
                ], 422);
            }

            $service = app(LoginSecurityService::class);

            if (! $service->verifyCaptcha($token, $request->ip())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('Captcha verification failed. Please try again.'),
                ], 422);
            }
        }

        $confirmed = $this->confirm_verify($request);
        if (! $confirmed) {
            return response()->json(['status' => 'error', 'message' => 'Invalid Two Factor Authentication code']);
        }
        if (session()->has('otp')) {
            session()->remove('otp');
        }
        session()->put('otp', true);

        return response()->json(['status' => 'success', 'message' => 'Two Factor Authentication code verified']);
    }

    private function confirm_verify($request)
    {
        return $request->user()->confirmTwoFactorAuth($request->code);
    }

    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse|RedirectResponse
    {
        $disable($request->user());

        return $request->wantsJson()
            ? new JsonResponse('', 200)
            : back()->with('status', 'two-factor-authentication-disabled');
    }

    public function lock(): RedirectResponse
    {
        if (session()->has('otp')) {
            session()->remove('otp');
        }

        return redirect()->route('otp.challenge')->with('status', 'two-factor-authentication-locked');
    }
}
