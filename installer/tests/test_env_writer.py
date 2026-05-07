import sys
from pathlib import Path

import pytest

from installer.steps.env_writer import write_laravel_env, write_root_env


@pytest.fixture
def form_and_secrets():
    form = {
        "base_domain": "example.com",
        "panel_domain": "server.example.com",
        "pma_domain": "pma.example.com",
        "code_server_domain": "file.example.com",
        "vaultwarden_domain": "password.example.com",
        "n8n_domain": "n8n.example.com",
        "portainer_domain": "portainer.example.com",
        "jenkins_domain": "jenkins.example.com",
        "jenkins_admin_ips": "",
        "cf_api_token": "cf-token-abc",
        "admin_email": "admin@example.com",
        "panel_admin_name": "Admin User",
        "panel_admin_username": "admin",
        "panel_admin_email": "admin@example.com",
        "panel_admin_password": "supersecret",
        "private_ip": "10.0.0.5",
        "public_ip": "203.0.113.5",
    }
    secrets = {
        "mysql_root_password": "mysqlroot",
        "meilisearch_master_key": "meilikey",
        "alpha_panel_meilisearch_master_key": "apmeilikey",
        "postgresql_password": "pgpass",
        "n8n_encryption_key": "n8nkey",
        "pma_blowfish_secret": "pmabf",
        "vaultwarden_db_password": "vwdbpass",
        "panel_db_pass": "paneldbpass",
        "ftp_mysql_password": "ftpdb",
        "crowdsec_firewall_bouncer_key": "cs-fw",
        "crowdsec_dashboard_api_key": "cs-dash",
        "update_agent_secret": "update-secret",
        "reverb_app_id": "abcd",
        "reverb_app_key": "reverbkey",
        "reverb_app_secret": "reverbsecret",
        "app_key": "base64:AAAA",
        "code_server_password": "cspass",
    }
    return form, secrets


def test_write_root_env_includes_expected_keys(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    path = tmp_path / ".env"
    write_root_env(path, form=form, secrets=secrets)
    content = path.read_text()
    for expected in [
        "CF_API_TOKEN=cf-token-abc",
        "ADMIN_EMAIL=admin@example.com",
        'MYSQL_ROOT_PASSWORD="mysqlroot"',
        "MYSQL_DATABASE=AlphaPanel",
        "BASE_DOMAIN=example.com",
        "PANEL_DOMAIN=server.example.com",
        "PORTAINER_DOMAIN=portainer.example.com",
        'MEILISEARCH_MASTER_KEY="meilikey"',
        "PRIVATE_NETWORK_IP=10.0.0.5",
        "PUBLIC_NETWORK_IP=203.0.113.5",
        "REVERB_APP_ID=abcd",
    ]:
        assert expected in content, f"missing in root .env: {expected}"


@pytest.mark.skipif(sys.platform == "win32", reason="chmod 600 has no effect on Windows NTFS")
def test_write_root_env_chmods_600(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    path = tmp_path / ".env"
    write_root_env(path, form=form, secrets=secrets)
    assert (path.stat().st_mode & 0o777) == 0o600


def test_write_laravel_env_substitutes_keys(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    example = tmp_path / ".env.example"
    example.write_text(
        "APP_NAME=Laravel\n"
        "APP_ENV=local\n"
        "APP_KEY=\n"
        "APP_DEBUG=true\n"
        "APP_URL=http://localhost\n"
        "APP_LOCALE=tr\n"
        "DB_CONNECTION=sqlite\n"
        "# DB_HOST=127.0.0.1\n"
        "# DB_PORT=3306\n"
        "# DB_DATABASE=laravel\n"
        "# DB_USERNAME=root\n"
        "# DB_PASSWORD=\n"
        "CACHE_STORE=database\n"
        "QUEUE_CONNECTION=database\n"
        "REDIS_HOST=127.0.0.1\n"
        "REVERB_APP_ID=\n"
        "REVERB_APP_KEY=\n"
        "REVERB_APP_SECRET=\n"
        "REVERB_HOST=localhost\n"
        "REVERB_PORT=8080\n"
        "REVERB_SCHEME=http\n"
        "VITE_REVERB_PORT=${REVERB_PORT}\n"
        "LOG_LEVEL=debug\n"
        "SESSION_DOMAIN=null\n"
        "MAIL_FROM_ADDRESS=hello@example.com\n"
        "PANEL_ADMIN_NAME=\n"
        "PANEL_ADMIN_USERNAME=\n"
        "PANEL_ADMIN_EMAIL=\n"
        "PANEL_ADMIN_PASSWORD=\n"
    )
    target = tmp_path / ".env"
    write_laravel_env(
        target=target,
        example=example,
        form=form,
        secrets=secrets,
        install_dir="/opt/alphapanel-docker",
    )
    content = target.read_text()

    assert "APP_NAME=AlphaPanel" in content
    assert "APP_ENV=production" in content
    assert "APP_KEY=base64:AAAA" in content
    assert "APP_DEBUG=false" in content
    assert "APP_URL=https://server.example.com:8443" in content
    assert "APP_LOCALE=en" in content
    assert "DB_CONNECTION=mysql" in content
    assert "DB_HOST=mysql" in content
    assert "DB_PORT=3306" in content
    assert "DB_DATABASE=AlphaPanel" in content
    assert "DB_USERNAME=alphapanel" in content
    assert "DB_PASSWORD=paneldbpass" in content
    assert "CACHE_STORE=redis" in content
    assert "QUEUE_CONNECTION=redis" in content
    assert "REDIS_HOST=redis" in content
    assert "REVERB_APP_ID=abcd" in content
    assert "REVERB_APP_KEY=reverbkey" in content
    assert "REVERB_APP_SECRET=reverbsecret" in content
    assert "REVERB_HOST=server.example.com" in content
    assert "REVERB_PORT=443" in content
    assert "REVERB_SCHEME=https" in content
    assert "VITE_REVERB_PORT=8443" in content
    assert "SESSION_DOMAIN=server.example.com" in content
    assert 'MAIL_FROM_ADDRESS="admin@example.com"' in content


def test_write_laravel_env_appends_production_block(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    example = tmp_path / ".env.example"
    example.write_text("APP_NAME=Laravel\n")
    target = tmp_path / ".env"
    write_laravel_env(
        target=target,
        example=example,
        form=form,
        secrets=secrets,
        install_dir="/opt/alphapanel-docker",
    )
    content = target.read_text()

    assert "SCOUT_DRIVER=meilisearch" in content
    assert "MEILISEARCH_HOST=http://alpha_panel_meilisearch:7700" in content
    assert "MEILISEARCH_KEY=apmeilikey" in content
    assert "PANEL_CADDY_MAIN_CONFIG=/etc/frankenphp-container/Caddyfile" in content
    assert "PANEL_CADDY_SITES_BASE=/etc/frankenphp-container/sites-enabled" in content
    assert "PANEL_CADDY_ADMIN_URL=http://frankenphp:2019" in content
    assert "PANEL_FRANKENPHP_CONTAINER=frankenphp" in content
    assert "PANEL_PHP_CODE_SERVER_CONTAINER=php-code-server" in content
    assert "COMPOSE_PROJECT_ROOT_HOST=/opt/alphapanel-docker" in content
    assert "PORTAINER_URL=http://portainer:9000" in content
    assert "PMA_URL=https://pma.example.com:8443/index.php?server=2" in content
    assert "PHPMYADMIN_URL=https://pma.example.com" in content
    assert "PMA_ADMIN_USER=root" in content
    assert "PMA_ADMIN_PASS=mysqlroot" in content
    assert "JENKINS_URL=https://jenkins.example.com" in content
    assert "UPDATE_AGENT_SECRET=update-secret" in content
    assert "UPDATE_AGENT_URL=http://update-agent:8100" in content


@pytest.mark.skipif(sys.platform == "win32", reason="chmod 600 has no effect on Windows NTFS")
def test_write_laravel_env_chmods_600(tmp_path: Path, form_and_secrets):
    form, secrets = form_and_secrets
    example = tmp_path / ".env.example"
    example.write_text("APP_NAME=Laravel\n")
    target = tmp_path / ".env"
    write_laravel_env(
        target=target,
        example=example,
        form=form,
        secrets=secrets,
        install_dir="/opt/alphapanel-docker",
    )
    assert (target.stat().st_mode & 0o777) == 0o600
