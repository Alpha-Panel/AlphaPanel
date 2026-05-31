from __future__ import annotations

import asyncio
import logging
from dataclasses import dataclass

logger = logging.getLogger(__name__)


@dataclass
class CommandResult:
    returncode: int
    stdout: str
    stderr: str

    @property
    def ok(self) -> bool:
        return self.returncode == 0


async def run_cmd(
    cmd: str | list[str],
    cwd: str | None = None,
    timeout: int = 300,
) -> CommandResult:
    """Run a shell command asynchronously and capture output."""
    if isinstance(cmd, list):
        shell_cmd = " ".join(cmd)
    else:
        shell_cmd = cmd

    logger.info("Running: %s (cwd=%s, timeout=%ds)", shell_cmd, cwd, timeout)

    proc = await asyncio.create_subprocess_shell(
        shell_cmd,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
        cwd=cwd,
    )

    try:
        stdout_bytes, stderr_bytes = await asyncio.wait_for(
            proc.communicate(),
            timeout=timeout,
        )
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        return CommandResult(
            returncode=-1,
            stdout="",
            stderr=f"Command timed out after {timeout}s: {shell_cmd}",
        )

    result = CommandResult(
        returncode=proc.returncode or 0,
        stdout=stdout_bytes.decode("utf-8", errors="replace").strip(),
        stderr=stderr_bytes.decode("utf-8", errors="replace").strip(),
    )

    if not result.ok:
        logger.error(
            "Command failed (rc=%d): %s\nstdout: %s\nstderr: %s",
            result.returncode,
            shell_cmd,
            result.stdout[:500],
            result.stderr[:500],
        )

    return result


async def ensure_https_git_remote(project_root: str) -> CommandResult:
    """Force the GitHub SSH transport to public HTTPS for the project repo.

    The agent image ships without an ssh client, so an SSH origin (the installer's
    historical default) breaks `git pull` with "cannot run ssh". Rewriting the
    transport is idempotent, credential-free for the public repo, and preserves the
    configured repo/fork path (only the transport changes). Run at agent startup and
    before each update so existing SSH-cloned servers self-correct without manual steps.
    """
    return await run_cmd(
        'git config url."https://github.com/".insteadOf "git@github.com:"',
        cwd=project_root,
        timeout=15,
    )


def _compose_base(project_root: str) -> str:
    """Return the base docker compose command with the project compose file."""
    return f"docker compose -f {project_root}/docker-compose.yaml"


async def compose_exec(
    service: str,
    command: str,
    project_root: str,
    timeout: int = 300,
) -> CommandResult:
    """Execute a command inside a running compose service container."""
    base = _compose_base(project_root)
    cmd = f"{base} exec -T {service} {command}"
    return await run_cmd(cmd, cwd=project_root, timeout=timeout)


async def compose_up(
    services: list[str],
    project_root: str,
    force_recreate: bool = False,
    compose_file: str | None = None,
) -> CommandResult:
    """Bring up one or more compose services."""
    if compose_file:
        base = f"docker compose -f {compose_file}"
    else:
        base = _compose_base(project_root)

    flags = "-d"
    if force_recreate:
        flags += " --force-recreate"

    svc_list = " ".join(services)
    cmd = f"{base} up {flags} {svc_list}"
    return await run_cmd(cmd, cwd=project_root, timeout=300)


async def compose_stop(
    services: list[str],
    project_root: str,
    compose_file: str | None = None,
) -> CommandResult:
    """Stop one or more compose services without removing them."""
    if compose_file:
        base = f"docker compose -f {compose_file}"
    else:
        base = _compose_base(project_root)

    svc_list = " ".join(services)
    cmd = f"{base} stop {svc_list}"
    return await run_cmd(cmd, cwd=project_root, timeout=120)


async def compose_down(
    compose_file: str,
    project_root: str | None = None,
) -> CommandResult:
    """Tear down all services defined in the given compose file."""
    cmd = f"docker compose -f {compose_file} down -v"
    return await run_cmd(cmd, cwd=project_root, timeout=120)
