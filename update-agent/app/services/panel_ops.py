from __future__ import annotations

import asyncio
import logging
import os
import re
import shlex
from dataclasses import dataclass

logger = logging.getLogger(__name__)

# Patterns that may carry secrets in a command line. Used to redact before any
# command string is written to logs (which may ship to aggregators).
#   -p<secret>           mysql/mysqladmin inline password (no space)
#   --password=<secret>  long-form password flag
#   token / secret-ish env assignments inline on the command
_SECRET_PATTERNS = [
    (re.compile(r"(-p)(?!\s)(\S+)"), r"\1***"),
    (re.compile(r"(--password=)(\S+)"), r"\1***"),
    (re.compile(r"(?i)((?:password|secret|token)=)(\S+)"), r"\1***"),
]


def _redact(text: str) -> str:
    """Mask likely secrets in a command/argument string for safe logging."""
    redacted = text
    for pattern, repl in _SECRET_PATTERNS:
        redacted = pattern.sub(repl, redacted)
    return redacted


def _program_only(shell_cmd: str) -> str:
    """Return just the program name (first token) for low-detail logging."""
    try:
        tokens = shlex.split(shell_cmd)
    except ValueError:
        return shell_cmd.split()[0] if shell_cmd.split() else "<command>"
    return tokens[0] if tokens else "<command>"


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

    logger.info(
        "Running: %s (cwd=%s, timeout=%ds)", _redact(shell_cmd), cwd, timeout
    )

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
            stderr=f"Command timed out after {timeout}s: {_redact(shell_cmd)}",
        )

    result = CommandResult(
        returncode=proc.returncode or 0,
        stdout=stdout_bytes.decode("utf-8", errors="replace").strip(),
        stderr=stderr_bytes.decode("utf-8", errors="replace").strip(),
    )

    if not result.ok:
        # Log the program name plus a redacted command line; stdout/stderr can
        # legitimately contain secrets echoed by tools, so keep them at debug.
        logger.error(
            "Command failed (rc=%d): %s",
            result.returncode,
            _redact(shell_cmd),
        )
        logger.debug(
            "Failed command output for %s — stdout: %s | stderr: %s",
            _program_only(shell_cmd),
            _redact(result.stdout[:500]),
            _redact(result.stderr[:500]),
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


async def ensure_compose_project_name(
    reference_containers: tuple[str, ...] = ("alpha_panel_web", "mysql", "frankenphp"),
) -> str | None:
    """Resolve and export COMPOSE_PROJECT_NAME for the running stack.

    The agent runs `docker compose -f /project/docker-compose.yaml`, whose implied
    project name is the mount-dir basename ("project"). The operator started the stack
    from the install directory, so the live containers carry a different project name —
    making `docker compose exec/up` target an empty project and fail with
    "service ... is not running". Read the real project name from a running container's
    compose label and export it to the process environment so every compose call the
    agent makes (panel and MySQL flows alike) targets the live stack. Idempotent.
    """
    fmt = '{{ index .Config.Labels "com.docker.compose.project" }}'
    for name in reference_containers:
        result = await run_cmd(
            f"docker inspect --format '{fmt}' {name}",
            timeout=10,
        )
        project = result.stdout.strip() if result.ok else ""
        if project:
            os.environ["COMPOSE_PROJECT_NAME"] = project
            logger.info("Resolved compose project name '%s' from container '%s'", project, name)
            return project

    logger.warning("Could not resolve compose project name from any reference container")
    return None


def _compose_base(project_root: str) -> str:
    """Return the base docker compose command with the project compose file."""
    return f"docker compose -f {project_root}/docker-compose.yaml"


async def compose_exec(
    service: str,
    command: str,
    project_root: str,
    timeout: int = 300,
    user: str = "root",
) -> CommandResult:
    """Execute a command inside a known stack container by name.

    Uses ``docker exec -u <user>`` directly rather than ``docker compose
    exec``, so the call does not depend on compose project-name resolution.
    Every service the agent talks to (``alpha_panel_web``, ``mysql``, ...)
    pins ``container_name:`` in docker-compose.yaml, so the service name
    doubles as the container name and is found regardless of how the stack
    was launched or which directory the compose file lives in.

    Defaulting to ``-u root`` mirrors the Jenkins pattern
    (``docker exec -u root frankenphp supervisorctl restart all``) and
    sidesteps permission failures when a future image adds a non-root
    ``USER`` directive — composer/npm/artisan all need write access to
    ``vendor/``, ``node_modules/``, ``bootstrap/cache/``, and storage.
    """
    cmd = f"docker exec -u {user} -i {service} {command}"
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
