<?php

namespace App\Enums;

enum DomainStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case PendingCert = 'pending_cert';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Disabled => __('Disabled'),
            self::PendingCert => __('Pending Cert'),
            self::Failed => __('Failed'),
        };
    }

    public function badgeHtml(): string
    {
        return match ($this) {
            self::Active => '<span class="badge bg-success">'.$this->label().'</span>',
            self::Disabled => '<span class="badge bg-secondary">'.$this->label().'</span>',
            self::PendingCert => '<span class="badge bg-warning text-dark">'.$this->label().'</span>',
            self::Failed => '<span class="badge bg-danger">'.$this->label().'</span>',
        };
    }
}
