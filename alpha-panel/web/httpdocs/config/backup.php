<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
    ],

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 21),

    'chunk_size_mb' => (int) env('BACKUP_CHUNK_SIZE_MB', 100),

    'local_backup_base' => env('BACKUP_LOCAL_BASE', '/root/backup'),

    'vhosts_path' => env('VHOSTS_PATH', '/var/www/vhosts'),

    'vhosts_exclude' => ['AlphaPanel'],

    'mysql' => [
        'host' => env('DB_HOST', 'mysql'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'exclude' => ['information_schema', 'performance_schema', 'mysql', 'sys', 'phpmyadmin'],
    ],
];
