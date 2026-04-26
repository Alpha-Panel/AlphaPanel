<?php

namespace App\Enums;

enum NotificationGroup: string
{
    case Domains = 'domains';
    case Ftp = 'ftp';
    case Ssl = 'ssl';
    case Backups = 'backups';
    case System = 'system';
    case Announcements = 'announcements';

    public function label(): string
    {
        return match ($this) {
            self::Domains => __('Domain Operations'),
            self::Ftp => __('FTP Operations'),
            self::Ssl => __('SSL Certificates'),
            self::Backups => __('Backup Operations'),
            self::System => __('System Health & Updates'),
            self::Announcements => __('Announcements'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Domains => __('Provisioning, deletion, and rename results for your domains.'),
            self::Ftp => __('FTP account credential changes.'),
            self::Ssl => __('SSL certificate activation and automatic renewal events.'),
            self::Backups => __('Lifecycle events for scheduled and manual backups.'),
            self::System => __('Resource usage alerts and available system updates.'),
            self::Announcements => __('Important announcements published by administrators.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Domains => 'bx bx-globe',
            self::Ftp => 'bx bx-transfer',
            self::Ssl => 'bx bx-lock-alt',
            self::Backups => 'bx bx-cloud-upload',
            self::System => 'bx bx-server',
            self::Announcements => 'bx bxs-megaphone',
        };
    }
}
