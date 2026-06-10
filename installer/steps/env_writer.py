from __future__ import annotations

import os
import re
from pathlib import Path
from typing import Any

_PANEL_DB_NAME = "AlphaPanel"
_PANEL_DB_USER = "alphapanel"
_POSTGRESQL_USER = "admin"


def _env_line(key: str, value: str, quoted: bool = False) -> str:
    if quoted:
        return f'{key}="{value}"\n'
    return f"{key}={value}\n"


def write_root_env(path: Path, form: dict[str, Any], secrets: dict[str, str]) -> None:
    """Write the Compose-level `.env` used by docker compose variable substitution."""
    lines: list[str] = []
    add = lines.append

    add("# ─── Cloudflare ───\n")
    add(_env_line("CF_API_TOKEN", form["cf_api_token"]))
    add("\n# ─── Admin ───\n")
    add(_env_line("ADMIN_EMAIL", form["admin_email"]))

    add("\n# ─── MySQL ───\n")
    add(_env_line("MYSQL_VERSION", "9.3.0"))
    add(_env_line("MYSQL_ROOT_PASSWORD", secrets["mysql_root_password"], quoted=True))
    add(_env_line("MYSQL_DATABASE", _PANEL_DB_NAME))

    add("\n# ─── FTP ───\n")
    add(_env_line("FTP_MYSQL_PASSWORD", secrets["ftp_mysql_password"], quoted=True))

    add("\n# ─── Meilisearch ───\n")
    add(_env_line("MEILISEARCH_MASTER_KEY", secrets["meilisearch_master_key"], quoted=True))
    add(
        _env_line(
            "ALPHA_PANEL_MEILISEARCH_MASTER_KEY",
            secrets["alpha_panel_meilisearch_master_key"],
            quoted=True,
        )
    )

    add("\n# ─── PostgreSQL (N8N) ───\n")
    add(_env_line("POSTGRESQL_USER", _POSTGRESQL_USER))
    add(_env_line("POSTGRESQL_PASSWORD", secrets["postgresql_password"], quoted=True))

    add("\n# ─── Network ───\n")
    add(_env_line("PRIVATE_NETWORK_IP", form["private_ip"]))
    add(_env_line("PUBLIC_NETWORK_IP", form["public_ip"]))

    add("\n# ─── Domains ───\n")
    for key in [
        "base_domain",
        "panel_domain",
        "pma_domain",
        "code_server_domain",
        "vaultwarden_domain",
        "n8n_domain",
        "portainer_domain",
        "jenkins_domain",
    ]:
        add(_env_line(key.upper(), form[key]))
    add(_env_line("JENKINS_ADMIN_IPS", form.get("jenkins_admin_ips", "")))

    add("\n# ─── Vaultwarden ───\n")
    add(_env_line("VAULTWARDEN_DB_HOST", "mysql"))
    add(_env_line("VAULTWARDEN_DB_NAME", "bitwarden"))
    add(_env_line("VAULTWARDEN_DB_USER", "bitwarden"))
    add(_env_line("VAULTWARDEN_DB_PASSWORD", secrets["vaultwarden_db_password"], quoted=True))

    add("\n# ─── Code Server ───\n")
    add(_env_line("CODE_SERVER_PASSWORD", secrets["code_server_password"], quoted=True))
    add(_env_line("CODE_SERVER_SUDO_PASSWORD", secrets["code_server_password"], quoted=True))
    add(_env_line("CODE_SERVER_PWA_APP_NAME", "AlphaPanel Code Server"))

    add("\n# ─── N8N ───\n")
    add(_env_line("N8N_EMAIL_MODE", "smtp"))
    add(_env_line("N8N_SMTP_HOST", ""))
    add(_env_line("N8N_SMTP_PORT", "587"))
    add(_env_line("N8N_SMTP_USER", ""))
    add(_env_line("N8N_SMTP_PASS", ""))
    add(_env_line("N8N_SMTP_SENDER", ""))
    add(_env_line("N8N_SMTP_SSL", "false"))
    add(_env_line("N8N_ENCRYPTION_KEY", secrets["n8n_encryption_key"], quoted=True))

    add("\n# ─── phpMyAdmin ───\n")
    add(_env_line("PMA_BLOWFISH_SECRET", secrets["pma_blowfish_secret"]))
    add(_env_line("PANEL_DB_NAME", _PANEL_DB_NAME))
    add(_env_line("PANEL_DB_USER", _PANEL_DB_USER))
    add(_env_line("PANEL_DB_PASS", secrets["panel_db_pass"]))
    add(_env_line("PMA_URL", f"https://{form['pma_domain']}:8443/index.php?server=2"))
    add(_env_line("PANEL_APP_KEY", secrets["app_key"]))

    add("\n# ─── Update Agent ───\n")
    add(_env_line("UPDATE_AGENT_SECRET", secrets["update_agent_secret"]))
    add(_env_line("PANEL_GITHUB_REPO", "Alpha-Panel/AlphaPanel"))
    add(_env_line("MYSQL_UPDATE_CHECK", "true"))

    add("\n# ─── CrowdSec ───\n")
    add(_env_line("CROWDSEC_FIREWALL_BOUNCER_KEY", secrets["crowdsec_firewall_bouncer_key"]))
    add(_env_line("CROWDSEC_DASHBOARD_API_KEY", secrets["crowdsec_dashboard_api_key"]))
    add(_env_line("CROWDSEC_LAPI_URL", "http://crowdsec:8080"))

    add("\n# ─── Redis ───\n")
    add(_env_line("REDIS_PASSWORD", secrets["redis_password"]))

    add("\n# ─── Reverb ───\n")
    add(_env_line("REVERB_APP_ID", secrets["reverb_app_id"]))
    add(_env_line("REVERB_APP_KEY", secrets["reverb_app_key"]))
    add(_env_line("REVERB_APP_SECRET", secrets["reverb_app_secret"]))

    add("\n# ─── Mail Server (Mailu) ───\n")
    add(_env_line("MAIL_ENABLED", "true" if form.get("mail_enabled") else "false"))
    add(_env_line("MAIL_DOMAIN", form.get("mail_domain", "")))
    add(_env_line("MAIL_HOSTNAME", form.get("mail_hostname", form.get("mail_domain", ""))))
    add(_env_line("MAIL_DATA_PATH", form.get("mail_data_path", "./mail-data")))
    add(_env_line("MAIL_ADMIN_PASSWORD", secrets.get("mail_admin_password", ""), quoted=True))
    add(_env_line("MAIL_SECRET_KEY", secrets.get("mail_secret_key", ""), quoted=True))
    add(_env_line("MAIL_WEBMAIL", form.get("mail_webmail", "snappymail")))
    add(_env_line("MAIL_ANTIVIRUS", form.get("mail_antivirus", "none")))
    add(_env_line("MAIL_MESSAGE_SIZE_LIMIT", form.get("mail_message_size_limit", "50000000")))
    add(_env_line("MAIL_RELAY_HOST", form.get("mail_relay_host", "")))
    add(_env_line("MAIL_RELAY_PORT", form.get("mail_relay_port", "587")))
    add(_env_line("MAIL_RELAY_USERNAME", form.get("mail_relay_username", "")))
    add(_env_line("MAIL_RELAY_PASSWORD", form.get("mail_relay_password", ""), quoted=True))

    add("\n# ─── Zimbra Bridge ───\n")
    add(_env_line("ZIMBRA_ENABLED", "true" if form.get("zimbra_enabled") else "false"))
    add(_env_line("ZIMBRA_ADMIN_URL", form.get("zimbra_admin_url", "")))
    add(_env_line("ZIMBRA_ADMIN_USER", form.get("zimbra_admin_user", "")))
    add(_env_line("ZIMBRA_ADMIN_PASSWORD", form.get("zimbra_admin_password", ""), quoted=True))
    add(_env_line("ZIMBRA_DEFAULT_MX_HOST", form.get("zimbra_default_mx_host", "")))
    add(_env_line("ZIMBRA_DEFAULT_MX_PRIORITY", str(form.get("zimbra_default_mx_priority", 10))))
    add(_env_line("ZIMBRA_DEFAULT_SPF_INCLUDE", form.get("zimbra_default_spf_include", "")))
    add(_env_line("ZIMBRA_VERIFY_TLS", "true" if form.get("zimbra_verify_tls", True) else "false"))
    add(_env_line("ZIMBRA_TIMEOUT_SECONDS", str(form.get("zimbra_timeout_seconds", 15))))

    path.write_text("".join(lines), encoding="utf-8")
    os.chmod(path, 0o600)


_STATIC_LARAVEL_REPLACEMENTS = {
    "APP_NAME": ("AlphaPanel", False),
    "APP_ENV": ("production", False),
    "APP_DEBUG": ("false", False),
    "APP_LOCALE": ("en", False),
    "DB_CONNECTION": ("mysql", False),
    "CACHE_STORE": ("redis", False),
    "CACHE_PREFIX": ("alpha_panel_", False),
    "QUEUE_CONNECTION": ("redis", False),
    "REDIS_HOST": ("redis", False),
    "REVERB_PORT": ("443", False),
    "REVERB_SCHEME": ("https", False),
    # Browser accesses the panel at :8443; REVERB_PORT=443 is container-internal only
    "VITE_REVERB_PORT": ("8443", False),
    "LOG_LEVEL": ("error", False),
    "DB_HOST": ("mysql", False),
    "DB_PORT": ("3306", False),
    "DB_DATABASE": (_PANEL_DB_NAME, False),
    "DB_USERNAME": (_PANEL_DB_USER, False),
    "SESSION_SECURE_COOKIE": ("true", False),
}


def _replace_env_line(text: str, key: str, value: str, quoted: bool = False) -> str:
    """
    Replace lines like `KEY=old` or `# KEY=old` with `KEY=value`.
    If the key does not exist in the file, append it.
    """
    pattern = rf"^#?\s*{re.escape(key)}=.*$"
    replacement = f'{key}="{value}"' if quoted else f"{key}={value}"
    if re.search(pattern, text, flags=re.MULTILINE):
        return re.sub(pattern, replacement, text, flags=re.MULTILINE)
    if not text.endswith("\n"):
        text += "\n"
    return text + replacement + "\n"


def write_laravel_env(
    target: Path,
    example: Path,
    form: dict[str, Any],
    secrets: dict[str, str],
    install_dir: str,
) -> None:
    text = example.read_text(encoding="utf-8")

    for key, (value, quoted) in _STATIC_LARAVEL_REPLACEMENTS.items():
        text = _replace_env_line(text, key, value, quoted)

    text = _replace_env_line(text, "APP_KEY", secrets["app_key"])
    text = _replace_env_line(text, "APP_URL", f"https://{form['panel_domain']}:8443")
    text = _replace_env_line(text, "DB_PASSWORD", secrets["panel_db_pass"])
    text = _replace_env_line(text, "REDIS_PASSWORD", secrets["redis_password"])
    text = _replace_env_line(text, "CLOUDFLARE_API_TOKEN", form["cf_api_token"])
    text = _replace_env_line(text, "REVERB_APP_ID", secrets["reverb_app_id"])
    text = _replace_env_line(text, "REVERB_APP_KEY", secrets["reverb_app_key"])
    text = _replace_env_line(text, "REVERB_APP_SECRET", secrets["reverb_app_secret"])
    text = _replace_env_line(text, "REVERB_HOST", form["panel_domain"])
    text = _replace_env_line(text, "SESSION_DOMAIN", form["panel_domain"])
    text = _replace_env_line(text, "MAIL_FROM_ADDRESS", form["admin_email"], quoted=True)

    pma_domain = form["pma_domain"]
    jenkins_domain = form["jenkins_domain"]
    meili_key = secrets["alpha_panel_meilisearch_master_key"]
    panel_db_pass = secrets["panel_db_pass"]
    mysql_root_password = secrets["mysql_root_password"]
    crowdsec_dash_key = secrets["crowdsec_dashboard_api_key"]
    update_agent_secret = secrets["update_agent_secret"]

    appended = f"""
# ─── Search ───
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://alpha_panel_meilisearch:7700
MEILISEARCH_KEY={meili_key}

# ─── Panel config ───
PANEL_CADDY_MAIN_CONFIG=/etc/frankenphp-container/Caddyfile
PANEL_CADDY_SITES_BASE=/etc/frankenphp-container/sites-enabled
PANEL_CADDY_ADMIN_URL=http://frankenphp:2019
PANEL_FRANKENPHP_CONTAINER=frankenphp
PANEL_PHP_CODE_SERVER_CONTAINER=php-code-server
PANEL_DOCKER_TIMEOUT=15

COMPOSE_PROJECT_ROOT=/docker_compose_project_root
COMPOSE_PROJECT_ROOT_HOST={install_dir}
PORTAINER_CERTBOT_IMAGE=alphapanel-docker-certbot-init:latest

# ─── Services ───
PORTAINER_URL=http://portainer:9000
PORTAINER_API_KEY=
PORTAINER_ENDPOINT_ID=1

PMA_URL=https://{pma_domain}:8443/index.php?server=2
PHPMYADMIN_URL=https://{pma_domain}
PMA_ADMIN_USER=root
PMA_ADMIN_PASS={mysql_root_password}
JENKINS_URL=https://{jenkins_domain}
PANEL_DB_HOST=mysql
PANEL_DB_PORT=3306
PANEL_DB_NAME={_PANEL_DB_NAME}
PANEL_DB_USER={_PANEL_DB_USER}
PANEL_DB_PASS={panel_db_pass}

# ─── CrowdSec ───
CROWDSEC_LAPI_URL=http://crowdsec:8080
CROWDSEC_DASHBOARD_API_KEY={crowdsec_dash_key}

# ─── SSH Terminal ───
PANEL_SSH_HOST=172.17.0.1
PANEL_SSH_PORT=22
PANEL_SSH_USER=root
PANEL_SSH_KEY_PATH=/root/.ssh/alphapanel_ed25519

# ─── Update Agent ───
UPDATE_AGENT_URL=http://update-agent:8100
UPDATE_AGENT_SECRET={update_agent_secret}
PANEL_GITHUB_REPO=Alpha-Panel/AlphaPanel
MYSQL_UPDATE_CHECK=true
UPDATE_AUTO_CHECK=true
"""
    if not text.endswith("\n"):
        text += "\n"
    text += appended

    target.write_text(text, encoding="utf-8")
    os.chmod(target, 0o600)


def set_portainer_credentials(laravel_env: Path, api_key: str, endpoint_id: int) -> None:
    """Call once Portainer is up and a token has been issued."""
    text = laravel_env.read_text(encoding="utf-8")
    text = _replace_env_line(text, "PORTAINER_API_KEY", api_key)
    text = _replace_env_line(text, "PORTAINER_ENDPOINT_ID", str(endpoint_id))
    laravel_env.write_text(text, encoding="utf-8")
