<?php

namespace App\Enums;

enum DomainMode: string
{
    case Main = 'main';
    case Subdomain = 'subdomain';
    case Addon = 'addon';
    case WildcardSubdomain = 'wildcard_subdomain';
    case WildcardCatchall = 'wildcard_catchall';

    public function label(): string
    {
        return match ($this) {
            self::Main => __('Main Domain'),
            self::Subdomain => __('Subdomain'),
            self::Addon => __('Addon Domain'),
            self::WildcardSubdomain => __('Wildcard Subdomain'),
            self::WildcardCatchall => __('Wildcard Catch-All'),
        };
    }
}
