<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Path Configuration
    |--------------------------------------------------------------------------
    */
    'caddy_main_config' => env('PANEL_CADDY_MAIN_CONFIG', '/etc/frankenphp-container/Caddyfile'),
    'caddy_sites_base' => env('PANEL_CADDY_SITES_BASE', '/etc/frankenphp-container/sites-enabled'),
    'apache_sites_base' => env('PANEL_APACHE_SITES_BASE', '/etc/apache2/sites-enabled'),
    'letsencrypt_base' => env('PANEL_LETSENCRYPT_BASE', '/etc/letsencrypt/live'),

    /*
    |--------------------------------------------------------------------------
    | Docker Configuration
    |--------------------------------------------------------------------------
    */
    'docker_timeout' => env('PANEL_DOCKER_TIMEOUT', 15),
    'frankenphp_container' => env('PANEL_FRANKENPHP_CONTAINER', 'frankenphp'),
    'php_code_server_container' => env('PANEL_PHP_CODE_SERVER_CONTAINER', 'php-code-server'),
    'caddy_admin_url' => env('PANEL_CADDY_ADMIN_URL', 'http://frankenphp:2019'),

    /*
    |--------------------------------------------------------------------------
    | Portainer Configuration
    |--------------------------------------------------------------------------
    */
    'portainer_url' => env('PORTAINER_URL'),
    'portainer_api_key' => env('PORTAINER_API_KEY'),
    'portainer_endpoint_id' => env('PORTAINER_ENDPOINT_ID', 1),
    'compose_project_root' => env('COMPOSE_PROJECT_ROOT', '/docker_compose_project_root'),
    'compose_project_root_host' => env('COMPOSE_PROJECT_ROOT_HOST', '/opt/alphapanel'),
    'portainer_certbot_image' => env('PORTAINER_CERTBOT_IMAGE', 'alphapanel-docker-certbot-init:latest'),

    /*
    |--------------------------------------------------------------------------
    | FTP Configuration
    |--------------------------------------------------------------------------
    */
    'ftp_users_env_path' => env('PANEL_FTP_USERS_ENV', '/docker_compose_project_root/ftp-config/users.env'),
    'ftp_container' => env('PANEL_FTP_CONTAINER', 'ftp-server'),
    'ftp_host' => env('PANEL_FTP_HOST', 'ftp'),
    'ftp_port' => (int) env('PANEL_FTP_PORT', 21),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare DNS
    |--------------------------------------------------------------------------
    */
    'cloudflare_email' => env('CLOUDFLARE_EMAIL'),
    'cloudflare_api_key' => env('CLOUDFLARE_APIKEY'),
    'server_ip' => env('PANEL_SERVER_IP', '127.0.0.1'),
    'server_public_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PUBLIC_NETWORK_IP', env('PANEL_SERVER_PUBLIC_IPS', env('PANEL_SERVER_IP', ''))))
    ))),
    'server_private_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PRIVATE_NETWORK_IP', env('PANEL_SERVER_PRIVATE_IPS', '')))
    ))),

    /*
    |--------------------------------------------------------------------------
    | MySQL Admin (for managed databases)
    |--------------------------------------------------------------------------
    */
    'db_admin_host' => env('DB_HOST', '127.0.0.1'),
    'db_admin_port' => env('DB_PORT', 3306),
    'db_admin_user' => env('DB_USERNAME', 'root'),
    'db_admin_pass' => env('DB_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Certbot
    |--------------------------------------------------------------------------
    */
    'certbot_email' => env('PANEL_CERTBOT_EMAIL', 'admin@example.com'),

    /*
    |--------------------------------------------------------------------------
    | Default Admin User (for seeding)
    |--------------------------------------------------------------------------
    */
    'admin_name' => env('PANEL_ADMIN_NAME', 'Admin User'),
    'admin_username' => env('PANEL_ADMIN_USERNAME', 'admin'),
    'admin_email' => env('PANEL_ADMIN_EMAIL', 'admin@example.com'),
    'admin_password' => env('PANEL_ADMIN_PASSWORD', 'change-me-now'),

    /*
    |--------------------------------------------------------------------------
    | Legacy Domain Settings
    |--------------------------------------------------------------------------
    */
    'legacy_derive_unix_user' => env('PANEL_LEGACY_DERIVE_UNIX_USER', false),
    'legacy_default_unix_user' => env('PANEL_LEGACY_DEFAULT_UNIX_USER', 'www-data'),
    'legacy_default_unix_group' => env('PANEL_LEGACY_DEFAULT_UNIX_GROUP', 'www-data'),

    /*
    |--------------------------------------------------------------------------
    | Terminal WebSocket Proxy
    |--------------------------------------------------------------------------
    | Port that `php artisan terminal:serve` listens on.
    | In local dev the browser connects directly: ws://127.0.0.1:{port}
    | In production Caddy proxies /terminal/ws → localhost:{port}
    */
    'terminal_ws_port' => (int) env('TERMINAL_WS_PORT', 2999),

    /*
    |--------------------------------------------------------------------------
    | WebAuthn
    |--------------------------------------------------------------------------
    */
    'webauthn_required_for_admin' => env('PANEL_WEBAUTHN_REQUIRED_FOR_ADMIN', false),
];
