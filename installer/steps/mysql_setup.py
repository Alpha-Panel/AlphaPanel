from __future__ import annotations

import re
import subprocess

from installer.errors import InstallerError

_PANEL_DB = "AlphaPanel"
_PANEL_USER = "alphapanel"
_BITWARDEN_DB = "bitwarden"
_BITWARDEN_USER = "bitwarden"
_FTP_USER = "ftp_reader"
_POWERDNS_DB = "powerdns"
_POWERDNS_USER = "powerdns"
# Must match DB_DATABASE in alpha-panel/web/httpdocs/phpunit.xml exactly — MySQL
# schema names are case-sensitive on Linux. Pre-created so the suite never has to
# be pointed at the live panel schema.
_PANEL_TEST_DB = "alphapanel_testing"


def _redact(sql: str) -> str:
    """Strip credentials before an SQL snippet reaches a log or the state file."""
    return re.sub(r"IDENTIFIED BY '[^']*'", "IDENTIFIED BY '***'", sql)


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
            detail={"sql": _redact(sql)[:200], "stderr": _redact(result.stderr)[:500]},
        )


def setup_mysql_users(secrets: dict[str, str]) -> None:
    root_pw = secrets["mysql_root_password"]
    panel_pw = secrets["panel_db_pass"]
    bitwarden_pw = secrets["vaultwarden_db_password"]
    ftp_pw = secrets["ftp_mysql_password"]
    powerdns_pw = secrets["powerdns_db_password"]

    statements = [
        # Panel DB + user — full superuser during install so migrations can
        # create auxiliary databases (e.g. powerdns) without permission errors.
        f"CREATE DATABASE IF NOT EXISTS `{_PANEL_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        f"CREATE USER IF NOT EXISTS '{_PANEL_USER}'@'%' IDENTIFIED BY '{panel_pw}';",
        f"GRANT ALL PRIVILEGES ON *.* TO '{_PANEL_USER}'@'%' WITH GRANT OPTION;",

        # Vaultwarden DB + user
        f"CREATE DATABASE IF NOT EXISTS `{_BITWARDEN_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        f"CREATE USER IF NOT EXISTS '{_BITWARDEN_USER}'@'%' IDENTIFIED BY '{bitwarden_pw}';",
        f"GRANT ALL PRIVILEGES ON `{_BITWARDEN_DB}`.* TO '{_BITWARDEN_USER}'@'%';",

        # FTP read-only user (reads ftp_users table from panel DB)
        f"CREATE USER IF NOT EXISTS '{_FTP_USER}'@'%' IDENTIFIED BY '{ftp_pw}';",
        f"GRANT SELECT ON `{_PANEL_DB}`.* TO '{_FTP_USER}'@'%';",

        # Test schema, so `php artisan test` can never touch the live panel data.
        # The panel user already holds ALL PRIVILEGES ON *.*, so no extra grant.
        f"CREATE DATABASE IF NOT EXISTS `{_PANEL_TEST_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",

        # PowerDNS DB pre-created so migrations don't race against creation.
        # The gmysql backend only reads/writes rows — the schema is owned by the
        # panel migration — so this account gets DML only: no DDL, no other schema.
        # Without it pdns.conf falls back to gmysql-user=root and the MySQL root
        # password ends up on the pdns command line.
        f"CREATE DATABASE IF NOT EXISTS `{_POWERDNS_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        f"CREATE USER IF NOT EXISTS '{_POWERDNS_USER}'@'%' IDENTIFIED BY '{powerdns_pw}';",
        f"ALTER USER '{_POWERDNS_USER}'@'%' IDENTIFIED BY '{powerdns_pw}';",
        f"GRANT SELECT, INSERT, UPDATE, DELETE ON `{_POWERDNS_DB}`.* TO '{_POWERDNS_USER}'@'%';",

        "FLUSH PRIVILEGES;",
    ]

    for sql in statements:
        _mysql(root_pw, sql)
