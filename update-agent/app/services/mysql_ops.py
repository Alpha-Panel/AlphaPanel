from __future__ import annotations

import json
import logging
import os
import re
import shlex
from dataclasses import dataclass
from pathlib import Path

from app.services.panel_ops import CommandResult, run_cmd

logger = logging.getLogger(__name__)


def _mysql_cli(host: str, port: int, user: str, password: str) -> str:
    """Build the base mysql CLI invocation string."""
    escaped_pw = shlex.quote(password)
    return (
        f"mysql -h {host} -P {port} -u {user} "
        f"-p{escaped_pw} --default-character-set=utf8mb4 --skip-ssl"
    )


async def wait_for_mysql_ready(
    host: str,
    port: int,
    user: str,
    password: str,
    timeout_secs: int = 120,
    poll_interval: int = 3,
) -> bool:
    """Poll until MySQL accepts connections or timeout."""
    escaped_pw = shlex.quote(password)
    iterations = max(1, timeout_secs // poll_interval)
    for _ in range(iterations):
        result = await run_cmd(
            f"mysqladmin ping -h {host} -P {port} -u {user} -p{escaped_pw} --skip-ssl --connect-timeout=3",
            timeout=10,
        )
        if result.ok and "alive" in result.stdout.lower():
            return True
        import asyncio
        await asyncio.sleep(poll_interval)
    return False


def _parse_version(version: str) -> tuple[int, int, int] | None:
    """Parse a semver-like string into (major, minor, patch). Returns None on failure."""
    parts = version.strip().split(".")
    if len(parts) != 3:
        return None
    try:
        return int(parts[0]), int(parts[1]), int(parts[2])
    except ValueError:
        return None


def detect_major_skip(current: str, target: str) -> str | None:
    """Validate the upgrade jump.

    MySQL only supports same-major upgrades or single-major increments
    (e.g., 8.0 → 8.4 → 9.x). Skipping a major (8.0 → 9.x) is unsupported and
    will fail at server start. Returns a reason string when the jump is
    invalid, otherwise None.
    """
    current_v = _parse_version(current)
    target_v = _parse_version(target)

    if current_v is None:
        return f"Cannot parse current version '{current}'."
    if target_v is None:
        return f"Cannot parse target version '{target}'."

    if target_v < current_v:
        return (
            f"Target version {target} is older than current {current}. "
            "Downgrades are not supported by this flow."
        )

    cur_major = current_v[0]
    tgt_major = target_v[0]

    if tgt_major == cur_major:
        return None
    if tgt_major == cur_major + 1:
        return None

    return (
        f"Cannot skip major versions: {current} → {target}. "
        f"MySQL requires upgrading one major at a time. "
        f"Upgrade to a {cur_major + 1}.x release first, then to {target}."
    )


async def validate_target_image(target_version: str) -> CommandResult:
    """Check that mysql:{target_version} exists in the registry."""
    safe_version = shlex.quote(target_version)
    return await run_cmd(
        f"docker manifest inspect mysql:{safe_version}",
        timeout=30,
    )


async def check_disk_for_backup(data_dir: str) -> tuple[int, int, bool]:
    """Return (data_size_bytes, free_bytes, sufficient).

    Requires `free >= data_size * 1.2` so a `cp -a` backup fits with a safety
    margin. Returns (0, 0, False) on probe failure (treated as insufficient).
    """
    if not os.path.isdir(data_dir):
        return (0, 0, False)

    size_result = await run_cmd(f"du -sb {shlex.quote(data_dir)}", timeout=120)
    if not size_result.ok:
        logger.error("du failed for %s: %s", data_dir, size_result.stderr)
        return (0, 0, False)

    try:
        data_size = int(size_result.stdout.split()[0])
    except (ValueError, IndexError):
        logger.error("Could not parse du output: %s", size_result.stdout)
        return (0, 0, False)

    parent = os.path.dirname(os.path.abspath(data_dir))
    free_result = await run_cmd(
        f"df -B1 --output=avail {shlex.quote(parent)} | tail -1",
        timeout=10,
    )
    if not free_result.ok:
        logger.error("df failed for %s: %s", parent, free_result.stderr)
        return (data_size, 0, False)

    try:
        free_bytes = int(free_result.stdout.strip())
    except ValueError:
        logger.error("Could not parse df output: %s", free_result.stdout)
        return (data_size, 0, False)

    sufficient = free_bytes >= int(data_size * 1.2)
    return (data_size, free_bytes, sufficient)


async def cp_data_dir(src: str, dst: str, timeout: int = 3600) -> CommandResult:
    """Copy a data directory preserving perms, ownership, timestamps, symlinks."""
    return await run_cmd(
        f"cp -a {shlex.quote(src)} {shlex.quote(dst)}",
        timeout=timeout,
    )


async def verify_backup(src: str, dst: str, tolerance: float = 0.001) -> bool:
    """Confirm two directories have matching byte-size totals within tolerance.

    `cp -a` should produce identical byte counts; a small tolerance accounts
    for rare filesystem-overhead drift on sparse files.
    """
    src_res = await run_cmd(f"du -sb {shlex.quote(src)}", timeout=120)
    dst_res = await run_cmd(f"du -sb {shlex.quote(dst)}", timeout=120)
    if not src_res.ok or not dst_res.ok:
        return False
    try:
        src_bytes = int(src_res.stdout.split()[0])
        dst_bytes = int(dst_res.stdout.split()[0])
    except (ValueError, IndexError):
        return False
    if src_bytes == 0:
        return dst_bytes == 0
    drift = abs(src_bytes - dst_bytes) / src_bytes
    return drift <= tolerance


@dataclass
class IncompatRule:
    """Rule describing tables known to break across a major-version jump."""
    description: str
    table_like: str  # SQL LIKE pattern matched against table_name


# Keyed by f"{from_major}.x_to_{to_major}.x". Major-only buckets keep the rule
# set readable; refine to minor if real-world gotchas appear.
KNOWN_INCOMPAT_RULES: dict[str, list[IncompatRule]] = {
    "8.x_to_9.x": [
        IncompatRule(
            description="Laravel Pulse legacy tables can block MySQL 9 startup; "
                        "drop and let Pulse recreate them under the new server.",
            table_like="pulse_%",
        ),
    ],
}


def _rule_key(current: str, target: str) -> str | None:
    cur_v = _parse_version(current)
    tgt_v = _parse_version(target)
    if cur_v is None or tgt_v is None:
        return None
    if cur_v[0] == tgt_v[0]:
        return None  # Same major: no incompat scan
    return f"{cur_v[0]}.x_to_{tgt_v[0]}.x"


async def scan_incompat_tables(
    host: str,
    port: int,
    user: str,
    password: str,
    current: str,
    target: str,
) -> list[dict]:
    """Search information_schema.tables for matches against KNOWN_INCOMPAT_RULES.

    Each finding is a dict: {schema, table, rule, description}.
    Empty list when no rules apply or no matches.
    """
    key = _rule_key(current, target)
    if key is None:
        return []
    rules = KNOWN_INCOMPAT_RULES.get(key, [])
    if not rules:
        return []

    cli = _mysql_cli(host, port, user, password)
    findings: list[dict] = []

    for rule in rules:
        like = rule.table_like.replace("'", "''")
        query = (
            f"SELECT table_schema, table_name FROM information_schema.tables "
            f"WHERE table_type='BASE TABLE' "
            f"AND table_schema NOT IN ('information_schema','performance_schema','sys','mysql') "
            f"AND table_name LIKE '{like}'"
        )
        res = await run_cmd(
            f"{cli} -N -e \"{query}\"",
            timeout=30,
        )
        if not res.ok:
            logger.warning("Incompat scan query failed for rule %s: %s", rule.table_like, res.stderr)
            continue
        for line in res.stdout.splitlines():
            parts = line.split("\t")
            if len(parts) != 2:
                continue
            findings.append({
                "schema": parts[0].strip(),
                "table": parts[1].strip(),
                "rule": rule.table_like,
                "description": rule.description,
            })

    return findings


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
        if not new_content.endswith("\n"):
            new_content += "\n"
        new_content += f"MYSQL_VERSION={version}\n"

    env_path.write_text(new_content, encoding="utf-8")


def update_version_json(project_root: str, new_mysql_version: str) -> None:
    """Update the mysql version in version.json."""
    version_path = Path(project_root) / "version.json"
    if not version_path.exists():
        return

    data = json.loads(version_path.read_text(encoding="utf-8"))
    if "services" in data:
        data["services"]["mysql"] = new_mysql_version
    version_path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


# ---------------------------------------------------------------------------
# Deprecated helpers retained for reference. The current upgrade flow uses an
# in-place strategy with a `cp -a` data-dir backup; logical snapshot/dump and
# tar.gz backup paths below are not invoked by any router and may be removed
# once a few release cycles confirm no rollback to the old flow is needed.
# ---------------------------------------------------------------------------


async def get_snapshot(
    host: str,
    port: int,
    user: str,
    password: str,
) -> dict:
    """DEPRECATED: capture databases/table-counts/row-counts/grants snapshot.

    Kept for ad-hoc diagnostics; not used by the in-place upgrade flow.
    """
    cli = _mysql_cli(host, port, user, password)

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

    table_counts: dict[str, int] = {}
    row_counts: dict[str, dict[str, int]] = {}

    for db in databases:
        res = await run_cmd(
            f"{cli} -N -e \"SELECT COUNT(*) FROM information_schema.tables "
            f"WHERE table_schema='{db}' AND table_type='BASE TABLE'\"",
            timeout=30,
        )
        if res.ok and res.stdout.strip().isdigit():
            table_counts[db] = int(res.stdout.strip())

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
    """DEPRECATED: collect SHOW GRANTS for every user."""
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


def compare_snapshots(
    before: dict,
    after: dict,
) -> list[str]:
    """DEPRECATED: diff two snapshots and return mismatch descriptions."""
    mismatches: list[str] = []

    before_dbs = set(before.get("databases", []))
    after_dbs = set(after.get("databases", []))

    missing = before_dbs - after_dbs
    if missing:
        mismatches.append(f"Missing databases after upgrade: {', '.join(sorted(missing))}")

    extra = after_dbs - before_dbs
    if extra:
        mismatches.append(f"New unexpected databases after upgrade: {', '.join(sorted(extra))}")

    before_tc = before.get("table_counts", {})
    after_tc = after.get("table_counts", {})

    for db in before_dbs & after_dbs:
        bt = before_tc.get(db, 0)
        at = after_tc.get(db, 0)
        if bt != at:
            mismatches.append(
                f"Table count mismatch in '{db}': before={bt}, after={at}"
            )

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
            threshold = max(br, 1) * 0.1
            if abs(br - ar) > threshold:
                mismatches.append(
                    f"Row count mismatch in '{db}'.'{table}': before={br}, after={ar}"
                )

    return mismatches
