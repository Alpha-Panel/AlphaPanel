<?php

namespace App\Enums;

enum UpdateType: string
{
    case PanelUpdate = 'panel_update';
    case MysqlUpgrade = 'mysql_upgrade';
    case ServiceUpdate = 'service_update';

    public function label(): string
    {
        return match ($this) {
            self::PanelUpdate => __('Panel Update'),
            self::MysqlUpgrade => __('MySQL Upgrade'),
            self::ServiceUpdate => __('Service Update'),
        };
    }
}
