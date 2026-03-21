from __future__ import annotations

import logging

from fastapi import APIRouter

from app.services.panel_ops import run_cmd

logger = logging.getLogger(__name__)

router = APIRouter(tags=["health"])


@router.get("/health")
async def health_check() -> dict:
    """Return service health status and verify Docker socket is accessible."""
    docker_version = "unavailable"
    docker_ok = False

    result = await run_cmd("docker info --format '{{.ServerVersion}}'", timeout=10)
    if result.ok and result.stdout:
        docker_version = result.stdout.strip().strip("'")
        docker_ok = True
    else:
        logger.warning("Docker socket check failed: %s", result.stderr)

    return {
        "status": "ok" if docker_ok else "degraded",
        "version": "1.0.0",
        "docker": {
            "connected": docker_ok,
            "version": docker_version,
        },
    }
