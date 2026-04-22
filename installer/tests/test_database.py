from unittest.mock import MagicMock, patch

import pytest

from installer.errors import InstallerError
from installer.log_queue import LogQueue
from installer.steps.database import (
    create_admin_user,
    run_migrations,
    seed_php_versions,
    wait_for_mysql,
)


def _fake_run(returncode: int):
    proc = MagicMock()
    proc.returncode = returncode
    proc.stdout = ""
    return proc


def test_wait_for_mysql_returns_on_success():
    with patch("installer.steps.database.subprocess.run", return_value=_fake_run(0)):
        wait_for_mysql(root_password="rootpw", timeout=5, interval=0.01)


def test_wait_for_mysql_raises_on_timeout():
    with patch("installer.steps.database.subprocess.run", return_value=_fake_run(1)):
        with pytest.raises(InstallerError) as exc:
            wait_for_mysql(root_password="rootpw", timeout=0.05, interval=0.01)
        assert exc.value.phase == "mysql_wait"


def test_run_migrations_streams_and_logs_lines():
    q = LogQueue()
    with patch("installer.steps.database.subprocess.Popen") as mock_popen:
        proc = MagicMock()
        proc.stdout = iter(["migrated FooTable\n"])
        proc.wait.return_value = 0
        mock_popen.return_value = proc
        run_migrations(log_queue=q)
    q.close()
    lines = [i["text"] for i in q.stream()]
    assert any("migrated FooTable" in line for line in lines)


def test_seed_php_versions_uses_expected_artisan_args():
    q = LogQueue()
    with patch("installer.steps.database.subprocess.Popen") as mock_popen:
        proc = MagicMock()
        proc.stdout = iter([])
        proc.wait.return_value = 0
        mock_popen.return_value = proc
        seed_php_versions(log_queue=q)
        args, _ = mock_popen.call_args
        assert "db:seed" in args[0]
        assert "--class=PhpVersionSeeder" in args[0]
        assert "--force" in args[0]


def test_create_admin_user_passes_all_flags():
    q = LogQueue()
    with patch("installer.steps.database.subprocess.Popen") as mock_popen:
        proc = MagicMock()
        proc.stdout = iter([])
        proc.wait.return_value = 0
        mock_popen.return_value = proc
        create_admin_user(
            name="A B",
            username="admin",
            email="a@b.com",
            password="secret",
            log_queue=q,
        )
        args, _ = mock_popen.call_args
        full_cmd = " ".join(args[0])
        assert "app:add-admin-user" in full_cmd
        assert "--name=A B" in full_cmd
        assert "--username=admin" in full_cmd
        assert "--email=a@b.com" in full_cmd
        assert "--password=secret" in full_cmd
