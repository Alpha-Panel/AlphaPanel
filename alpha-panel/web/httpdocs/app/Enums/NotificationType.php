<?php

namespace App\Enums;

enum NotificationType: string
{
    case DomainNotifications = 'domain_notifications';
    case BackupNotifications = 'backup_notifications';
    case SystemUpdates = 'system_updates';
    case AdminAnnouncements = 'admin_announcements';

    public function label(): string
    {
        return match ($this) {
            self::DomainNotifications => __('Domain Notifications'),
            self::BackupNotifications => __('Backup Notifications'),
            self::SystemUpdates => __('System Updates'),
            self::AdminAnnouncements => __('Admin Announcements'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DomainNotifications => 'bx bx-globe',
            self::BackupNotifications => 'bx bx-cloud-upload',
            self::SystemUpdates => 'bx bx-revision',
            self::AdminAnnouncements => 'bx bx-megaphone',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DomainNotifications => __('Provisioning, renaming, deletion, and SSL operations'),
            self::BackupNotifications => __('Backup upload status and results'),
            self::SystemUpdates => __('Available system updates and patches'),
            self::AdminAnnouncements => __('Announcements sent by administrators'),
        };
    }
}
