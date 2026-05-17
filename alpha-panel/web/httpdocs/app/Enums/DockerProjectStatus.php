<?php

namespace App\Enums;

enum DockerProjectStatus: string
{
    case Pending = 'pending';
    case Building = 'building';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Building => __('Building'),
            self::Running => __('Running'),
            self::Stopped => __('Stopped'),
            self::Failed => __('Failed'),
            self::Removing => __('Removing'),
        };
    }
}
