from __future__ import annotations

import logging
import re
from dataclasses import dataclass

import httpx

from app.services.panel_ops import run_cmd

logger = logging.getLogger(__name__)

DOCKER_HUB_API = "https://registry.hub.docker.com/v2/repositories/library/mysql/tags"

# Matches stable version tags like 9.3.0, 10.0.1 — rejects rc, alpha, beta, etc.
_STABLE_TAG_RE = re.compile(r"^\d+\.\d+\.\d+$")


@dataclass
class MysqlVersionInfo:
    current: str
    latest_minor: str
    latest_major: str
    minor_update_available: bool
    major_upgrade_available: bool


async def _read_current_mysql_version(project_root: str) -> str:
    """Read the current MySQL version from the running container's image tag.

    Uses ``docker inspect`` to get the actual deployed image — not a stale
    version.json. This is the source of truth: if the container is running
    mysql:9.7.0, that is what we compare against Docker Hub.
    """
    result = await run_cmd(
        "docker inspect --format '{{.Config.Image}}' mysql",
        timeout=10,
    )

    if not result.ok:
        logger.warning("Could not inspect mysql container: %s", result.stderr)
        return "0.0.0"

    # Output format: "mysql:9.7.0" or just "9.7.0" if image is local
    image = result.stdout.strip().strip("'\"")
    tag = image.rsplit(":", 1)[-1] if ":" in image else image

    if not _STABLE_TAG_RE.match(tag):
        logger.warning("MySQL image tag is not a stable semver: %s", tag)
        return "0.0.0"

    return tag


def _parse_version(tag: str) -> tuple[int, int, int]:
    parts = tag.split(".")
    return int(parts[0]), int(parts[1]), int(parts[2])


async def check_mysql_updates(
    client: httpx.AsyncClient,
    project_root: str,
) -> MysqlVersionInfo:
    """Query Docker Hub for available MySQL version updates."""
    current = await _read_current_mysql_version(project_root)

    # Cannot detect current version → never report a phantom update.
    if current == "0.0.0":
        return MysqlVersionInfo(
            current=current,
            latest_minor=current,
            latest_major=current,
            minor_update_available=False,
            major_upgrade_available=False,
        )

    stable_tags: list[str] = []
    url: str | None = f"{DOCKER_HUB_API}?page_size=100"

    # Paginate through all tags (Docker Hub paginates at 100)
    while url:
        try:
            resp = await client.get(url)
            resp.raise_for_status()
            data = resp.json()
        except httpx.HTTPError as exc:
            logger.error("Docker Hub API request failed: %s", exc)
            break

        for tag_info in data.get("results", []):
            name = tag_info.get("name", "")
            if _STABLE_TAG_RE.match(name):
                stable_tags.append(name)

        url = data.get("next")

    if not stable_tags:
        return MysqlVersionInfo(
            current=current,
            latest_minor=current,
            latest_major=current,
            minor_update_available=False,
            major_upgrade_available=False,
        )

    try:
        current_major, current_minor, current_patch = _parse_version(current)
    except (ValueError, IndexError):
        logger.error("Cannot parse current MySQL version: %s", current)
        return MysqlVersionInfo(
            current=current,
            latest_minor=current,
            latest_major=current,
            minor_update_available=False,
            major_upgrade_available=False,
        )

    # Find latest minor (same major.minor line) and latest major
    best_minor = (current_major, current_minor, current_patch)
    best_major = (current_major, current_minor, current_patch)

    for tag in stable_tags:
        try:
            major, minor, patch = _parse_version(tag)
        except (ValueError, IndexError):
            continue

        v = (major, minor, patch)

        # Same major version line -- candidate for minor/patch update
        if major == current_major and v > best_minor:
            best_minor = v

        # Any version -- candidate for latest overall
        if v > best_major:
            best_major = v

    latest_minor_str = ".".join(str(x) for x in best_minor)
    latest_major_str = ".".join(str(x) for x in best_major)

    return MysqlVersionInfo(
        current=current,
        latest_minor=latest_minor_str,
        latest_major=latest_major_str,
        minor_update_available=best_minor > (current_major, current_minor, current_patch),
        major_upgrade_available=best_major[0] > current_major,
    )
