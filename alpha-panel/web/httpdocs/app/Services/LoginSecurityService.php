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

        if (! $response->successful() || $response->json('success') !== true) {
            return false;
        }

        // reCAPTCHA v3 returns a score (0.0 - 1.0); require >= 0.5
        if (($settings->recaptcha_version ?? 'v2') === 'v3') {
            return ($response->json('score', 0) >= 0.5)
                && $response->json('action') === 'login';
        }

        return true;
    }

    /**
     * Check if an IP matches any of the given rules (supports IPv4/IPv6 and CIDR notation).
     *
     * @param  list<string>  $rules
     */
    private function ipMatchesAnyRule(string $ip, array $rules): bool
    {
        $ipPacked = @inet_pton($ip);

        if ($ipPacked === false) {
            return false;
        }

        foreach ($rules as $rule) {
            if (str_contains($rule, '/')) {
                if ($this->ipInCidr($ipPacked, $rule)) {
                    return true;
                }
            } else {
                $rulePacked = @inet_pton($rule);
                if ($rulePacked !== false && $rulePacked === $ipPacked) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if an IP (already inet_pton-packed) is within a CIDR range.
     * Supports both IPv4 and IPv6 CIDR ranges.
     */
    private function ipInCidr(string $ipPacked, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;

        $subnetPacked = @inet_pton($subnet);

        if ($subnetPacked === false) {
            return false;
        }

        // IP versions must match (both IPv4 or both IPv6)
        if (strlen($ipPacked) !== strlen($subnetPacked)) {
            return false;
        }

        $totalBits = strlen($ipPacked) * 8;

        if ($mask < 0 || $mask > $totalBits) {
            return false;
        }

        if ($mask === 0) {
            return true;
        }

        $fullBytes = intdiv($mask, 8);
        $remainingBits = $mask % 8;

        // Compare full bytes
        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($subnetPacked, 0, $fullBytes)) {
            return false;
        }

        // Compare remaining bits (if any)
        if ($remainingBits > 0) {
            $maskByte = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

            if ((ord($ipPacked[$fullBytes]) & $maskByte) !== (ord($subnetPacked[$fullBytes]) & $maskByte)) {
                return false;
            }
        }

        return true;
    }
}
