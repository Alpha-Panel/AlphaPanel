from __future__ import annotations

import re
import subprocess
import urllib.request
from pathlib import Path

_PUBLIC_IP_URLS = (
    "https://ifconfig.me",
    "https://api.ipify.org",
)


def _run(cmd: list[str]) -> str:
    return subprocess.check_output(cmd, text=True, timeout=10)


def _http_get(url: str, timeout: int = 8) -> str:
    with urllib.request.urlopen(url, timeout=timeout) as r:
        return r.read().decode("ascii").strip()


def detect_os(os_release_path: Path = Path("/etc/os-release")) -> dict[str, str]:
    if not os_release_path.exists():
        return {"id": "unknown", "pretty": "unknown"}
    info = {"id": "unknown", "pretty": "unknown"}
    for line in os_release_path.read_text().splitlines():
        if "=" not in line:
            continue
        k, v = line.split("=", 1)
        v = v.strip('"').strip()
        if k == "ID":
            info["id"] = v
        elif k == "PRETTY_NAME":
            info["pretty"] = v
    return info


def detect_private_ip() -> str:
    try:
        out = _run(["ip", "route", "get", "1.1.1.1"])
        m = re.search(r"\bsrc\s+(\S+)", out)
        if m:
            return m.group(1)
    except Exception:
        pass
    return "127.0.0.1"


def detect_public_ip() -> str:
    for url in _PUBLIC_IP_URLS:
        try:
            ip = _http_get(url)
            if ip:
                return ip
        except Exception:
            continue
    return detect_private_ip()
