from __future__ import annotations

import logging
import re
from pathlib import Path

from app.services.panel_ops import CommandResult, run_cmd

logger = logging.getLogger(__name__)

_MYSQLDUMP_PARAMS = (
    "--single-transaction "
    "--routines "
    "--triggers "
    "--events "
    "--hex-blob "
    "--default-character-set=utf8mb4 "
    "--set-gtid-purged=OFF "
    "--column-statistics=0 "
    "--all-databases"
)

_IMPORT_PREAMBLE = (
    "SET FOREIGN_KEY_CHECKS=0; "
    "SET UNIQUE_CHECKS=0; "
    "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';"
)


def _mysql_cli(host: str, port: int, user: str, password: str) -> str:
    """Build the base mysql CLI invocation string."""
    return (
        f"mysql -h {host} -P {port} -u {user} "
        f"-p'{password}' --default-character-set=utf8mb4"
    )


def _mysqldump_cli(host: str, port: int, user: str, password: str) -> str:
    """Build the base mysqldump CLI invocation string."""
    return (
        f"mysqldump -h {host} -P {port} -u {user} "
        f"-p'{password}' {_MYSQLDUMP_PARAMS}"
    )


async def get_snapshot(
    host: str,
    port: int,
    user: str,
    password: str,
) -> dict:
    """Capture a snapshot of databases, table counts, row counts, and grants."""
    cli = _mysql_cli(host, port, user, password)

    # Databases
    result = await run_cmd(
        f"{cli} -N -e \"SHOW DATABASES\"",
        timeout=30,
    )
    databases: list[str] = []
    if result.ok:
        databases = [
            db
            for db in result.stdout.splitlines()
            if db not in ("information_schema", "performance_schema", "sys")
        ]

    # Table counts and row counts per database
    table_counts: dict[str, int] = {}
    row_counts: dict[str, dict[str, int]] = {}

    for db in databases:
        # Table count
        res = await run_cmd(
            f"{cli} -N -e \"SELECT COUNT(*) FROM information_schema.tables "
            f"WHERE table_schema='{db}' AND table_type='BASE TABLE'\"",
            timeout=30,
        )
        if res.ok and res.stdout.strip().isdigit():
            table_counts[db] = int(res.stdout.strip())

        # Row counts per table
        res = await run_cmd(
            f"{cli} -N -e \"SELECT table_name, table_rows "
            f"FROM information_schema.tables "
            f"WHERE table_schema='{db}' AND table_type='BASE TABLE'\"",
            timeout=30,
        )
        if res.ok:
            db_rows: dict[str, int] = {}
            for line in res.stdout.splitlines():
                parts = line.split("\t")
                if len(parts) == 2 and parts[1].strip().isdigit():
                    db_rows[parts[0].strip()] = int(parts[1].strip())
            row_counts[db] = db_rows

    # Grants
    grants = await _collect_grants(host, port, user, password)

    return {
        "databases": databases,
        "table_counts": table_counts,
        "row_counts": row_counts,
        "grants": grants,
    }


async def _collect_grants(
    host: str,
    port: int,
    user: str,
    password: str,
) -> list[str]:
    """Collect all SHOW GRANTS for every user."""
    cli = _mysql_cli(host, port, user, password)

    result = await run_cmd(
        f"{cli} -N -e \"SELECT CONCAT(\\\"'\\\", user, \\\"'@'\\\", host, \\\"'\\\") "
        f"FROM mysql.user WHERE user NOT IN ('mysql.sys','mysql.session','mysql.infoschema','root')\"",
        timeout=30,
    )

    all_grants: list[str] = []
    if not result.ok:
        return all_grants

    for user_host in result.stdout.splitlines():
        user_host = user_host.strip()
        if not user_host:
            continue
        res = await run_cmd(
            f"{cli} -N -e \"SHOW GRANTS FOR {user_host}\"",
            timeout=15,
        )
        if res.ok:
            for line in res.stdout.splitlines():
                line = line.strip()
                if line:
                    all_grants.append(line)

    return all_grants


async def safe_mysqldump(
    host: str,
    port: int,
    user: str,
    password: str,
    output_path: str,
) -> CommandResult:
    """Run mysqldump with safe parameters and write to output_path."""
    cli = _mysqldump_cli(host, port, user, password)
    cmd = f"{cli} > {output_path}"
    return await run_cmd(cmd, timeout=1800)


async def export_grants(
    host: str,
    port: int,
    user: str,
    password: str,
    output_path: str,
) -> CommandResult:
    """Export all user grants to a SQL file."""
    grants = await _collect_grants(host, port, user, password)
    if not grants:
        # Write empty file
        Path(output_path).write_text("-- No grants to export\n", encoding="utf-8")
        return CommandResult(returncode=0, stdout="No grants to export", stderr="")

    lines = ["-- Grants export", "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';", ""]
    for grant in grants:
        if not grant.endswith(";"):
            grant += ";"
        lines.append(grant)

    Path(output_path).write_text("\n".join(lines) + "\n", encoding="utf-8")
    return CommandResult(returncode=0, stdout=f"Exported {len(grants)} grant statements", stderr="")


async def import_dump(
    host: str,
    port: int,
    user: str,
    password: str,
    dump_path: str,
) -> CommandResult:
    """Import a SQL dump with FK checks disabled."""
    cli = _mysql_cli(host, port, user, password)
    # Prepend the import preamble then pipe the dump
    cmd = (
        f"echo \"{_IMPORT_PREAMBLE}\" | cat - {dump_path} | {cli}"
    )
    return await run_cmd(cmd, timeout=3600)


def compare_snapshots(
    before: dict,
    after: dict,
) -> list[str]:
    """Compare two snapshots and return a list of mismatch descriptions."""
    mismatches: list[str] = []

    # Compare database lists
    before_dbs = set(before.get("databases", []))
    after_dbs = set(after.get("databases", []))

    missing = before_dbs - after_dbs
    if missing:
        mismatches.append(f"Missing databases after upgrade: {', '.join(sorted(missing))}")

    extra = after_dbs - before_dbs
    if extra:
        mismatches.append(f"New unexpected databases after upgrade: {', '.join(sorted(extra))}")

    # Compare table counts
    before_tc = before.get("table_counts", {})
    after_tc = after.get("table_counts", {})

    for db in before_dbs & after_dbs:
        bt = before_tc.get(db, 0)
        at = after_tc.get(db, 0)
        if bt != at:
            mismatches.append(
                f"Table count mismatch in '{db}': before={bt}, after={at}"
            )

    # Compare row counts (with 10% tolerance for InnoDB estimates)
    before_rc = before.get("row_counts", {})
    after_rc = after.get("row_counts", {})

    for db in before_dbs & after_dbs:
        before_tables = before_rc.get(db, {})
        after_tables = after_rc.get(db, {})

        for table in set(before_tables.keys()) | set(after_tables.keys()):
            br = before_tables.get(table, 0)
            ar = after_tables.get(table, 0)
            if br == 0 and ar == 0:
                continue
            # Allow 10% tolerance because InnoDB row estimates are approximate
            threshold = max(br, 1) * 0.1
            if abs(br - ar) > threshold:
                mismatches.append(
                    f"Row count mismatch in '{db}'.'{table}': before={br}, after={ar}"
                )

    return mismatches


def get_mysql_version_from_env(project_root: str) -> str:
    """Read the MYSQL_VERSION value from the project .env file."""
    env_path = Path(project_root) / ".env"
    if not env_path.exists():
        return "unknown"

    content = env_path.read_text(encoding="utf-8")
    match = re.search(r"^MYSQL_VERSION\s*=\s*(.+)$", content, re.MULTILINE)
    if match:
        return match.group(1).strip().strip("\"'")
    return "unknown"


def set_mysql_version_in_env(project_root: str, version: str) -> None:
    """Update (or add) MYSQL_VERSION in the project .env file."""
    env_path = Path(project_root) / ".env"
    if not env_path.exists():
        env_path.write_text(f"MYSQL_VERSION={version}\n", encoding="utf-8")
        return

    content = env_path.read_text(encoding="utf-8")
    new_content, count = re.subn(
        r"^MYSQL_VERSION\s*=\s*.+$",
        f"MYSQL_VERSION={version}",
        content,
        flags=re.MULTILINE,
    )

    if count == 0:
        # Variable not found, append it
        if not new_content.endswith("\n"):
            new_content += "\n"
        new_content += f"MYSQL_VERSION={version}\n"

    env_path.write_text(new_content, encoding="utf-8")
