from unittest.mock import MagicMock, patch

import pytest

from installer.errors import InstallerError
from installer.steps.cloudflare import verify_token, write_cloudflare_ini


def test_write_cloudflare_ini_writes_with_mode_600(tmp_path):
    target = tmp_path / "secrets" / "cloudflare.ini"
    write_cloudflare_ini(target, token="abc123")
    content = target.read_text()
    assert "dns_cloudflare_api_token = abc123" in content
    assert (target.stat().st_mode & 0o777) == 0o600


def test_verify_token_returns_true_on_active_status():
    fake_response = MagicMock()
    fake_response.status_code = 200
    fake_response.json.return_value = {"success": True, "result": {"status": "active"}}
    with patch("installer.steps.cloudflare.requests.get", return_value=fake_response):
        assert verify_token("valid-token") is True


def test_verify_token_raises_installer_error_on_non_active():
    fake_response = MagicMock()
    fake_response.status_code = 200
    fake_response.json.return_value = {"success": True, "result": {"status": "disabled"}}
    with patch("installer.steps.cloudflare.requests.get", return_value=fake_response):
        with pytest.raises(InstallerError) as exc:
            verify_token("bad-token")
        assert exc.value.phase == "cloudflare_verify"


def test_verify_token_raises_installer_error_on_http_error():
    fake_response = MagicMock()
    fake_response.status_code = 401
    fake_response.json.return_value = {"success": False, "errors": [{"message": "Unauthorized"}]}
    fake_response.text = "Unauthorized"
    with patch("installer.steps.cloudflare.requests.get", return_value=fake_response):
        with pytest.raises(InstallerError):
            verify_token("bad-token")
