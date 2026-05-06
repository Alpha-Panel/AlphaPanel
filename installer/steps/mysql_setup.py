from __future__ import annotations

import subprocess

from installer.errors import InstallerError

_PANEL_DB = "AlphaPanel"
_PANEL_USER = "alphapanel"
_BITWARDEN_DB = "bitwarden"
_BITWARDEN_USER = "bitwarden"
_FTP_USER = "ftp_reader"
_POWERDNS_DB = "powerdns"


def _mysql(root_password: str, sql: str) -> None:
    result = subprocess.run(
        [
            "docker", "exec", "mysql",
            "mysql", "-uroot", f"-p{root_password}", "-e", sql,
        ],
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        raise InstallerError(
            "mysql_setup",
            "MySQL command failed",
            detail={"sql": sql[:200], "stderr": result.stderr[:500]},
        )


def setup_mysql_users(secrets: dict[str, str]) -> None:
    root_pw = secrets["mysql_root_password"]
    panel_pw = secrets["panel_db_pass"]
    bitwarden_pw = secrets["vaultwarden_db_password"]
    ftp_pw = secrets["ftp_mysql_password"]

    statements = [
        # Panel DB + user
        f"CREATE DATABASE IF NOT EXISTS `{_PANEL_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        f"CREATE USER IF NOT EXISTS '{_PANEL_USER}'@'%' IDENTIFIED BY '{panel_pw}';",
        f"GRANT ALL PRIVILEGES ON `{_PANEL_DB}`.* TO '{_PANEL_USER}'@'%';",

        # Vaultwarden DB + user
        f"CREATE DATABASE IF NOT EXISTS `{_BITWARDEN_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        f"CREATE USER IF NOT EXISTS '{_BITWARDEN_USER}'@'%' IDENTIFIED BY '{bitwarden_pw}';",
        f"GRANT ALL PRIVILEGES ON `{_BITWARDEN_DB}`.* TO '{_BITWARDEN_USER}'@'%';",

        # FTP read-only user (reads ftp_users table from panel DB)
        f"CREATE USER IF NOT EXISTS '{_FTP_USER}'@'%' IDENTIFIED BY '{ftp_pw}';",
        f"GRANT SELECT ON `{_PANEL_DB}`.* TO '{_FTP_USER}'@'%';",

        # PowerDNS DB (root credentials, no separate user needed)
        f"CREATE DATABASE IF NOT EXISTS `{_POWERDNS_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

        "FLUSH PRIVILEGES;",
    ]

    for sql in statements:
        _mysql(root_pw, sql)
