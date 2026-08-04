"""Allowlisted systemd control for the WebSocket host (no SSH)."""
from __future__ import annotations

import subprocess
from typing import Any

# Unit basename → full systemd unit. Only these may be started/stopped.
ALLOWED_UNITS: dict[str, str] = {
    "websocket": "websocket.service",
    "caddy": "caddy.service",
    "websocket-control": "websocket-control.service",
}


def _normalize_unit(name: str) -> str | None:
    key = (name or "").strip().lower()
    if key.endswith(".service"):
        key = key[: -len(".service")]
    return ALLOWED_UNITS.get(key)


def _run(cmd: list[str], timeout: float = 30.0) -> tuple[int, str]:
    try:
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
        out = ((proc.stdout or "") + (proc.stderr or "")).strip()
        return proc.returncode, out
    except subprocess.TimeoutExpired:
        return 124, "command timed out"
    except FileNotFoundError:
        return 127, f"not found: {cmd[0]}"
    except Exception as e:
        return 1, str(e)


def _systemctl(*args: str) -> tuple[int, str]:
    code, out = _run(["systemctl", *args])
    if code == 0:
        return code, out
    # Non-root deploy: passwordless sudo (same as dashboard SSH control)
    return _run(["sudo", "-n", "systemctl", *args])


def unit_status(name: str) -> dict[str, Any]:
    unit = _normalize_unit(name)
    if not unit:
        return {
            "ok": False,
            "unit": name,
            "status": "Unknown",
            "pid": None,
            "error": f"Unit not allowlisted: {name}",
        }
    code, out = _systemctl("show", unit, "--property=ActiveState,SubState,MainPID", "--no-pager")
    if code != 0:
        return {
            "ok": False,
            "unit": unit,
            "status": "Error",
            "pid": None,
            "error": out or f"systemctl show exit {code}",
        }
    props: dict[str, str] = {}
    for line in out.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            props[k.strip()] = v.strip()
    active = props.get("ActiveState", "")
    sub = props.get("SubState", "")
    pid_raw = props.get("MainPID", "0")
    try:
        pid = int(pid_raw) if pid_raw and pid_raw != "0" else None
    except ValueError:
        pid = None
    if active == "active" and sub == "running":
        status_label = "Running"
    elif active == "inactive":
        status_label = "Stopped"
    elif active == "failed":
        status_label = "Failed"
    else:
        status_label = active or "Unknown"
        if sub:
            status_label = f"{status_label} ({sub})"
    return {
        "ok": True,
        "unit": unit,
        "status": status_label,
        "active_state": active,
        "sub_state": sub,
        "pid": pid,
        "error": None,
    }


def list_services() -> dict[str, Any]:
    items = [unit_status(k) for k in ALLOWED_UNITS]
    return {"ok": True, "count": len(items), "services": items}


def control_unit(name: str, action: str) -> dict[str, Any]:
    action = (action or "").strip().lower()
    if action not in ("start", "stop", "restart", "reload"):
        return {"ok": False, "error": f"Invalid action: {action}"}
    unit = _normalize_unit(name)
    if not unit:
        return {"ok": False, "unit": name, "error": f"Unit not allowlisted: {name}"}
    code, out = _systemctl(action, unit)
    if code != 0:
        return {
            "ok": False,
            "unit": unit,
            "action": action,
            "error": out or f"systemctl {action} exit {code}",
        }
    st = unit_status(name)
    return {
        "ok": True,
        "unit": unit,
        "action": action,
        "message": f"{unit} {action}ed successfully",
        "status": st.get("status"),
        "pid": st.get("pid"),
    }
