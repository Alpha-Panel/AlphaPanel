from unittest.mock import MagicMock, patch

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.caddy_reload import reload_caddy


def test_reload_caddy_runs_panel_apply_and_reloads():
    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter(["applied\n"])
    proc.wait.return_value = 0
    with patch("installer.steps.caddy_reload.subprocess.Popen", return_value=proc) as popen:
        reload_caddy(log_queue=q)
    q.close()
    lines = [i["text"] for i in q.stream()]
    assert any("applied" in line for line in lines)
    args, _ = popen.call_args
    assert "panel:apply" in args[0]


def test_reload_caddy_raises_on_nonzero():
    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter([])
    proc.wait.return_value = 1
    with patch("installer.steps.caddy_reload.subprocess.Popen", return_value=proc):
        with pytest.raises(InstallerError):
            reload_caddy(log_queue=q)
