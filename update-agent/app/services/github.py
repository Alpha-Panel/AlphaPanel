from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from pathlib import Path

import httpx

logger = logging.getLogger(__name__)

GITHUB_API = "https://api.github.com"


@dataclass
class PanelVersionInfo:
    current: str
    latest: str
    update_available: bool
    release_notes: str
    release_url: str


def _read_current_version(project_root: str) -> str:
    """Read the current panel version from version.json."""
    version_file = Path(project_root) / "version.json"
    try:
        data = json.loads(version_file.read_text(encoding="utf-8"))
        return data.get("version", "0.0.0")
    except (FileNotFoundError, json.JSONDecodeError, KeyError) as exc:
        logger.warning("Could not read version.json: %s", exc)
        return "0.0.0"


def _strip_v(tag: str) -> str:
    """Remove leading 'v' from a tag name."""
    return tag.lstrip("vV")


async def check_panel_update(
    client: httpx.AsyncClient,
    github_repo: str,
    project_root: str,
) -> PanelVersionInfo:
    """Check the latest GitHub release for the panel repository."""
    current = _read_current_version(project_root)

    url = f"{GITHUB_API}/repos/{github_repo}/releases/latest"
    try:
        resp = await client.get(url)
        resp.raise_for_status()
        data = resp.json()
    except httpx.HTTPStatusError as exc:
        logger.error("GitHub API error %s: %s", exc.response.status_code, exc.response.text)
        return PanelVersionInfo(
            current=current,
            latest=current,
            update_available=False,
            release_notes="",
            release_url="",
        )
    except httpx.HTTPError as exc:
        logger.error("GitHub API request failed: %s", exc)
        return PanelVersionInfo(
            current=current,
            latest=current,
            update_available=False,
            release_notes="",
            release_url="",
        )

    latest = _strip_v(data.get("tag_name", current))
    release_notes = data.get("body", "") or ""
    release_url = data.get("html_url", "")

    return PanelVersionInfo(
        current=current,
        latest=latest,
        update_available=_version_newer(latest, current),
        release_notes=release_notes,
        release_url=release_url,
    )


def _version_newer(latest: str, current: str) -> bool:
    """Compare semver strings. Returns True if latest > current."""
    try:
        latest_parts = tuple(int(x) for x in latest.split("."))
        current_parts = tuple(int(x) for x in current.split("."))
        return latest_parts > current_parts
    except (ValueError, AttributeError):
        return latest != current
