<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecuritySetting extends Model
{
    protected $fillable = [
        'ip_filter_mode',
        'captcha_provider',
        'turnstile_site_key',
        'turnstile_secret_key',
        'recaptcha_site_key',
        'recaptcha_secret_key',
        'honeypot_enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'turnstile_site_key' => 'encrypted',
            'turnstile_secret_key' => 'encrypted',
            'recaptcha_site_key' => 'encrypted',
            'recaptcha_secret_key' => 'encrypted',
            'honeypot_enabled' => 'boolean',
        ];
    }

    public static function instance(): self
    {
        return self::firstOrCreate([]);
    }

    public function isIpFilterActive(): bool
    {
        return $this->ip_filter_mode !== 'off';
    }

    public function isCaptchaEnabled(): bool
    {
        return $this->captcha_provider !== 'none';
    }
}
