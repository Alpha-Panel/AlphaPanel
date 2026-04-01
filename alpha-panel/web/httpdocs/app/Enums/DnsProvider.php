<?php

namespace App\Enums;

enum DnsProvider: string
{
    case Cloudflare = 'cloudflare';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Cloudflare => __('Cloudflare DNS'),
            self::Local => __('Local DNS'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Cloudflare => 'Cloudflare',
            self::Local => 'Local',
        };
    }
}
