from functools import lru_cache

from pydantic_settings import BaseSettings


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


@lru_cache
def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
