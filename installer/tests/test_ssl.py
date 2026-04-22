from unittest.mock import MagicMock, patch

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.ssl import issue_panel_certificate


def _fake_popen(lines: list[str], returncode: int):
    proc = MagicMock()
    proc.stdout = iter([line + "\n" for line in lines])
    proc.wait.return_value = returncode
    proc.returncode = returncode
    return proc


def test_issue_panel_certificate_invokes_artisan_and_streams_output():
    q = LogQueue()
    proc = _fake_popen(["[acme] starting", "[acme] cert issued"], returncode=0)
    with patch("installer.steps.ssl.subprocess.Popen", return_value=proc) as popen:
        issue_panel_certificate(
            base_domain="example.com",
            admin_email="admin@example.com",
            token_file="/opt/alphapanel-docker/secrets/cloudflare.ini",
            container="alpha_panel_web",
            log_queue=q,
        )
    q.close()
    lines = [i["text"] for i in q.stream() if i["type"] == "line"]
    assert "[acme] starting" in lines
    assert "[acme] cert issued" in lines
    args, _ = popen.call_args
    assert "docker" in args[0]
    assert "exec" in args[0]
    assert "alpha_panel_web" in args[0]
    assert "panel:issue-installer-cert" in args[0]


def test_issue_panel_certificate_raises_when_artisan_fails():
    q = LogQueue()
    proc = _fake_popen(["[acme] dns propagation timeout"], returncode=1)
    with patch("installer.steps.ssl.subprocess.Popen", return_value=proc):
        with pytest.raises(InstallerError) as exc:
            issue_panel_certificate(
                base_domain="example.com",
                admin_email="admin@example.com",
                token_file="/opt/alphapanel-docker/secrets/cloudflare.ini",
                container="alpha_panel_web",
                log_queue=q,
            )
        assert exc.value.phase == "ssl_issue"
