from functools import lru_cache

from pydantic import field_validator
from pydantic_settings import BaseSettings

# Secret strength policy. Enforced at startup so a misconfigured deployment
# fails closed instead of running the agent with a guessable bearer token.
_MIN_SECRET_LENGTH = 32
_WEAK_SECRETS = {
    "change_me",
    "changeme",
    "change-me",
    "secret",
    "password",
    "update_agent_secret",
    "your_secret_here",
}


class Settings(BaseSettings):
    """Application settings loaded from environment variables."""

    update_agent_secret: str
    mysql_root_password: str
    mysql_user: str = "root"
    mysql_password: str = ""
    mysql_host: str = "mysql"
    mysql_port: int = 3306
    github_repo: str = "Alpha-Panel/AlphaPanel"
    project_root: str = "/project"
    mysql_update_check: bool = True

    model_config = {"env_file": ".env", "extra": "ignore"}

    @field_validator("update_agent_secret")
    @classmethod
    def _validate_secret_strength(cls, value: str) -> str:
        """Reject empty, too-short, or known-weak bearer secrets.

        Fails closed at startup: a weak secret on a remote-reachable update
        agent is effectively no authentication at all.
        """
        candidate = (value or "").strip()
        if not candidate:
            raise ValueError("UPDATE_AGENT_SECRET must be set and non-empty.")
        if len(candidate) < _MIN_SECRET_LENGTH:
            raise ValueError(
                f"UPDATE_AGENT_SECRET must be at least {_MIN_SECRET_LENGTH} "
                f"characters (got {len(candidate)})."
            )
        if candidate.lower() in _WEAK_SECRETS:
            raise ValueError(
                "UPDATE_AGENT_SECRET is a known-weak value; choose a strong, "
                "randomly generated secret."
            )
        return value


@lru_cache
def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
