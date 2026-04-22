import re

from installer.secrets_gen import gen_all_panel_secrets, gen_b64, gen_hex


def test_gen_hex_default_32_bytes_returns_64_hex_chars():
    out = gen_hex()
    assert re.fullmatch(r"[0-9a-f]{64}", out)


def test_gen_hex_custom_length():
    assert len(gen_hex(16)) == 32  # 16 bytes → 32 hex chars
    assert len(gen_hex(4)) == 8


def test_gen_b64_returns_base64_without_newline():
    out = gen_b64()
    assert "\n" not in out
    assert re.fullmatch(r"[A-Za-z0-9+/=]+", out)
    # 32 bytes base64 → 44 chars with padding
    assert len(out) == 44


def test_gen_all_panel_secrets_returns_full_dict():
    s = gen_all_panel_secrets()
    expected_keys = {
        "mysql_root_password",
        "meilisearch_master_key",
        "alpha_panel_meilisearch_master_key",
        "postgresql_password",
        "n8n_encryption_key",
        "pma_blowfish_secret",
        "vaultwarden_db_password",
        "panel_db_pass",
        "ftp_mysql_password",
        "crowdsec_firewall_bouncer_key",
        "crowdsec_dashboard_api_key",
        "update_agent_secret",
        "reverb_app_id",
        "reverb_app_key",
        "reverb_app_secret",
        "app_key",
        "code_server_password",
    }
    assert set(s.keys()) == expected_keys
    for value in s.values():
        assert len(value) > 0
    assert s["app_key"].startswith("base64:")


def test_gen_all_panel_secrets_returns_unique_values_per_call():
    a = gen_all_panel_secrets()
    b = gen_all_panel_secrets()
    assert a != b
