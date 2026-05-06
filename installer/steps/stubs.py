from __future__ import annotations

import shutil
from pathlib import Path

_PHP_VERSIONS = ("7.0", "7.1", "7.2", "7.3", "7.4", "8.0", "8.1", "8.2", "8.3", "8.4", "8.5")


def materialize_stubs(project_dir: Path) -> None:
    """
    Copy *.stub files to their real target names before docker compose up.
    Without this, Docker creates missing bind-mount file targets as directories,
    causing OCI runtime mount failures.
    """
    pcs = project_dir / "php-code-server"

    for ver in _PHP_VERSIONS:
        stub = pcs / ver / "php.ini.stub"
        target = pcs / ver / "php.ini"
        if stub.exists() and not target.exists():
            shutil.copy2(stub, target)

    sup = pcs / "supervisor.d"
    for ver in _PHP_VERSIONS:
        stub = sup / f"php-fpm-{ver}.conf.stub"
        target = sup / f"php-fpm-{ver}.conf"
        if stub.exists() and not target.exists():
            shutil.copy2(stub, target)
