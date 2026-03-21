from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.config import Settings, get_settings

_scheme = HTTPBearer()


async def require_auth(
    credentials: HTTPAuthorizationCredentials = Depends(_scheme),
    settings: Settings = Depends(get_settings),
) -> str:
    """Validate the Bearer token against the configured secret.

    Returns the token on success; raises 401 on mismatch.
    """
    if credentials.credentials != settings.update_agent_secret:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing authentication token.",
            headers={"WWW-Authenticate": "Bearer"},
        )
    return credentials.credentials
