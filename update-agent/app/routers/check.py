from __future__ import annotations

from dataclasses import asdict

from fastapi import APIRouter, Depends, Request

from app.auth import require_auth
from app.config import Settings, get_settings
from app.services.docker_hub import MysqlVersionInfo, check_mysql_updates
from app.services.github import check_panel_update

router = APIRouter(tags=["check"])


@router.get("/check", dependencies=[Depends(require_auth)])
async def check_updates(
    request: Request,
    settings: Settings = Depends(get_settings),
) -> dict:
    """Check for available panel and MySQL updates."""
    client = request.app.state.http_client

    panel_info = await check_panel_update(
        client=client,
        github_repo=settings.github_repo,
        project_root=settings.project_root,
    )

    if settings.mysql_update_check:
        mysql_info = await check_mysql_updates(
            client=client,
            project_root=settings.project_root,
        )
    else:
        # MySQL check disabled by operator. Report no update available
        # with a placeholder current version so the panel UI stays clean.
        mysql_info = MysqlVersionInfo(
            current="disabled",
            latest_minor="disabled",
            latest_major="disabled",
            minor_update_available=False,
            major_upgrade_available=False,
        )

    return {
        "panel": asdict(panel_info),
        "mysql": asdict(mysql_info),
    }
