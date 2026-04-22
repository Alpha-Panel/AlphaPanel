from installer.steps.directories import ensure_data_directories


def test_ensure_data_directories_creates_all_expected(tmp_path):
    ensure_data_directories(base=tmp_path, base_domain="example.com")

    expected = [
        "secrets",
        "letsencrypt",
        "deploy_cache",
        "backup",
        "portainer",
        "vaultwarden/data",
        "mysql/data",
        "postgres",
        "redis",
        "meilisearch/data",
        "meilisearch/tmp",
        "alpha-panel/meilisearch/data",
        "alpha-panel/meilisearch/tmp",
        "alpha-panel/web/logs",
        "alpha-panel/web/caddy_data",
        "alpha-panel/web/ssl",
        "frankenphp/caddy_data",
        "frankenphp/logs",
        "frankenphp/waf/generated/domains",
        "frankenphp/sites-enabled/example.com",
        "n8n/data",
        "n8n/files",
        "jenkins/data",
        "code-server/data",
        "external-services",
    ]
    for rel in expected:
        assert (tmp_path / rel).is_dir(), f"missing directory {rel}"


def test_ensure_data_directories_writes_waf_placeholders(tmp_path):
    ensure_data_directories(base=tmp_path, base_domain="example.com")
    assert (tmp_path / "frankenphp/waf/generated/global.conf").exists()
    assert (tmp_path / "frankenphp/waf/generated/domains/000-default.conf").exists()


def test_ensure_data_directories_is_idempotent(tmp_path):
    ensure_data_directories(base=tmp_path, base_domain="example.com")
    (tmp_path / "frankenphp/waf/generated/global.conf").write_text("custom\n")
    ensure_data_directories(base=tmp_path, base_domain="example.com")
    assert (tmp_path / "frankenphp/waf/generated/global.conf").read_text() == "custom\n"
