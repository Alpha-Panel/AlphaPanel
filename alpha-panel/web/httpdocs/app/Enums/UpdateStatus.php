<?php

namespace App\Enums;

enum UpdateStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::InProgress => __('In Progress'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
            self::RolledBack => __('Rolled Back'),
        };
    }
}
