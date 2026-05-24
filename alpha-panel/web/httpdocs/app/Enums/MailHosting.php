<?php

namespace App\Enums;

enum MailHosting: string
{
    case Disabled = 'disabled';
    case Local = 'local';
    case Remote = 'remote';
    case Zimbra = 'zimbra';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => __('Disabled'),
            self::Local => __('Local (Mailu)'),
            self::Remote => __('Remote MX'),
            self::Zimbra => __('Zimbra'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::Local => 'Mailu',
            self::Remote => 'Remote',
            self::Zimbra => 'Zimbra',
        };
    }

    public function isManaged(): bool
    {
        return $this === self::Local || $this === self::Zimbra;
    }
}
