from unittest.mock import MagicMock, patch

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.compose import compose_up


def _fake_popen(lines: list[str], returncode: int):
    proc = MagicMock()
    proc.stdout = iter([line + "\n" for line in lines])
    proc.wait.return_value = returncode
    proc.returncode = returncode
    return proc


def test_compose_up_streams_each_line_to_queue(tmp_path):
    q = LogQueue()
    fake = _fake_popen(["Creating alpha-panel-web ... done", "Starting mysql ..."], returncode=0)
    with patch("installer.steps.compose.subprocess.Popen", return_value=fake):
        compose_up(project_dir=tmp_path, log_queue=q)
    q.close()
    items = list(q.stream())
    lines = [i["text"] for i in items if i["type"] == "line"]
    assert "Creating alpha-panel-web ... done" in lines
    assert "Starting mysql ..." in lines


def test_compose_up_raises_on_nonzero_exit(tmp_path):
    q = LogQueue()
    fake = _fake_popen(["err: something"], returncode=1)
    with patch("installer.steps.compose.subprocess.Popen", return_value=fake):
        with pytest.raises(InstallerError) as exc:
            compose_up(project_dir=tmp_path, log_queue=q)
        assert exc.value.phase == "compose_up"
