import json
from unittest.mock import patch

import pytest

from installer.app import create_app


@pytest.fixture
def client(tmp_path):
    app = create_app(
        project_dir=tmp_path,
        state_file=tmp_path / ".installer_state.json",
    )
    app.config.update({"TESTING": True})
    return app.test_client()


def test_get_state_returns_null_when_no_state(client):
    resp = client.get("/api/state")
    assert resp.status_code == 200
    assert resp.get_json() == {"state": None}


def test_detect_returns_os_ip_info(client):
    with patch(
        "installer.app.detect_os",
        return_value={"id": "ubuntu", "pretty": "Ubuntu 22.04"},
    ), patch(
        "installer.app.detect_private_ip", return_value="10.0.0.5"
    ), patch(
        "installer.app.detect_public_ip", return_value="203.0.113.5"
    ):
        resp = client.post("/api/detect")
        assert resp.status_code == 200
        data = resp.get_json()
        assert data["os"]["id"] == "ubuntu"
        assert data["private_ip"] == "10.0.0.5"
        assert data["public_ip"] == "203.0.113.5"


def test_verify_cf_token_returns_ok_on_valid(client):
    with patch("installer.app.verify_token", return_value=True):
        resp = client.post(
            "/api/verify-cf-token",
            data=json.dumps({"token": "good"}),
            content_type="application/json",
        )
        assert resp.status_code == 200
        assert resp.get_json() == {"valid": True}


def test_verify_cf_token_returns_error_on_invalid(client):
    from installer.errors import InstallerError

    with patch(
        "installer.app.verify_token",
        side_effect=InstallerError("cloudflare_verify", "bad"),
    ):
        resp = client.post(
            "/api/verify-cf-token",
            data=json.dumps({"token": "bad"}),
            content_type="application/json",
        )
        assert resp.status_code == 400
        body = resp.get_json()
        assert body["valid"] is False
        assert body["phase"] == "cloudflare_verify"


def test_shutdown_schedules_exit(client):
    with patch("installer.app.threading.Timer") as mock_timer:
        resp = client.post("/api/shutdown")
        assert resp.status_code == 200
        mock_timer.assert_called_once()
