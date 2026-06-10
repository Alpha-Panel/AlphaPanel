from __future__ import annotations

import hmac
import logging
import time

from fastapi import Depends, HTTPException, Request, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.config import Settings, get_settings

logger = logging.getLogger(__name__)

_scheme = HTTPBearer()

# In-memory failed-auth backoff (stdlib only; single-process FastAPI/uvicorn
# worker assumed). Keyed by client IP. After _MAX_FAILURES failures within
# _FAILURE_WINDOW seconds, the IP is locked out (429) for _COOLDOWN seconds.
# This is a lightweight brute-force speed bump, not a substitute for an
# upstream WAF/rate limiter — but it closes the "unlimited online guessing"
# gap on this server-to-server endpoint with zero added dependencies.
_MAX_FAILURES = 10
_FAILURE_WINDOW = 60.0
_COOLDOWN = 300.0

# ip -> (failure_count, window_start_monotonic, locked_until_monotonic)
_failures: dict[str, tuple[int, float, float]] = {}

# Evict stale IP entries beyond this many to bound memory under IP-spoofed
# floods. Entries are pruned opportunistically on each auth attempt.
_MAX_TRACKED_IPS = 10_000


def _client_ip(request: Request) -> str:
    """Best-effort client IP for rate-limit keying.

    Uses the socket peer only. This agent sits on a private Docker network
    behind the panel; we intentionally do NOT trust X-Forwarded-For here, as
    that header is client-controlled and would let an attacker rotate keys to
    bypass the backoff.
    """
    if request.client and request.client.host:
        return request.client.host
    return "unknown"


def _prune(now: float) -> None:
    """Drop expired entries; hard-cap the table size to bound memory."""
    expired = [
        ip
        for ip, (_count, window_start, locked_until) in _failures.items()
        if locked_until <= now and (now - window_start) > _FAILURE_WINDOW
    ]
    for ip in expired:
        _failures.pop(ip, None)

    if len(_failures) > _MAX_TRACKED_IPS:
        # Evict oldest-window entries first.
        for ip, _ in sorted(_failures.items(), key=lambda kv: kv[1][1])[
            : len(_failures) - _MAX_TRACKED_IPS
        ]:
            _failures.pop(ip, None)


def _check_locked(ip: str, now: float) -> None:
    """Raise 429 if the IP is currently in cooldown."""
    entry = _failures.get(ip)
    if entry is None:
        return
    _count, _window_start, locked_until = entry
    if locked_until > now:
        retry_after = max(1, int(locked_until - now))
        logger.warning("Auth lockout active for %s (%ss remaining)", ip, retry_after)
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="Too many failed authentication attempts. Try again later.",
            headers={"Retry-After": str(retry_after)},
        )


def _record_failure(ip: str, now: float) -> None:
    """Increment the failure counter for an IP and arm the lockout at the cap."""
    count, window_start, locked_until = _failures.get(ip, (0, now, 0.0))

    # Reset the window if it has elapsed since the first counted failure.
    if (now - window_start) > _FAILURE_WINDOW:
        count, window_start, locked_until = 0, now, 0.0

    count += 1
    if count >= _MAX_FAILURES:
        locked_until = now + _COOLDOWN
        logger.warning(
            "IP %s reached %d failed auth attempts; locking out for %ds",
            ip, count, int(_COOLDOWN),
        )

    _failures[ip] = (count, window_start, locked_until)


def _record_success(ip: str) -> None:
    """Clear any failure state for an IP after a successful auth."""
    _failures.pop(ip, None)


async def require_auth(
    request: Request,
    credentials: HTTPAuthorizationCredentials = Depends(_scheme),
    settings: Settings = Depends(get_settings),
) -> str:
    """Validate the Bearer token against the configured secret.

    Uses a constant-time comparison to avoid leaking the secret via response
    timing, rejects empty tokens/secret (fail closed), and applies a per-IP
    failed-attempt backoff to throttle brute-force guessing.

    Returns the token on success; raises 401 on mismatch, 429 when locked out.
    """
    ip = _client_ip(request)
    now = time.monotonic()

    _prune(now)
    _check_locked(ip, now)

    secret = settings.update_agent_secret
    provided = credentials.credentials or ""

    if not secret or not hmac.compare_digest(provided.encode(), secret.encode()):
        _record_failure(ip, now)
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing authentication token.",
            headers={"WWW-Authenticate": "Bearer"},
        )

    _record_success(ip)
    return credentials.credentials
