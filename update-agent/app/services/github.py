from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass
from pathlib import Path

import httpx

logger = logging.getLogger(__name__)

GITHUB_API = "https://api.github.com"

# owner/repo with the characters GitHub actually permits. Validating before the
# value is interpolated into an API URL prevents path traversal / SSRF-style
# redirection of the release check to an attacker-chosen endpoint.
_REPO_RE = re.compile(r"^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$")


def _validate_repo(github_repo: str) -> bool:
    """Return True if github_repo is a safe 'owner/name' slug."""
    return bool(_REPO_RE.match((github_repo or "").strip()))


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
    """Check the latest GitHub release for the panel repository.

    SECURITY TODO (supply chain): this only resolves the *latest release tag*
    and reports it. The actual deploy path (`git pull --ff-only` in
    routers/panel.py) follows the configured remote's branch tip and performs
    no integrity check. Releases should be cryptographically signed (signed
    git tags / cosign) and the deploy pinned to a verified, signed tag rather
    than the branch tip, so a compromised upstream or MITM cannot push
    arbitrary code into the running stack. Tracked separately; not changed here
    to avoid breaking the existing update mechanics.
    """
    current = _read_current_version(project_root)

    # Fail closed on a malformed repo slug so a bad config value cannot be
    # interpolated into the GitHub API URL.
    if not _validate_repo(github_repo):
        logger.error("Refusing GitHub check for invalid repo slug: %r", github_repo)
        return PanelVersionInfo(
            current=current,
            latest=current,
            update_available=False,
            release_notes="",
            release_url="",
        )

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
