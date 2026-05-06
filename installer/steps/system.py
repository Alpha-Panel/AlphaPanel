from __future__ import annotations

import ipaddress
import re
import subprocess
import urllib.request
from pathlib import Path

# IPv4-only endpoints — avoids getting IPv6 on dual-stack servers
_PUBLIC_IP_URLS = (
    "https://api4.ipify.org",
    "https://ipv4.icanhazip.com",
    "https://ipv4.ifconfig.me",
)

# WireGuard interface name prefixes (highest priority for private IP selection)
_WG_PREFIXES = ("wg", "vpn", "tun")


def _run(cmd: list[str]) -> str:
    return subprocess.check_output(cmd, text=True, timeout=10)


def _http_get(url: str, timeout: int = 8) -> str:
    with urllib.request.urlopen(url, timeout=timeout) as r:
        return r.read().decode("ascii").strip()


def _is_rfc1918(ip_str: str) -> bool:
    """Return True only for RFC-1918 private unicast addresses (10/8, 172.16/12, 192.168/16)."""
    try:
        addr = ipaddress.ip_address(ip_str)
        return addr.is_private and not addr.is_loopback and not addr.is_link_local
    except ValueError:
        return False


def _is_wg_iface(name: str) -> bool:
    return any(name.startswith(p) for p in _WG_PREFIXES)


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


def _list_ipv4_ifaces() -> list[tuple[str, str]]:
    """Return [(iface_name, ipv4_addr), ...] for all non-loopback IPv4 addresses."""
    try:
        out = _run(["ip", "-4", "addr", "show"])
    except Exception:
        return []

    results: list[tuple[str, str]] = []
    current_iface = ""
    for line in out.splitlines():
        iface_match = re.match(r"^\d+:\s+(\S+)", line)
        if iface_match:
            current_iface = iface_match.group(1).rstrip(":")
        inet_match = re.search(r"inet\s+(\d+\.\d+\.\d+\.\d+)", line)
        if inet_match and current_iface:
            results.append((current_iface, inet_match.group(1)))
    return results


def detect_private_ip() -> str:
    """
    Return the best RFC-1918 IP on this host.
    Priority: WireGuard/VPN interfaces > other private interfaces.
    Falls back to 127.0.0.1 if nothing private is found.
    """
    ifaces = _list_ipv4_ifaces()
    private = [(iface, ip) for iface, ip in ifaces if _is_rfc1918(ip)]

    wg = [(iface, ip) for iface, ip in private if _is_wg_iface(iface)]
    if wg:
        return wg[0][1]

    if private:
        return private[0][1]

    return "127.0.0.1"


def detect_public_ip() -> str:
    """Return the public IPv4 address of this host."""
    for url in _PUBLIC_IP_URLS:
        try:
            ip = _http_get(url)
            if ip and re.match(r"^\d+\.\d+\.\d+\.\d+$", ip):
                return ip
        except Exception:
            continue
    # Last resort: use the route-to-internet source IP (even if public)
    try:
        out = _run(["ip", "-4", "route", "get", "1.1.1.1"])
        m = re.search(r"\bsrc\s+(\d+\.\d+\.\d+\.\d+)", out)
        if m:
            return m.group(1)
    except Exception:
        pass
    return ""
