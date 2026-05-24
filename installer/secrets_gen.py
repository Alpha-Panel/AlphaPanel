from __future__ import annotations

import base64
import secrets


def gen_hex(num_bytes: int = 32) -> str:
    """Return a random hex string of `2 * num_bytes` characters."""
    return secrets.token_hex(num_bytes)


def gen_b64(num_bytes: int = 32) -> str:
    """Return a base64-encoded random string, no trailing newline."""
    return base64.b64encode(secrets.token_bytes(num_bytes)).decode("ascii")


def gen_all_panel_secrets() -> dict[str, str]:
    """
    Generate every internal credential needed by the stack.

    Returns a flat dict mirroring the variable names used in root `.env`
    and Laravel `.env`, in snake_case. The caller maps keys to the UPPER_CASE
    env var names.
    """
    return {
        "mysql_root_password": gen_hex(16),
        "meilisearch_master_key": gen_hex(32),
        "alpha_panel_meilisearch_master_key": gen_hex(32),
        "postgresql_password": gen_hex(16),
        "n8n_encryption_key": gen_hex(32),
        "pma_blowfish_secret": gen_hex(16),
        "vaultwarden_db_password": gen_hex(16),
        "panel_db_pass": gen_hex(16),
        "ftp_mysql_password": gen_hex(16),
        "crowdsec_firewall_bouncer_key": gen_hex(32),
        "crowdsec_dashboard_api_key": gen_hex(32),
        "update_agent_secret": gen_hex(32),
        "reverb_app_id": gen_hex(4),
        "reverb_app_key": gen_hex(16),
        "reverb_app_secret": gen_hex(32),
        "app_key": f"base64:{gen_b64(32)}",
        "code_server_password": gen_hex(12),
        "mail_admin_password": gen_hex(12),
        "mail_secret_key": gen_hex(8),
    }
