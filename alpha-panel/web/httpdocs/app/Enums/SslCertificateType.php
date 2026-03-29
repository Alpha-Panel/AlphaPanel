<?php

namespace App\Enums;

enum SslCertificateType: string
{
    case LetsEncrypt = 'letsencrypt';
    case Custom = 'custom';
    case SelfSigned = 'self_signed';

    public function label(): string
    {
        return match ($this) {
            self::LetsEncrypt => __("Let's Encrypt"),
            self::Custom => __('Custom Certificate'),
            self::SelfSigned => __('Self-Signed'),
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::LetsEncrypt => 'success',
            self::Custom => 'info',
            self::SelfSigned => 'warning',
        };
    }
}
