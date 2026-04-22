from unittest.mock import patch

from installer.steps.system import detect_os, detect_private_ip, detect_public_ip


def test_detect_os_parses_os_release(tmp_path):
    fake = tmp_path / "os-release"
    fake.write_text('ID=ubuntu\nPRETTY_NAME="Ubuntu 22.04.3 LTS"\n')
    info = detect_os(os_release_path=fake)
    assert info == {"id": "ubuntu", "pretty": "Ubuntu 22.04.3 LTS"}


def test_detect_os_unknown_when_file_missing(tmp_path):
    fake = tmp_path / "missing"
    info = detect_os(os_release_path=fake)
    assert info == {"id": "unknown", "pretty": "unknown"}


def test_detect_private_ip_parses_ip_route_output():
    fake_output = "1.1.1.1 via 10.0.0.1 dev eth0 src 10.0.0.42 uid 0 \n    cache"
    with patch("installer.steps.system._run", return_value=fake_output):
        assert detect_private_ip() == "10.0.0.42"


def test_detect_private_ip_falls_back_to_localhost_on_failure():
    with patch("installer.steps.system._run", side_effect=Exception("no route")):
        assert detect_private_ip() == "127.0.0.1"


def test_detect_public_ip_uses_first_successful_service():
    with patch("installer.steps.system._http_get", side_effect=["203.0.113.5", "other"]):
        assert detect_public_ip() == "203.0.113.5"


def test_detect_public_ip_returns_private_on_all_services_failing():
    with patch("installer.steps.system._http_get", side_effect=Exception("no net")):
        with patch("installer.steps.system.detect_private_ip", return_value="10.0.0.42"):
            assert detect_public_ip() == "10.0.0.42"
