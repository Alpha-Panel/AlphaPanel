import json
from pathlib import Path

import pytest

from installer.state import InstallerState, clear_state, load_state, save_state


@pytest.fixture
def state_path(tmp_path: Path) -> Path:
    return tmp_path / ".installer_state.json"


def test_load_state_returns_none_when_file_missing(state_path: Path):
    assert load_state(state_path) is None


def test_save_then_load_round_trip(state_path: Path):
    original = InstallerState(
        form={"base_domain": "example.com"},
        generated_secrets={"mysql_root_password": "abc"},
        completed_phases=["secrets", "env"],
        current_phase="compose_up",
        last_error=None,
    )
    save_state(state_path, original)
    loaded = load_state(state_path)
    assert loaded == original


def test_save_state_writes_mode_600(state_path: Path):
    state = InstallerState()
    save_state(state_path, state)
    mode = state_path.stat().st_mode & 0o777
    assert mode == 0o600


def test_save_state_includes_version_and_timestamps(state_path: Path):
    state = InstallerState(form={"x": "y"})
    save_state(state_path, state)
    raw = json.loads(state_path.read_text())
    assert raw["version"] == 1
    assert "started_at" in raw
    assert "updated_at" in raw


def test_clear_state_removes_file(state_path: Path):
    save_state(state_path, InstallerState())
    assert state_path.exists()
    clear_state(state_path)
    assert not state_path.exists()


def test_clear_state_is_idempotent(state_path: Path):
    clear_state(state_path)  # should not raise
