import logging
from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.config import get_settings
from app.routers import check, health, mysql, mysql_config, panel, status
from app.services.panel_ops import ensure_https_git_remote

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(application: FastAPI):
    """Manage shared resources across the application lifecycle."""
    application.state.http_client = httpx.AsyncClient(
        timeout=httpx.Timeout(30.0, connect=10.0),
        headers={"Accept": "application/json"},
    )

    # Self-heal git transport on boot: the image has no ssh client, so an SSH origin
    # would break automated pulls. Best-effort — a failure here must not block startup.
    try:
        result = await ensure_https_git_remote(get_settings().project_root)
        if not result.ok:
            logger.warning("Startup git transport normalization failed: %s", result.stderr)
    except Exception as exc:  # noqa: BLE001
        logger.warning("Startup git transport normalization errored: %s", exc)

    yield
    await application.state.http_client.aclose()


app = FastAPI(
    title="AlphaPanel Update Agent",
    version="1.0.0",
    description="Handles automated updates and upgrades for the AlphaPanel hosting stack.",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(check.router)
app.include_router(panel.router)
app.include_router(mysql.router)
app.include_router(mysql_config.router)
app.include_router(status.router)
