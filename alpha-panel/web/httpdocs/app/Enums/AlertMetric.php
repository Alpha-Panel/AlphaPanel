<?php

namespace App\Enums;

enum AlertMetric: string
{
    case Cpu = 'cpu';
    case Ram = 'ram';
    case Disk = 'disk';

    public function label(): string
    {
        return match ($this) {
            self::Cpu => __('CPU'),
            self::Ram => __('RAM'),
            self::Disk => __('Disk'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cpu => 'bx bx-chip',
            self::Ram => 'bx bx-memory-card',
            self::Disk => 'bx bx-hdd',
        };
    }
}
