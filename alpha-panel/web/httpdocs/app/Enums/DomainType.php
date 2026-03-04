<?php

namespace App\Enums;

enum DomainType: string
{
    case ApacheReverseProxy = 'apache_reverse_proxy';
    case CaddyWebServer = 'caddy_web_server';

    public function label(): string
    {
        return match ($this) {
            self::CaddyWebServer => __('Caddy Web Server'),
            self::ApacheReverseProxy => __('Apache + Reverse Proxy'),
        };
    }

    public function badgeHtml(): string
    {
        return match ($this) {
            self::CaddyWebServer => '<span class="badge bg-info">'.$this->label().'</span>',
            self::ApacheReverseProxy => '<span class="badge bg-secondary">'.$this->label().'</span>',
        };
    }
}
