<?php

namespace App\Enums;

enum NotificationType: string
{
    case DomainProvisioned = 'domain_provisioned';
    case DomainDeleted = 'domain_deleted';
    case DomainRenamed = 'domain_renamed';
    case SslCertificate = 'ssl_certificate';
    case FtpChanges = 'ftp_changes';
    case BackupStatus = 'backup_status';
    case SystemUpdates = 'system_updates';
    case AdminAnnouncements = 'admin_announcements';

    public function label(): string
    {
        return match ($this) {
            self::DomainProvisioned => __('Domain Provisioning'),
            self::DomainDeleted => __('Domain Deletion'),
            self::DomainRenamed => __('Domain Rename'),
            self::SslCertificate => __('SSL Certificate'),
            self::FtpChanges => __('FTP Account Changes'),
            self::BackupStatus => __('Backup Operations'),
            self::SystemUpdates => __('System Updates'),
            self::AdminAnnouncements => __('Admin Announcements'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DomainProvisioned => 'bx bx-plus-circle',
            self::DomainDeleted => 'bx bx-trash',
            self::DomainRenamed => 'bx bx-rename',
            self::SslCertificate => 'bx bx-lock-alt',
            self::FtpChanges => 'bx bx-transfer',
            self::BackupStatus => 'bx bx-cloud-upload',
            self::SystemUpdates => 'bx bx-revision',
            self::AdminAnnouncements => 'bx bx-megaphone',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DomainProvisioned => __('New domain creation and provisioning results'),
            self::DomainDeleted => __('Domain deletion results'),
            self::DomainRenamed => __('Domain rename operation results'),
            self::SslCertificate => __('SSL certificate activation and renewal'),
            self::FtpChanges => __('FTP user and password changes'),
            self::BackupStatus => __('Backup start, completion, and failure'),
            self::SystemUpdates => __('Available system updates and patches'),
            self::AdminAnnouncements => __('Announcements sent by administrators'),
        };
    }
}
