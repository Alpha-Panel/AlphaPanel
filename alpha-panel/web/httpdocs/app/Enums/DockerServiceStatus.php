<?php

namespace App\Enums;

enum DockerServiceStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Running => __('Running'),
            self::Stopped => __('Stopped'),
            self::Failed => __('Failed'),
            self::Removing => __('Removing'),
        };
    }

    public function badgeHtml(): string
    {
        return match ($this) {
            self::Pending => '<span class="badge bg-warning text-dark">'.$this->label().'</span>',
            self::Running => '<span class="badge bg-success">'.$this->label().'</span>',
            self::Stopped => '<span class="badge bg-secondary">'.$this->label().'</span>',
            self::Failed => '<span class="badge bg-danger">'.$this->label().'</span>',
            self::Removing => '<span class="badge bg-warning text-dark">'.$this->label().'</span>',
        };
    }
}
