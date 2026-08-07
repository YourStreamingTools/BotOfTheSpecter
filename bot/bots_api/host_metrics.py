"""
Host resource metrics for public GET /health/metrics.

Non-blocking collection (no 1s sleeps). Network rates use the previous sample
in this process; first call after start reports 0 MB/s for net.
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


def collect_host_metrics(server_name: str, *, service: str | None = None) -> dict[str, Any]:
    """Return a JSON-serializable metrics dict matching the status page fields."""
    if psutil is None:
        return {
            "ok": False,
            "error": "psutil not installed",
            "server_name": server_name,
            "service": service or server_name,
        }

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
        "service": service or server_name,
        "cpu_percent": round(cpu_percent, 1),
        "ram_percent": round(ram_percent, 1),
        "ram_used": round(ram_used_b / (1024**3), 2),
        "ram_total": round(ram_total_b / (1024**3), 2),
        "disk_percent": round(float(disk.percent), 1),
        "disk_used": round(disk.used / (1024**3), 2),
        "disk_total": round(disk.total / (1024**3), 2),
        "net_sent": round(net_sent, 3),
        "net_recv": round(net_recv, 3),
        "collected_at": int(now),
    }
