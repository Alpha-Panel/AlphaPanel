from __future__ import annotations

import logging
import os
from pathlib import Path

from app.services.panel_ops import run_cmd

logger = logging.getLogger(__name__)

ALLOWED_CONF_FILES = {"10-security.cnf", "99-tuning.cnf", "disable_binlog.cnf"}


def _conf_path(project_root: str, filename: str) -> Path:
    return Path(project_root) / "mysql" / "conf.d" / filename


def validate_filename(filename: str) -> bool:
    return filename in ALLOWED_CONF_FILES


def read_config_file(project_root: str, filename: str) -> str:
    """Read a conf.d file and return its content."""
    path = _conf_path(project_root, filename)
    if not path.exists():
        return f"[mysqld]\n# {filename} not found\n"
    return path.read_text(encoding="utf-8")


def write_config_file(project_root: str, filename: str, content: str) -> None:
    """Atomically write content to a conf.d file."""
    path = _conf_path(project_root, filename)
    tmp_path = path.with_suffix(".cnf.tmp")
    tmp_path.write_text(content, encoding="utf-8")
    os.replace(tmp_path, path)
    logger.info("Wrote MySQL config file: %s", filename)


async def restart_mysql(project_root: str) -> tuple[bool, str]:
    """Restart the MySQL container via docker compose. Returns (ok, detail)."""
    result = await run_cmd(
        f"docker compose -f {project_root}/docker-compose.yaml restart mysql",
        timeout=120,
    )
    if result.ok:
        return (True, "MySQL container restarted")
    return (False, result.stderr[:300])
