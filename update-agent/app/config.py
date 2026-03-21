from functools import lru_cache

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Application settings loaded from environment variables."""

    update_agent_secret: str
    mysql_root_password: str
    mysql_host: str = "mysql"
    mysql_port: int = 3306
    github_repo: str = "alphapanel/alphapanel-docker"
    project_root: str = "/project"

    model_config = {"env_file": ".env", "extra": "ignore"}


@lru_cache
def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
