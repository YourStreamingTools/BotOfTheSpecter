"""
Host resource metrics for public GET /health/metrics.

Prefers psutil when installed; falls back to Linux /proc so metrics still work
if a host has not pip-installed psutil yet. Network rates use the previous
sample in this process (first call after start reports 0 MB/s for net).
"""
from __future__ import annotations

import os
import time
from typing import Any, Optional

try:
    import psutil
except ImportError:  # pragma: no cover
    psutil = None  # type: ignore

_cpu_primed = False
_prev_net: Optional[tuple[float, int, int]] = None  # (time, bytes_sent, bytes_recv)
_prev_cpu: Optional[tuple[int, int]] = None  # (total, idle) ticks from /proc/stat


def _gb(n: float) -> float:
    return round(n / (1024**3), 2)


def _read_proc_meminfo() -> dict[str, int]:
    out: dict[str, int] = {}
    with open("/proc/meminfo", "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            if ":" not in line:
                continue
            key, rest = line.split(":", 1)
            parts = rest.split()
            if not parts:
                continue
            try:
                # Values are kB
                out[key.strip()] = int(parts[0]) * 1024
            except ValueError:
                continue
    return out


def _read_proc_cpu_times() -> tuple[int, int]:
    with open("/proc/stat", "r", encoding="utf-8", errors="replace") as f:
        line = f.readline()
    parts = line.split()
    # cpu user nice system idle iowait irq softirq steal ...
    nums = [int(x) for x in parts[1:] if x.isdigit()]
    if len(nums) < 4:
        return 0, 0
    idle = nums[3] + (nums[4] if len(nums) > 4 else 0)
    return sum(nums), idle


def _read_proc_net_bytes() -> tuple[int, int]:
    sent = 0
    recv = 0
    with open("/proc/net/dev", "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            if ":" not in line:
                continue
            name, rest = line.split(":", 1)
            iface = name.strip()
            if iface == "lo":
                continue
            cols = rest.split()
            if len(cols) < 9:
                continue
            try:
                recv += int(cols[0])
                sent += int(cols[8])
            except ValueError:
                continue
    return sent, recv


def _collect_via_proc(server_name: str, service: str) -> dict[str, Any]:
    """Linux /proc fallback when psutil is unavailable."""
    global _prev_net, _prev_cpu

    if not os.path.isfile("/proc/meminfo"):
        return {
            "ok": False,
            "error": "psutil not installed and /proc metrics unavailable",
            "server_name": server_name,
            "service": service,
        }

    mem = _read_proc_meminfo()
    mem_total = int(mem.get("MemTotal", 0))
    mem_avail = int(mem.get("MemAvailable", mem.get("MemFree", 0)))
    mem_used = max(0, mem_total - mem_avail)
    swap_total = int(mem.get("SwapTotal", 0))
    swap_free = int(mem.get("SwapFree", 0))
    swap_used = max(0, swap_total - swap_free)
    # Fold swap into RAM totals (no separate swap fields on the status UI).
    ram_total_b = mem_total + swap_total
    ram_used_b = mem_used + swap_used
    ram_percent = (ram_used_b / ram_total_b * 100.0) if ram_total_b > 0 else 0.0

    disk_path = os.getenv("METRICS_DISK_PATH") or "/"
    try:
        st = os.statvfs(disk_path)
    except OSError:
        st = os.statvfs("/")
    disk_total = st.f_frsize * st.f_blocks
    disk_free = st.f_frsize * st.f_bavail
    disk_used = max(0, disk_total - disk_free)
    disk_percent = (disk_used / disk_total * 100.0) if disk_total > 0 else 0.0

    total1, idle1 = _read_proc_cpu_times()
    cpu_percent = 0.0
    if _prev_cpu is not None:
        pt, pi = _prev_cpu
        d_total = max(0, total1 - pt)
        d_idle = max(0, idle1 - pi)
        if d_total > 0:
            cpu_percent = max(0.0, min(100.0, (1.0 - (d_idle / d_total)) * 100.0))
    else:
        # Prime with a short sample so the first public response is usable.
        time.sleep(0.1)
        total2, idle2 = _read_proc_cpu_times()
        d_total = max(0, total2 - total1)
        d_idle = max(0, idle2 - idle1)
        if d_total > 0:
            cpu_percent = max(0.0, min(100.0, (1.0 - (d_idle / d_total)) * 100.0))
        total1, idle1 = total2, idle2
    _prev_cpu = (total1, idle1)

    now = time.time()
    bytes_sent, bytes_recv = _read_proc_net_bytes()
    net_sent = 0.0
    net_recv = 0.0
    if _prev_net is not None:
        prev_t, prev_sent, prev_recv = _prev_net
        dt = max(now - prev_t, 1e-3)
        net_sent = max(0.0, (bytes_sent - prev_sent) / dt / (1024**2))
        net_recv = max(0.0, (bytes_recv - prev_recv) / dt / (1024**2))
    _prev_net = (now, bytes_sent, bytes_recv)

    return {
        "ok": True,
        "server_name": server_name,
        "service": service,
        "cpu_percent": round(cpu_percent, 1),
        "ram_percent": round(ram_percent, 1),
        "ram_used": _gb(ram_used_b),
        "ram_total": _gb(ram_total_b),
        "disk_percent": round(disk_percent, 1),
        "disk_used": _gb(disk_used),
        "disk_total": _gb(disk_total),
        "net_sent": round(net_sent, 3),
        "net_recv": round(net_recv, 3),
        "collected_at": int(now),
    }


def _collect_via_psutil(server_name: str, service: str) -> dict[str, Any]:
    global _cpu_primed, _prev_net

    if not _cpu_primed:
        # Short blocking sample primes the counter for later non-blocking reads.
        cpu_percent = float(psutil.cpu_percent(interval=0.1))
        _cpu_primed = True
    else:
        cpu_percent = float(psutil.cpu_percent(interval=None))

    memory = psutil.virtual_memory()
    swap = psutil.swap_memory()
    # Fold swap into RAM totals (no separate swap fields on the status UI).
    ram_total_b = int(memory.total) + int(swap.total or 0)
    ram_used_b = int(memory.used) + int(swap.used or 0)
    if ram_total_b > 0:
        ram_percent = (ram_used_b / ram_total_b) * 100.0
    else:
        ram_percent = float(memory.percent)

    disk_path = os.getenv("METRICS_DISK_PATH") or ("C:\\" if os.name == "nt" else "/")
    try:
        disk = psutil.disk_usage(disk_path)
    except Exception:
        disk = psutil.disk_usage("/")

    now = time.time()
    net = psutil.net_io_counters()
    net_sent = 0.0
    net_recv = 0.0
    if _prev_net is not None:
        prev_t, prev_sent, prev_recv = _prev_net
        dt = max(now - prev_t, 1e-3)
        net_sent = max(0.0, (net.bytes_sent - prev_sent) / dt / (1024**2))
        net_recv = max(0.0, (net.bytes_recv - prev_recv) / dt / (1024**2))
    _prev_net = (now, int(net.bytes_sent), int(net.bytes_recv))

    return {
        "ok": True,
        "server_name": server_name,
        "service": service,
        "cpu_percent": round(cpu_percent, 1),
        "ram_percent": round(ram_percent, 1),
        "ram_used": _gb(ram_used_b),
        "ram_total": _gb(ram_total_b),
        "disk_percent": round(float(disk.percent), 1),
        "disk_used": _gb(disk.used),
        "disk_total": _gb(disk.total),
        "net_sent": round(net_sent, 3),
        "net_recv": round(net_recv, 3),
        "collected_at": int(now),
    }


def collect_host_metrics(server_name: str, *, service: str | None = None) -> dict[str, Any]:
    """Return a JSON-serializable metrics dict matching the status page fields."""
    svc = service or server_name
    if psutil is not None:
        try:
            return _collect_via_psutil(server_name, svc)
        except Exception as e:
            # Fall through to /proc if psutil misbehaves on the host.
            proc = _collect_via_proc(server_name, svc)
            if proc.get("ok"):
                return proc
            return {
                "ok": False,
                "error": f"psutil failed: {e}",
                "server_name": server_name,
                "service": svc,
            }
    return _collect_via_proc(server_name, svc)
