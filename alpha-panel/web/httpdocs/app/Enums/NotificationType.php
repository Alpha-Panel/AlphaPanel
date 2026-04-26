<?php

namespace App\Enums;

enum NotificationType: string
{
    case DomainProvisioned = 'domain_provisioned';
    case DomainDeleted = 'domain_deleted';
    case DomainRenamed = 'domain_renamed';
    case FtpChanges = 'ftp_changes';
    case SslIssuance = 'ssl_issuance';
    case SslRenewal = 'ssl_renewal';
    case BackupStarted = 'backup_started';
    case BackupCompleted = 'backup_completed';
    case BackupFailed = 'backup_failed';
    case SystemUpdates = 'system_updates';
    case SystemAlert = 'system_alert';
    case AdminAnnouncements = 'admin_announcements';

    public function label(): string
    {
        return match ($this) {
            self::DomainProvisioned => __('Domain Provisioning'),
            self::DomainDeleted => __('Domain Deletion'),
            self::DomainRenamed => __('Domain Rename'),
            self::FtpChanges => __('FTP Account Changes'),
            self::SslIssuance => __('SSL Issuance'),
            self::SslRenewal => __('SSL Renewal'),
            self::BackupStarted => __('Backup Started'),
            self::BackupCompleted => __('Backup Completed'),
            self::BackupFailed => __('Backup Failed'),
            self::SystemUpdates => __('System Updates'),
            self::SystemAlert => __('System Alerts'),
            self::AdminAnnouncements => __('Admin Announcements'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DomainProvisioned => 'bx bx-plus-circle',
            self::DomainDeleted => 'bx bx-trash',
            self::DomainRenamed => 'bx bx-rename',
            self::FtpChanges => 'bx bx-transfer',
            self::SslIssuance => 'bx bx-lock-alt',
            self::SslRenewal => 'bx bx-refresh',
            self::BackupStarted => 'bx bx-cloud-upload',
            self::BackupCompleted => 'bx bx-check-circle',
            self::BackupFailed => 'bx bx-error-circle',
            self::SystemUpdates => 'bx bx-revision',
            self::SystemAlert => 'bx bx-error',
            self::AdminAnnouncements => 'bx bx-megaphone',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DomainProvisioned => __('New domain creation and provisioning results'),
            self::DomainDeleted => __('Domain deletion results'),
            self::DomainRenamed => __('Domain rename operation results'),
            self::FtpChanges => __('FTP user and password changes'),
            self::SslIssuance => __('Initial SSL certificate activation results'),
            self::SslRenewal => __('Automatic SSL certificate renewal results'),
            self::BackupStarted => __('Triggered when a backup operation begins'),
            self::BackupCompleted => __('Sent when a backup finishes successfully'),
            self::BackupFailed => __('Sent when a backup operation fails'),
            self::SystemUpdates => __('Available system updates and patches'),
            self::SystemAlert => __('CPU, RAM, and disk usage alerts and recovery'),
            self::AdminAnnouncements => __('Announcements sent by administrators'),
        };
    }

    public function group(): NotificationGroup
    {
        return match ($this) {
            self::DomainProvisioned, self::DomainDeleted, self::DomainRenamed => NotificationGroup::Domains,
            self::FtpChanges => NotificationGroup::Ftp,
            self::SslIssuance, self::SslRenewal => NotificationGroup::Ssl,
            self::BackupStarted, self::BackupCompleted, self::BackupFailed => NotificationGroup::Backups,
            self::SystemUpdates, self::SystemAlert => NotificationGroup::System,
            self::AdminAnnouncements => NotificationGroup::Announcements,
        };
    }
}
