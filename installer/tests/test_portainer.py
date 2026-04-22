from unittest.mock import MagicMock, patch

import pytest

from installer.errors import InstallerError
from installer.steps.portainer import (
    create_access_token,
    detect_endpoint_id,
    init_portainer_admin,
    wait_for_portainer,
)


def _mk_response(status_code=200, json_body=None, text=""):
    resp = MagicMock()
    resp.status_code = status_code
    resp.json.return_value = json_body or {}
    resp.text = text
    return resp


def test_wait_for_portainer_returns_when_status_200():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.return_value = _mk_response(200, {"Version": "CE-2.x"})
        wait_for_portainer("http://localhost:9000", timeout=5, interval=0.01)


def test_wait_for_portainer_raises_after_timeout():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.side_effect = Exception("connection refused")
        with pytest.raises(InstallerError) as exc:
            wait_for_portainer("http://localhost:9000", timeout=0.05, interval=0.01)
        assert exc.value.phase == "portainer_wait"


def test_init_portainer_admin_posts_credentials():
    with patch("installer.steps.portainer.requests.post") as mock_post:
        mock_post.return_value = _mk_response(200, {"Id": 1, "Username": "admin"})
        init_portainer_admin("http://localhost:9000", "admin", "password123456")
        mock_post.assert_called_once()
        args, kwargs = mock_post.call_args
        assert "/api/users/admin/init" in args[0]
        assert kwargs["json"] == {"Username": "admin", "Password": "password123456"}


def test_init_portainer_admin_skips_on_409_already_initialised():
    with patch("installer.steps.portainer.requests.post") as mock_post:
        mock_post.return_value = _mk_response(409)
        init_portainer_admin("http://localhost:9000", "admin", "password123456")


def test_init_portainer_admin_raises_on_400():
    with patch("installer.steps.portainer.requests.post") as mock_post:
        mock_post.return_value = _mk_response(400, text="Password too short")
        with pytest.raises(InstallerError):
            init_portainer_admin("http://localhost:9000", "admin", "short")


def test_create_access_token_logs_in_then_creates_token():
    auth_response = _mk_response(200, {"jwt": "fake-jwt"})
    whoami_response = _mk_response(200, {"Id": 1})
    token_response = _mk_response(200, {"rawAPIKey": "raw-key-xxx"})

    with patch(
        "installer.steps.portainer.requests.post",
        side_effect=[auth_response, token_response],
    ) as mock_post, patch(
        "installer.steps.portainer.requests.get", return_value=whoami_response
    ):
        token = create_access_token("http://localhost:9000", "admin", "password123456")
        assert token == "raw-key-xxx"
        assert mock_post.call_count == 2
        _, last_kwargs = mock_post.call_args
        assert last_kwargs["headers"]["Authorization"] == "Bearer fake-jwt"


def test_detect_endpoint_id_returns_first_id():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.return_value = _mk_response(200, [{"Id": 7, "Name": "primary"}])
        assert detect_endpoint_id("http://localhost:9000", "api-key") == 7


def test_detect_endpoint_id_defaults_to_1_when_empty():
    with patch("installer.steps.portainer.requests.get") as mock_get:
        mock_get.return_value = _mk_response(200, [])
        assert detect_endpoint_id("http://localhost:9000", "api-key") == 1
