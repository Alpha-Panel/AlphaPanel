from pathlib import Path
from unittest.mock import MagicMock, patch

from installer.log_queue import LogQueue
from installer.steps.reset import reset_installation


def test_reset_runs_docker_down_and_deletes_state(tmp_path: Path):
    state_file = tmp_path / ".installer_state.json"
    state_file.write_text("{}")
    env_file = tmp_path / ".env"
    env_file.write_text("X=1\n")
    laravel_env = tmp_path / "alpha-panel" / "web" / "httpdocs" / ".env"
    laravel_env.parent.mkdir(parents=True)
    laravel_env.write_text("Y=2\n")

    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter(["stopped\n"])
    proc.wait.return_value = 0
    with patch("installer.steps.reset.subprocess.Popen", return_value=proc) as popen:
        reset_installation(
            project_dir=tmp_path,
            state_file=state_file,
            log_queue=q,
        )

    args, _ = popen.call_args
    assert "docker" in args[0]
    assert "compose" in args[0]
    assert "down" in args[0]
    assert "-v" in args[0]

    assert not state_file.exists()
    assert not env_file.exists()
    assert not laravel_env.exists()


def test_reset_is_idempotent_with_missing_files(tmp_path: Path):
    q = LogQueue()
    proc = MagicMock()
    proc.stdout = iter([])
    proc.wait.return_value = 0
    with patch("installer.steps.reset.subprocess.Popen", return_value=proc):
        reset_installation(
            project_dir=tmp_path,
            state_file=tmp_path / "missing.json",
            log_queue=q,
        )
