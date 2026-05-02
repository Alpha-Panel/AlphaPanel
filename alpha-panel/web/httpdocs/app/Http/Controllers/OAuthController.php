<?php

namespace App\Http\Controllers;

use App\Models\OAuthAuthorizationCode;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Services\LoginSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OAuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        abort_unless($request->filled('redirect_uri') && filter_var($request->redirect_uri, FILTER_VALIDATE_URL), 400, 'Invalid redirect_uri.');
        abort_unless($request->filled('state'), 400, 'state required.');

        if (Auth::check()) {
            return $this->issueCodeAndRedirect(Auth::user(), $request->redirect_uri, $request->state);
        }

        return view('oauth.authorize', [
            'redirect_uri' => $request->redirect_uri,
            'state' => $request->state,
            'captcha' => $this->captchaConfig(),
        ]);
    }

    public function checkUser(Request $request): JsonResponse
    {
        $request->validate([
            'login' => 'required|string|max:255',
        ]);

        $user = User::where('email', $request->input('login'))
            ->orWhere('username', $request->input('login'))
            ->first();

        if (! $user) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'email' => $user->email,
            'has_webauthn' => $user->webAuthnCredentials()->whereEnabled()->exists(),
            'has_password' => ! is_null($user->password),
        ]);
    }

    public function submit(Request $request, LoginSecurityService $security): RedirectResponse
    {
        $rules = [
            'login' => 'required|string|max:255',
            'password' => 'required|string',
            'redirect_uri' => 'required|url',
            'state' => 'required|string',
        ];

        $settings = SecuritySetting::instance();

        if ($settings->isCaptchaEnabled()) {
            $rules['captcha_token'] = 'required|string';
        }

        $validated = $request->validate($rules);

        if ($settings->isCaptchaEnabled()) {
            if (! $security->verifyCaptcha($validated['captcha_token'], $request->ip())) {
                return back()
                    ->withInput(['login' => $validated['login'], 'redirect_uri' => $validated['redirect_uri'], 'state' => $validated['state']])
                    ->withErrors(['captcha_token' => 'Captcha doğrulaması başarısız. Lütfen tekrar deneyin.']);
            }
        }

        $user = User::where('email', $validated['login'])
            ->orWhere('username', $validated['login'])
            ->first();

        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            return back()
                ->withInput(['login' => $validated['login'], 'redirect_uri' => $validated['redirect_uri'], 'state' => $validated['state']])
                ->withErrors(['login' => 'Kimlik bilgileri hatalı.']);
        }

        if ($user->webAuthnCredentials()->whereEnabled()->exists()) {
            return back()
                ->withInput(['login' => $validated['login'], 'redirect_uri' => $validated['redirect_uri'], 'state' => $validated['state']])
                ->withErrors(['login' => 'Bu hesapta passkey aktif — parola ile giriş engellenmiştir.']);
        }

        return $this->issueCodeAndRedirect($user, $validated['redirect_uri'], $validated['state']);
    }

    private function issueCodeAndRedirect(User $user, string $redirectUri, string $state): RedirectResponse
    {
        $code = Str::random(64);

        OAuthAuthorizationCode::create([
            'code' => $code,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'expires_at' => now()->addSeconds(90),
        ]);

        return redirect($redirectUri.'?'.http_build_query([
            'code' => $code,
            'state' => $state,
        ]));
    }

    /** @return array{provider:string,site_key:string,recaptcha_version?:string}|null */
    private function captchaConfig(): ?array
    {
        $settings = SecuritySetting::instance();

        if (! $settings->isCaptchaEnabled()) {
            return null;
        }

        $config = [
            'provider' => $settings->captcha_provider,
            'site_key' => $settings->captcha_provider === 'turnstile'
                ? $settings->turnstile_site_key
                : $settings->recaptcha_site_key,
        ];

        if ($settings->captcha_provider === 'recaptcha') {
            $config['recaptcha_version'] = $settings->recaptcha_version ?? 'v2';
        }

        return $config;
    }
}
