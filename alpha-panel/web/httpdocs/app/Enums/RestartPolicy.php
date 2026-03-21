<?php

namespace App\Enums;

enum RestartPolicy: string
{
    case No = 'no';
    case Always = 'always';
    case UnlessStopped = 'unless-stopped';
    case OnFailure = 'on-failure';

    public function label(): string
    {
        return match ($this) {
            self::No => __('No'),
            self::Always => __('Always'),
            self::UnlessStopped => __('Unless Stopped'),
            self::OnFailure => __('On Failure'),
        };
    }
}
