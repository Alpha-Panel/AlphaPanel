<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'jenkins_url' => env('JENKINS_URL'),
    'n8n_url' => env('N8N_URL'),
    'file_manager_url' => env('FILE_MANAGER_URL'),
    'pma_url' => env('PHPMYADMIN_URL'),

    'phpmyadmin' => [
        'base_url' => env('PHPMYADMIN_URL'),
        'admin_user' => env('PMA_ADMIN_USER', 'root'),
        'admin_pass' => env('PMA_ADMIN_PASS', ''),
        'mysql_host' => env('PMA_MYSQL_HOST', 'mysql'),
        'mysql_port' => (int) env('PMA_MYSQL_PORT', 3306),
        'token_ttl_seconds' => (int) env('PMA_SSO_TOKEN_TTL', 120),
    ],

];
