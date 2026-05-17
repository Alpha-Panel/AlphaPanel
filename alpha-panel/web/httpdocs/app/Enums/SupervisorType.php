<?php

namespace App\Enums;

enum SupervisorType: string
{
    case Queue = 'queue';
    case Reverb = 'reverb';
    case Pulse = 'pulse';
    case Horizon = 'horizon';
    case Ssr = 'ssr';

    public function label(): string
    {
        return match ($this) {
            self::Queue => __('Queue Worker'),
            self::Reverb => __('Reverb'),
            self::Pulse => __('Pulse'),
            self::Horizon => __('Horizon'),
            self::Ssr => __('SSR Server'),
        };
    }

    public function artisanCommand(): string
    {
        return match ($this) {
            self::Queue => 'queue:work --daemon',
            self::Reverb => 'reverb:start',
            self::Pulse => 'pulse:work',
            self::Horizon => 'horizon',
            self::Ssr => '',
        };
    }

    public function programSuffix(): string
    {
        return match ($this) {
            self::Queue => 'worker',
            self::Reverb => 'reverb',
            self::Pulse => 'pulse',
            self::Horizon => 'horizon',
            self::Ssr => 'ssr',
        };
    }

    public function logFile(): string
    {
        return match ($this) {
            self::Queue => 'worker.log',
            self::Reverb => 'reverb.log',
            self::Pulse => 'pulse.log',
            self::Horizon => 'horizon.log',
            self::Ssr => 'ssr.log',
        };
    }

    public function supportsNumProcs(): bool
    {
        return $this === self::Queue;
    }
}
