<?php

namespace App\Enums;

enum AlertLevel: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Warning => __('Warning'),
            self::Critical => __('Critical'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Warning => 'warning',
            self::Critical => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Warning => 'bx bx-error',
            self::Critical => 'bx bx-error-circle',
        };
    }
}
