<?php

namespace App\Enums;

enum DomainType: string
{
    case ApacheReverseProxy = 'apache_reverse_proxy';
    case CaddyWebServer = 'caddy_web_server';
    case CaddyFastCgi = 'caddy_fastcgi';

    public function label(): string
    {
        return match ($this) {
            self::CaddyWebServer => __('Caddy Web Server'),
            self::CaddyFastCgi => __('Caddy + FastCGI'),
            self::ApacheReverseProxy => __('Apache + Reverse Proxy'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::CaddyWebServer => 'Caddy',
            self::CaddyFastCgi => 'Caddy+FPM',
            self::ApacheReverseProxy => 'Apache',
        };
    }

    public function badgeHtml(): string
    {
        return match ($this) {
            self::CaddyWebServer => '<span class="badge bg-info">'.$this->label().'</span>',
            self::CaddyFastCgi => '<span class="badge bg-primary">'.$this->label().'</span>',
            self::ApacheReverseProxy => '<span class="badge bg-secondary">'.$this->label().'</span>',
        };
    }
}
