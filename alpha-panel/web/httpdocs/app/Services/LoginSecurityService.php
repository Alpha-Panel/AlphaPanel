<?php

namespace App\Services;

use App\Models\LoginIpRule;
use App\Models\SecuritySetting;
use Illuminate\Support\Facades\Http;

class LoginSecurityService
{
    /**
     * Check if the given IP address is allowed to login based on IP filter settings.
     */
    public function isIpAllowed(string $ip): bool
    {
        $settings = SecuritySetting::instance();

        if (! $settings->isIpFilterActive()) {
            return true;
        }

        $rules = LoginIpRule::pluck('ip_address')->all();

        if ($rules === []) {
            // Whitelist with no rules = block all, Blacklist with no rules = allow all
            return $settings->ip_filter_mode === 'blacklist';
        }

        $matches = $this->ipMatchesAnyRule($ip, $rules);

        return $settings->ip_filter_mode === 'whitelist' ? $matches : ! $matches;
    }

    /**
     * Verify a captcha token against the active provider.
     */
    public function verifyCaptcha(string $token, string $ip): bool
    {
        $settings = SecuritySetting::instance();

        if (! $settings->isCaptchaEnabled()) {
            return true;
        }

        return match ($settings->captcha_provider) {
            'turnstile' => $this->verifyTurnstile($token, $ip, $settings),
            'recaptcha' => $this->verifyRecaptcha($token, $ip, $settings),
            default => true,
        };
    }

    private function verifyTurnstile(string $token, string $ip, SecuritySetting $settings): bool
    {
        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $settings->turnstile_secret_key,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        return $response->successful() && $response->json('success') === true;
    }

    private function verifyRecaptcha(string $token, string $ip, SecuritySetting $settings): bool
    {
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $settings->recaptcha_secret_key,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        return $response->successful() && $response->json('success') === true;
    }

    /**
     * Check if an IP matches any of the given rules (supports CIDR notation).
     *
     * @param  list<string>  $rules
     */
    private function ipMatchesAnyRule(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (str_contains($rule, '/')) {
                if ($this->ipInCidr($ip, $rule)) {
                    return true;
                }
            } elseif ($ip === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP address is within a CIDR range.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
