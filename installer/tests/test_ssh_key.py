import os
from unittest.mock import patch

from installer.steps.ssh_key import ensure_ssh_key


def test_ensure_ssh_key_generates_keypair_when_missing(tmp_path):
    key_dir = tmp_path / "keys"
    auth_keys = tmp_path / "authorized_keys"

    def fake_check_call(cmd, **kwargs):
        priv = cmd[cmd.index("-f") + 1]
        with open(priv, "w") as f:
            f.write("PRIVATE\n")
        with open(priv + ".pub", "w") as f:
            f.write("ssh-ed25519 AAAAB3Nz fakehost\n")
        return 0

    with patch("installer.steps.ssh_key.subprocess.check_call", side_effect=fake_check_call):
        result = ensure_ssh_key(
            key_dir=key_dir,
            authorized_keys_path=auth_keys,
            comment="alphapanel-terminal@host",
        )

    assert (key_dir / "alphapanel_ed25519").exists()
    assert (key_dir / "alphapanel_ed25519.pub").exists()
    assert (key_dir / "alphapanel_ed25519").stat().st_mode & 0o777 == 0o600
    assert auth_keys.read_text().strip().endswith("fakehost")
    assert auth_keys.stat().st_mode & 0o777 == 0o600
    assert result == key_dir / "alphapanel_ed25519"


def test_ensure_ssh_key_does_not_regenerate_when_present(tmp_path):
    key_dir = tmp_path / "keys"
    key_dir.mkdir()
    priv = key_dir / "alphapanel_ed25519"
    pub = key_dir / "alphapanel_ed25519.pub"
    priv.write_text("EXISTING\n")
    pub.write_text("ssh-ed25519 AAAA fakehost\n")
    os.chmod(priv, 0o600)

    auth_keys = tmp_path / "authorized_keys"

    with patch("installer.steps.ssh_key.subprocess.check_call") as mock_run:
        ensure_ssh_key(
            key_dir=key_dir,
            authorized_keys_path=auth_keys,
            comment="x",
        )
    mock_run.assert_not_called()
    assert priv.read_text() == "EXISTING\n"


def test_ensure_ssh_key_appends_pub_key_only_once(tmp_path):
    key_dir = tmp_path / "keys"
    key_dir.mkdir()
    (key_dir / "alphapanel_ed25519").write_text("priv\n")
    (key_dir / "alphapanel_ed25519.pub").write_text("ssh-ed25519 DUP host\n")

    auth_keys = tmp_path / "authorized_keys"

    for _ in range(3):
        ensure_ssh_key(
            key_dir=key_dir,
            authorized_keys_path=auth_keys,
            comment="x",
        )

    content = auth_keys.read_text()
    assert content.count("ssh-ed25519 DUP host") == 1
