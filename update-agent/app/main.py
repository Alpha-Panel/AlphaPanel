from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.routers import check, health, mysql, panel, status


@asynccontextmanager
async def lifespan(application: FastAPI):
    """Manage shared resources across the application lifecycle."""
    application.state.http_client = httpx.AsyncClient(
        timeout=httpx.Timeout(30.0, connect=10.0),
        headers={"Accept": "application/json"},
    )
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
app.include_router(status.router)
