<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Path Configuration
    |--------------------------------------------------------------------------
    */
    'caddy_main_config' => env('PANEL_CADDY_MAIN_CONFIG', '/etc/frankenphp-container/Caddyfile'),
    'caddy_reload_config' => env('PANEL_CADDY_RELOAD_CONFIG', '/etc/frankenphp/Caddyfile'),
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
    'portainer_certbot_image' => env('PORTAINER_CERTBOT_IMAGE', 'certbot/dns-cloudflare:v5.4.0'),

    /*
    |--------------------------------------------------------------------------
    | FTP Configuration
    |--------------------------------------------------------------------------
    */
    'ftp_users_env_path' => env('PANEL_FTP_USERS_ENV', '/docker_compose_project_root/ftp-config/users.env'),
    'ftp_container' => env('PANEL_FTP_CONTAINER', 'ftp-server'),
    'ftp_host' => env('PANEL_FTP_HOST', 'ftp'),
    'ftp_port' => (int) env('PANEL_FTP_PORT', 21),
    'ftp_ssl' => (bool) env('PANEL_FTP_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare DNS
    |--------------------------------------------------------------------------
    */
    'cloudflare_api_token' => env('CLOUDFLARE_API_TOKEN'),
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
    'certbot_email' => env('PANEL_CERTBOT_EMAIL', env('PANEL_ADMIN_EMAIL')),
    'certbot_staging' => (bool) env('PANEL_CERTBOT_STAGING', false),

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
    | SSH Terminal (Host Machine Access)
    |--------------------------------------------------------------------------
    | SSH connection from the container to the host machine.
    | The container connects via SSH key authentication.
    */
    'ssh_host' => env('PANEL_SSH_HOST', 'host.docker.internal'),
    'ssh_port' => (int) env('PANEL_SSH_PORT', 22),
    'ssh_user' => env('PANEL_SSH_USER', 'root'),
    'ssh_key_path' => env('PANEL_SSH_KEY_PATH', '/root/.ssh/alphapanel_ed25519'),

    /*
    |--------------------------------------------------------------------------
    | WebAuthn
    |--------------------------------------------------------------------------
    */
    'webauthn_required_for_admin' => env('PANEL_WEBAUTHN_REQUIRED_FOR_ADMIN', false),

    /*
    |--------------------------------------------------------------------------
    | System Updates
    |--------------------------------------------------------------------------
    */
    'update' => [
        'github_repo' => env('PANEL_GITHUB_REPO', 'alphapanel/alphapanel-docker'),
        'agent_url' => env('UPDATE_AGENT_URL', 'http://update-agent:8100'),
        'agent_secret' => env('UPDATE_AGENT_SECRET'),
        'check_interval' => (int) env('UPDATE_CHECK_INTERVAL', 86400),
        'auto_check' => (bool) env('UPDATE_AUTO_CHECK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Docker Services
    |--------------------------------------------------------------------------
    */
    'docker_services' => [
        'compose_dir' => env('DOCKER_SERVICES_COMPOSE_DIR', '/docker_compose_project_root/external-services/docker-services'),
        'local_services_path' => env('LOCAL_SERVICES_COMPOSE_PATH', '/docker_compose_project_root/external-services/local-services.yaml'),
        'volume_base_path' => env('DOCKER_SERVICES_VOLUME_BASE', '/docker_compose_project_root/external-services'),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Reserved Domains
    |--------------------------------------------------------------------------
    | These domains are used by system services and cannot be registered
    | as customer domains or subdomains in the panel.
    */
    'system_reserved_domains' => array_values(array_filter(array_map(
        fn ($v) => is_string($v) ? trim($v) : null,
        [
            env('PANEL_DOMAIN'),
            env('PMA_DOMAIN'),
            env('CODE_SERVER_DOMAIN'),
            env('VAULTWARDEN_DOMAIN'),
            env('N8N_DOMAIN'),
            env('PORTAINER_DOMAIN'),
            env('JENKINS_DOMAIN'),
        ],
    ))),
];
