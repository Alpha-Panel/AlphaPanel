<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAntiBotSettingRequest;
use App\Models\SecuritySetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SecuritySettingController extends Controller
{
    public function antiBot(): Response
    {
        $settings = SecuritySetting::instance();

        return Inertia::render('Settings/Security/AntiBot', [
            'settings' => [
                'captcha_provider' => $settings->captcha_provider,
                'turnstile_site_key' => $settings->turnstile_site_key ?? '',
                'recaptcha_site_key' => $settings->recaptcha_site_key ?? '',
                'honeypot_enabled' => $settings->honeypot_enabled,
                'has_turnstile_secret' => filled($settings->turnstile_secret_key),
                'has_recaptcha_secret' => filled($settings->recaptcha_secret_key),
            ],
        ]);
    }

    public function updateAntiBot(UpdateAntiBotSettingRequest $request): RedirectResponse
    {
        $settings = SecuritySetting::instance();
        $validated = $request->validated();

        $data = [
            'captcha_provider' => $validated['captcha_provider'],
            'honeypot_enabled' => $validated['honeypot_enabled'],
        ];

        if ($validated['captcha_provider'] === 'turnstile') {
            $data['turnstile_site_key'] = $validated['turnstile_site_key'];
            if (filled($validated['turnstile_secret_key'] ?? null)) {
                $data['turnstile_secret_key'] = $validated['turnstile_secret_key'];
            }
            // Clear recaptcha keys
            $data['recaptcha_site_key'] = null;
            $data['recaptcha_secret_key'] = null;
        } elseif ($validated['captcha_provider'] === 'recaptcha') {
            $data['recaptcha_site_key'] = $validated['recaptcha_site_key'];
            if (filled($validated['recaptcha_secret_key'] ?? null)) {
                $data['recaptcha_secret_key'] = $validated['recaptcha_secret_key'];
            }
            // Clear turnstile keys
            $data['turnstile_site_key'] = null;
            $data['turnstile_secret_key'] = null;
        } else {
            // none - clear all keys
            $data['turnstile_site_key'] = null;
            $data['turnstile_secret_key'] = null;
            $data['recaptcha_site_key'] = null;
            $data['recaptcha_secret_key'] = null;
        }

        $settings->update($data);

        return redirect()->route('settings.security.anti-bot.index')
            ->with('success', __('Anti-bot settings updated successfully.'));
    }
}
