"""
Local process management for Twitch bot variants on the bot host.

Does not talk to MySQL or Twitch — callers (public API / dashboard) supply
launch credentials. This module only starts/stops/screens/scans processes.

Also maintains a durable running-bots snapshot on disk so admins can see
which bots were last observed running after OOM/crash/reboot (process
scan alone is empty after death).
"""
from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import signal
import threading
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import psutil

log = logging.getLogger("bots_api.manager")

BOT_HOME = Path(os.getenv("BOT_HOME", "/home/botofthespecter"))
VENV_ROOT = BOT_HOME / "venvs"
LOGS = BOT_HOME / "logs"
CRASH_DIR = LOGS / "crash"
VERSION_DIR = LOGS / "version"
# Durable inventory of bots we last saw running (survives process death / OOM).
SNAPSHOT_PATH = Path(
    os.getenv("BOTS_RUNNING_SNAPSHOT", str(LOGS / "bots_running_snapshot.json"))
)
# Drop expected-but-missing snapshot rows after this many seconds (default 7d).
SNAPSHOT_STALE_MAX_SECONDS = int(os.getenv("BOTS_SNAPSHOT_STALE_MAX_SECONDS") or str(7 * 86400))
SNAPSHOT_INTERVAL_SECONDS = float(os.getenv("BOTS_SNAPSHOT_INTERVAL_SECONDS") or "15")
VERSIONS_URL = os.getenv("API_VERSIONS_URL", "https://api.botofthespecter.com/versions")

_snapshot_lock = threading.Lock()

BOT_TYPES = ("stable", "beta", "v6")

SCRIPT_BY_TYPE = {
    "stable": "bot.py",
    "beta": "beta.py",
    "v6": "beta-v6.py",
    "kick": "kick.py",
}

PYTHON_BY_TYPE = {
    "stable": VENV_ROOT / "stable" / "bin" / "python",
    "beta": VENV_ROOT / "beta" / "bin" / "python",
    "v6": VENV_ROOT / "v6" / "bin" / "python",
    "kick": VENV_ROOT / "kick" / "bin" / "python",
    "discord": VENV_ROOT / "discord" / "bin" / "python",
}

VERSION_FILE_BY_TYPE = {
    "stable": lambda ch: VERSION_DIR / f"{ch}_version_control.txt",
    "beta": lambda ch: VERSION_DIR / "beta" / f"{ch}_beta_version_control.txt",
    "v6": lambda ch: VERSION_DIR / "v6" / f"{ch}_v6_version_control.txt",
    "custom": lambda ch: VERSION_DIR / "custom" / f"{ch}_custom_version_control.txt",
    "kick": lambda ch: VERSION_DIR / "kick" / f"{ch}_kick_version_control.txt",
}

# Map process/control type → on-disk script whose mtime is "Last Updated"
SCRIPT_TYPE_FOR_MTIME = {
    "stable": "stable",
    "beta": "beta",
    "v6": "v6",
    "custom": "beta",  # custom flag runs beta.py
}


def screen_session_name(channel: str) -> str:
    return "specter_" + re.sub(r"[^a-zA-Z0-9_]", "_", channel.lower())


def _script_basename(path_or_name: str) -> str:
    return os.path.basename(path_or_name).lower()


def _cmdline_list(proc: psutil.Process) -> list[str]:
    try:
        raw = proc.cmdline() or []
    except (psutil.AccessDenied, psutil.NoSuchProcess):
        return []
    return [str(a) for a in raw]


def _channel_from_cmdline(cmdline: list[str], *, kick: bool = False) -> str | None:
    flags = ("-twitchusername", "--twitchusername") if kick else ("-channel", "--channel")
    lower = [a.lower() for a in cmdline]
    for flag in flags:
        if flag in lower:
            i = lower.index(flag)
            if i + 1 < len(cmdline):
                return cmdline[i + 1].lower()
    for a in cmdline:
        for prefix in (f"{f}=" for f in flags):
            if a.lower().startswith(prefix):
                return a.split("=", 1)[1].lower()
    return None


def _is_custom(cmdline: list[str]) -> bool:
    lower = [a.lower() for a in cmdline]
    return "-custom" in lower or "--custom" in lower


def _matches_bot_type(cmdline: list[str], bot_type: str, channel: str) -> bool:
    channel = channel.lower()
    script = None
    for arg in cmdline:
        if arg.endswith(".py") or ".py" in arg:
            script = _script_basename(arg)
            break
    if not script:
        return False
    if bot_type == "kick":
        return script == "kick.py" and _channel_from_cmdline(cmdline, kick=True) == channel
    found = _channel_from_cmdline(cmdline, kick=False)
    if found != channel:
        return False
    if bot_type == "custom":
        return _is_custom(cmdline) and script in ("bot.py", "beta.py")
    if bot_type == "stable":
        return script == "bot.py" and not _is_custom(cmdline)
    if bot_type == "beta":
        # Exclude -custom (those are bot_type "custom"; stop_bot(beta) still merges custom pids).
        return script == "beta.py" and not _is_custom(cmdline)
    if bot_type == "v6":
        return script in ("beta-v6.py", "beta_v6.py")
    return False


def find_pids(bot_type: str, channel: str) -> list[int]:
    channel = channel.lower()
    pids: list[int] = []
    for proc in psutil.process_iter(attrs=["pid", "cmdline"]):
        try:
            cmdline = _cmdline_list(proc)
            if not cmdline:
                continue
            if _matches_bot_type(cmdline, bot_type, channel):
                pids.append(proc.pid)
        except (psutil.AccessDenied, psutil.NoSuchProcess):
            continue
    return pids


def _file_mtime(path: Path) -> int | None:
    """Unix mtime seconds, or None if the path is missing/unreadable."""
    try:
        return int(path.stat().st_mtime)
    except OSError:
        return None


def script_path_for_type(bot_type: str) -> Path | None:
    """Resolved path to the bot .py script for a control type."""
    key = SCRIPT_TYPE_FOR_MTIME.get(bot_type, bot_type if bot_type in SCRIPT_BY_TYPE else None)
    if not key:
        return None
    name = SCRIPT_BY_TYPE.get(key)
    if not name:
        return None
    return BOT_HOME / name


def read_version(bot_type: str, channel: str) -> str | None:
    fn = VERSION_FILE_BY_TYPE.get(bot_type)
    if not fn:
        return None
    path = fn(channel.lower())
    try:
        text = path.read_text(encoding="utf-8").strip()
        return text or None
    except OSError:
        return None


def version_file_mtime(bot_type: str, channel: str) -> int | None:
    """mtime of the per-channel version-control file (= last successful start / last run)."""
    fn = VERSION_FILE_BY_TYPE.get(bot_type)
    if not fn:
        return None
    return _file_mtime(fn(channel.lower()))


def script_mtime_for_type(bot_type: str) -> int | None:
    path = script_path_for_type(bot_type)
    if path is None:
        return None
    return _file_mtime(path)


def write_version(bot_type: str, channel: str, version: str) -> None:
    fn = VERSION_FILE_BY_TYPE.get(bot_type)
    if not fn or not version:
        return
    path = fn(channel.lower())
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(version.strip() + "\n", encoding="utf-8")


@dataclass
class RunningBot:
    channel: str
    bot_type: str
    pid: int
    version: str | None
    custom: bool = False


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def _parse_iso(ts: str | None) -> datetime | None:
    if not ts or not isinstance(ts, str):
        return None
    try:
        # Support both ...Z and +00:00
        normalized = ts.replace("Z", "+00:00")
        dt = datetime.fromisoformat(normalized)
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt
    except (TypeError, ValueError):
        return None


def _snapshot_key(bot_type: str, channel: str) -> str:
    return f"{bot_type}:{channel.lower()}"


def _empty_snapshot() -> dict[str, Any]:
    return {"version": 1, "updated_at": None, "entries": {}}


def load_snapshot() -> dict[str, Any]:
    """Load durable snapshot from disk. Never raises."""
    try:
        if not SNAPSHOT_PATH.is_file():
            return _empty_snapshot()
        raw = SNAPSHOT_PATH.read_text(encoding="utf-8")
        data = json.loads(raw)
        if not isinstance(data, dict):
            return _empty_snapshot()
        entries = data.get("entries")
        if not isinstance(entries, dict):
            data["entries"] = {}
        data.setdefault("version", 1)
        return data
    except Exception as e:
        log.warning("failed to load running-bots snapshot: %s", e)
        return _empty_snapshot()


def _atomic_write_snapshot(data: dict[str, Any]) -> None:
    SNAPSHOT_PATH.parent.mkdir(parents=True, exist_ok=True)
    data = dict(data)
    data["updated_at"] = _utc_now_iso()
    payload = json.dumps(data, indent=2, sort_keys=True)
    tmp = SNAPSHOT_PATH.with_suffix(SNAPSHOT_PATH.suffix + ".tmp")
    tmp.write_text(payload, encoding="utf-8")
    os.replace(tmp, SNAPSHOT_PATH)


def save_snapshot(data: dict[str, Any]) -> None:
    with _snapshot_lock:
        try:
            _atomic_write_snapshot(data)
        except Exception as e:
            log.error("failed to write running-bots snapshot: %s", e)


def _flatten_live_buckets(buckets: dict[str, list[dict[str, Any]]]) -> list[dict[str, Any]]:
    flat: list[dict[str, Any]] = []
    for btype, items in buckets.items():
        for entry in items or []:
            row = dict(entry)
            row.setdefault("bot_type", btype)
            flat.append(row)
    return flat


def merge_live_into_snapshot(live_inventory: dict[str, Any]) -> dict[str, Any]:
    """
    Upsert every live process into the snapshot (refresh last_seen_at).
    Expected-but-missing rows are left alone (crash recovery candidates).
    Intentionally-stopped bots are removed via snapshot_mark_stopped().
    """
    now_iso = _utc_now_iso()
    now_dt = datetime.now(timezone.utc)
    buckets = live_inventory.get("bots") if isinstance(live_inventory, dict) else None
    if not isinstance(buckets, dict):
        buckets = {}
    live_rows = _flatten_live_buckets(buckets)
    live_keys: set[str] = set()

    with _snapshot_lock:
        data = load_snapshot()
        entries: dict[str, Any] = dict(data.get("entries") or {})

        for row in live_rows:
            channel = (row.get("channel") or "").strip().lower()
            bot_type = (row.get("bot_type") or "").strip().lower()
            if not channel or not bot_type:
                continue
            key = _snapshot_key(bot_type, channel)
            live_keys.add(key)
            prev = entries.get(key) if isinstance(entries.get(key), dict) else {}
            entries[key] = {
                "channel": channel,
                "bot_type": bot_type,
                "pid": row.get("pid"),
                "version": row.get("version"),
                "custom": bool(row.get("custom", bot_type == "custom")),
                "last_seen_at": now_iso,
                "first_seen_at": prev.get("first_seen_at") or now_iso,
                "expected": True,
            }

        # Prune very old expected-but-missing rows so intentional long-ago
        # stops that never cleared (or abandoned fleets) do not stick forever.
        stale_cutoff = now_dt.timestamp() - SNAPSHOT_STALE_MAX_SECONDS
        to_drop: list[str] = []
        for key, entry in entries.items():
            if key in live_keys:
                continue
            if not isinstance(entry, dict):
                to_drop.append(key)
                continue
            if entry.get("expected") is False:
                to_drop.append(key)
                continue
            last = _parse_iso(entry.get("last_seen_at"))
            if last is None or last.timestamp() < stale_cutoff:
                to_drop.append(key)
        for key in to_drop:
            entries.pop(key, None)

        data["entries"] = entries
        try:
            _atomic_write_snapshot(data)
        except Exception as e:
            log.error("failed to write running-bots snapshot: %s", e)
        return data


def snapshot_mark_stopped(channel: str, bot_type: str) -> None:
    """Remove channel+type from expected set after an intentional stop."""
    channel = channel.lower().strip()
    bot_type = bot_type.lower().strip()
    if not channel or not bot_type:
        return
    keys = {_snapshot_key(bot_type, channel)}
    # Stopping beta also kills custom-flag beta processes (see stop_bot).
    if bot_type == "beta":
        keys.add(_snapshot_key("custom", channel))
    if bot_type == "custom":
        keys.add(_snapshot_key("beta", channel))

    with _snapshot_lock:
        data = load_snapshot()
        entries = dict(data.get("entries") or {})
        changed = False
        for key in keys:
            if key in entries:
                entries.pop(key, None)
                changed = True
        if changed:
            data["entries"] = entries
            try:
                _atomic_write_snapshot(data)
            except Exception as e:
                log.error("failed to write snapshot after stop: %s", e)


def snapshot_mark_started(
    channel: str,
    bot_type: str,
    *,
    pid: int | None = None,
    version: str | None = None,
    custom: bool = False,
) -> None:
    """Record an intentional start (and drop other variants for the channel)."""
    channel = channel.lower().strip()
    bot_type = bot_type.lower().strip()
    if not channel or not bot_type:
        return
    now_iso = _utc_now_iso()
    key = _snapshot_key(bot_type, channel)

    with _snapshot_lock:
        data = load_snapshot()
        entries = dict(data.get("entries") or {})
        # start_bot kills other variants for the same channel
        for other in ("stable", "beta", "v6", "custom"):
            if other == bot_type:
                continue
            entries.pop(_snapshot_key(other, channel), None)
        prev = entries.get(key) if isinstance(entries.get(key), dict) else {}
        entries[key] = {
            "channel": channel,
            "bot_type": bot_type,
            "pid": pid,
            "version": version,
            "custom": custom or bot_type == "custom",
            "last_seen_at": now_iso,
            "first_seen_at": prev.get("first_seen_at") or now_iso,
            "expected": True,
        }
        data["entries"] = entries
        try:
            _atomic_write_snapshot(data)
        except Exception as e:
            log.error("failed to write snapshot after start: %s", e)


def snapshot_view(live_inventory: dict[str, Any] | None = None) -> dict[str, Any]:
    """
    Build admin-facing snapshot view: every expected bot, whether live now,
    and how long since last_seen.
    """
    if live_inventory is None:
        live_inventory = _scan_running_bots()
    buckets = live_inventory.get("bots") if isinstance(live_inventory, dict) else {}
    if not isinstance(buckets, dict):
        buckets = {}
    live_map: dict[str, dict[str, Any]] = {}
    for row in _flatten_live_buckets(buckets):
        ch = (row.get("channel") or "").strip().lower()
        bt = (row.get("bot_type") or "").strip().lower()
        if ch and bt:
            live_map[_snapshot_key(bt, ch)] = row

    data = load_snapshot()
    entries = data.get("entries") if isinstance(data.get("entries"), dict) else {}
    now = datetime.now(timezone.utc)
    out_list: list[dict[str, Any]] = []
    missing: list[dict[str, Any]] = []

    for key, entry in entries.items():
        if not isinstance(entry, dict):
            continue
        if entry.get("expected") is False:
            continue
        channel = (entry.get("channel") or "").strip().lower()
        bot_type = (entry.get("bot_type") or "").strip().lower()
        if not channel or not bot_type:
            continue
        live = live_map.get(key)
        currently_running = live is not None
        last_seen_at = entry.get("last_seen_at")
        # If live now, last_seen is effectively now (even if file lag)
        if currently_running:
            last_seen_at = _utc_now_iso()
        last_dt = _parse_iso(last_seen_at)
        if last_dt is not None:
            ago = max(0, int((now - last_dt).total_seconds()))
        else:
            ago = None
        row = {
            "channel": channel,
            "bot_type": bot_type,
            "pid": (live or entry).get("pid"),
            "version": (live or entry).get("version"),
            "custom": bool((live or entry).get("custom", bot_type == "custom")),
            "last_seen_at": last_seen_at,
            "last_seen_ago_seconds": ago,
            "first_seen_at": entry.get("first_seen_at"),
            "currently_running": currently_running,
            "expected": True,
        }
        if currently_running and live and live.get("uptime_seconds") is not None:
            row["uptime_seconds"] = live.get("uptime_seconds")
        out_list.append(row)
        if not currently_running:
            missing.append(row)

    out_list.sort(key=lambda r: (r.get("channel") or "", r.get("bot_type") or ""))
    missing.sort(key=lambda r: (r.get("channel") or "", r.get("bot_type") or ""))

    return {
        "updated_at": data.get("updated_at"),
        "bots": out_list,
        "missing": missing,
        "expected_total": len(out_list),
        "missing_total": len(missing),
        "live_total": sum(1 for r in out_list if r.get("currently_running")),
        "path": str(SNAPSHOT_PATH),
    }


def _scan_running_bots() -> dict[str, Any]:
    """Scan local processes only (no snapshot side effects)."""
    buckets: dict[str, list[dict[str, Any]]] = {
        "stable": [],
        "beta": [],
        "v6": [],
        "custom": [],
        "kick": [],
    }
    seen: set[tuple[str, str, int]] = set()

    now = time.time()
    for proc in psutil.process_iter(attrs=["pid", "cmdline", "create_time"]):
        try:
            cmdline = _cmdline_list(proc)
            if not cmdline:
                continue
            script = None
            for arg in cmdline:
                if arg.endswith(".py") or ".py" in arg:
                    script = _script_basename(arg)
                    break
            if not script:
                continue
            custom = _is_custom(cmdline)
            pid = proc.pid
            create_time = proc.info.get("create_time")
            uptime_seconds = int(now - create_time) if create_time else None

            if script == "kick.py":
                ch = _channel_from_cmdline(cmdline, kick=True)
                if not ch:
                    continue
                key = ("kick", ch, pid)
                if key in seen:
                    continue
                seen.add(key)
                buckets["kick"].append(
                    {
                        "channel": ch,
                        "pid": pid,
                        "version": read_version("kick", ch),
                        "bot_type": "kick",
                        "uptime_seconds": uptime_seconds,
                    }
                )
                continue

            ch = _channel_from_cmdline(cmdline, kick=False)
            if not ch:
                continue

            if custom and script in ("bot.py", "beta.py"):
                btype = "custom"
            elif script == "bot.py":
                btype = "stable"
            elif script == "beta.py":
                btype = "beta"
            elif script in ("beta-v6.py", "beta_v6.py"):
                btype = "v6"
            else:
                continue

            key = (btype, ch, pid)
            if key in seen:
                continue
            seen.add(key)
            buckets[btype].append(
                {
                    "channel": ch,
                    "pid": pid,
                    "version": read_version(btype if btype != "custom" else "custom", ch),
                    "bot_type": btype,
                    "custom": custom,
                    "uptime_seconds": uptime_seconds,
                }
            )
        except (psutil.AccessDenied, psutil.NoSuchProcess):
            continue

    totals = {k: len(v) for k, v in buckets.items()}
    return {
        "bots": buckets,
        "totals": totals,
        "total": sum(totals.values()),
    }


def list_running_bots(*, update_snapshot: bool = True) -> dict[str, Any]:
    """
    Scan local processes; returns JSON-serializable inventory plus durable
    snapshot metadata for crash recovery on the admin dashboard.

    Snapshot merge/view failures never hide the live inventory — admin status
    must still load if the snapshot file is unwritable or corrupt.
    """
    live = _scan_running_bots()
    if update_snapshot:
        try:
            merge_live_into_snapshot(live)
        except Exception as e:
            log.error("snapshot merge failed (live inventory still returned): %s", e)
    try:
        snap = snapshot_view(live)
    except Exception as e:
        log.error("snapshot view failed: %s", e)
        snap = {
            "updated_at": None,
            "bots": [],
            "missing": [],
            "expected_total": 0,
            "missing_total": 0,
            "live_total": int(live.get("total") or 0),
            "path": str(SNAPSHOT_PATH),
            "error": str(e),
        }
    return {
        **live,
        "snapshot": snap,
        "scanned_at": _utc_now_iso(),
    }


def refresh_running_snapshot() -> dict[str, Any]:
    """Periodic / explicit snapshot refresh (scan + merge + view)."""
    return list_running_bots(update_snapshot=True)


def read_online_marker(channel: str) -> str | None:
    # Stream online marker: logs/online/{channel}.txt contents "True" or "False".
    channel = (channel or "").lower().strip()
    if not channel or not re.match(r"^[a-z0-9_]{1,64}$", channel):
        return None
    path = LOGS / "online" / f"{channel}.txt"
    try:
        text = path.read_text(encoding="utf-8").strip()
    except OSError:
        return None
    if text in ("True", "False"):
        return text
    return None


# Allowlisted maintenance scripts (admin ops via bots API; no arbitrary shell)
OPS_SCRIPTS: dict[str, list[str]] = {
    "refresh_spotify": ["python3", str(BOT_HOME / "refresh_spotify_tokens.py")],
    "refresh_streamelements": ["python3", str(BOT_HOME / "refresh_streamelements_tokens.py")],
    "refresh_discord": ["python3", str(BOT_HOME / "refresh_discord_tokens.py")],
    "refresh_custom_bot": ["python3", str(BOT_HOME / "refresh_custom_bot_tokens.py")],
}


async def run_allowlisted_script(script_key: str, *, timeout: float = 120.0) -> dict[str, Any]:
    # Run a named ops script under BOT_HOME; rejects unknown keys.
    key = (script_key or "").strip().lower()
    cmd = OPS_SCRIPTS.get(key)
    if not cmd:
        return {
            "success": False,
            "message": f"Unknown script {script_key!r}. Allowed: {', '.join(sorted(OPS_SCRIPTS))}",
        }
    script_path = Path(cmd[-1])
    if not script_path.is_file():
        return {"success": False, "message": f"Script file missing: {script_path}"}
    try:
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            cwd=str(BOT_HOME),
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        try:
            out_b, err_b = await asyncio.wait_for(proc.communicate(), timeout=timeout)
        except asyncio.TimeoutError:
            proc.kill()
            await proc.communicate()
            return {"success": False, "message": f"Script timed out after {timeout:.0f}s", "script": key}
        out = out_b.decode(errors="replace")
        err = err_b.decode(errors="replace")
        code = proc.returncode or 0
        combined = (out + ("\n" + err if err else "")).strip()
        return {
            "success": code == 0,
            "script": key,
            "exit_code": code,
            "output": combined[-8000:] if combined else "",
            "message": "OK" if code == 0 else f"exit {code}",
        }
    except Exception as e:
        log.exception("ops script %s failed", key)
        return {"success": False, "script": key, "message": str(e)}


def status_for_channel(channel: str, bot_type: str | None = None) -> dict[str, Any]:
    # Process status + update-notice mtimes (local files only). Unfiltered scan: custom before beta.
    channel = channel.lower()
    if bot_type:
        # beta control also finds -custom; still report true type as "custom" when that is running.
        if bot_type == "beta":
            types = ["custom", "beta"]
        else:
            types = [bot_type]
    else:
        types = ["stable", "custom", "beta", "v6"]
    found_type = None
    found_pid = None
    for t in types:
        if t is None:
            continue
        pids = find_pids(t, channel)
        if pids:
            found_type = t
            found_pid = pids[0]
            break

    # Meta is for the running type if any, else the requested type, else stable.
    meta_type = found_type or bot_type or "stable"
    if meta_type not in VERSION_FILE_BY_TYPE and meta_type not in SCRIPT_TYPE_FOR_MTIME:
        meta_type = "stable"

    script_mtime = script_mtime_for_type(meta_type)
    last_run_mtime = version_file_mtime(meta_type, channel)
    version = read_version(meta_type, channel)
    code_update = (
        script_mtime is not None
        and last_run_mtime is not None
        and script_mtime > last_run_mtime
    )

    return {
        "running": bool(found_type),
        "pid": found_pid,
        "bot_type": found_type,
        "version": version,
        "channel": channel,
        "requested_bot_type": bot_type,
        "meta_bot_type": meta_type,
        "script_mtime": script_mtime,
        "last_run_mtime": last_run_mtime,
        "code_update_available": code_update,
    }


async def _run(cmd: str | list[str], *, shell: bool = False) -> tuple[int, str, str]:
    if shell:
        proc = await asyncio.create_subprocess_shell(
            cmd if isinstance(cmd, str) else " ".join(cmd),
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
    else:
        args = cmd if isinstance(cmd, list) else cmd.split()
        proc = await asyncio.create_subprocess_exec(
            *args,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
    out_b, err_b = await proc.communicate()
    return proc.returncode or 0, out_b.decode(errors="replace"), err_b.decode(errors="replace")


def _kill_pids(pids: list[int]) -> tuple[list[int], list[int]]:
    """Returns (killed_pids, permission_denied_pids)."""
    killed: list[int] = []
    denied: list[int] = []
    for pid in pids:
        try:
            os.kill(pid, signal.SIGKILL)
            killed.append(pid)
        except ProcessLookupError:
            continue
        except PermissionError:
            denied.append(pid)
    return killed, denied


async def stop_bot(channel: str, bot_type: str) -> dict[str, Any]:
    channel = channel.lower()
    if bot_type not in BOT_TYPES and bot_type != "custom":
        return {"success": False, "state": "error", "message": f"Invalid bot_type: {bot_type}"}

    # For beta, also catch custom-flag processes on beta.py
    pids = find_pids(bot_type, channel)
    if bot_type == "beta":
        pids = list(dict.fromkeys(pids + find_pids("custom", channel)))

    session = screen_session_name(channel)
    if not pids:
        await _run(f"screen -S {session} -X quit 2>/dev/null; true", shell=True)
        await _run(f"tmux kill-session -t {session} 2>/dev/null; true", shell=True)
        # Clear any stale expected snapshot (e.g. crash left a recovery row and
        # admin is acknowledging / cleaning up via Stop).
        snapshot_mark_stopped(channel, bot_type)
        return {
            "success": True,
            "state": "already_stopped",
            "running": False,
            "pid": None,
            "bot_type": bot_type,
            "version": None,
            "message": "Bot is not running. No action taken.",
            "channel": channel,
        }

    killed, denied = _kill_pids(pids)
    await _run(f"screen -S {session} -X quit 2>/dev/null; true", shell=True)
    await _run(f"tmux kill-session -t {session} 2>/dev/null; true", shell=True)

    def _still_running() -> list[int]:
        remaining = find_pids(bot_type, channel)
        if bot_type == "beta":
            remaining = list(dict.fromkeys(remaining + find_pids("custom", channel)))
        return remaining

    # A denied kill can't be retried into working, so skip the poll and fail fast.
    still_alive = _still_running()
    if not denied:
        for _ in range(3):
            if not still_alive:
                break
            await asyncio.sleep(0.3)
            still_alive = _still_running()

    if still_alive:
        message = f"Failed to stop bot: PID(s) {', '.join(str(p) for p in still_alive)} still running"
        if denied:
            message += f" (permission denied killing {', '.join(str(p) for p in denied)} - likely started outside the bots-api control plane, e.g. directly as root)"
        return {
            "success": False,
            "state": "error",
            "running": True,
            "pid": still_alive[0],
            "bot_type": bot_type,
            "version": None,
            "message": message,
            "channel": channel,
            "killed_pids": killed,
        }

    # Intentional stop — drop from crash-recovery snapshot so we do not
    # prompt admins to restart bots they deliberately shut down.
    snapshot_mark_stopped(channel, bot_type)

    return {
        "success": True,
        "state": "stopped",
        "running": False,
        "pid": None,
        "bot_type": bot_type,
        "version": None,
        "message": f"Bot stopped (killed PIDs: {', '.join(str(p) for p in killed)})",
        "channel": channel,
        "killed_pids": killed,
    }


async def start_bot(
    *,
    channel: str,
    bot_type: str,
    channel_id: str,
    token: str,
    refresh: str,
    apitoken: str,
    custom: bool = False,
    botusername: str | None = None,
    self_mode: bool = False,
    version: str | None = None,
) -> dict[str, Any]:
    channel = channel.lower()
    if bot_type not in BOT_TYPES:
        return {"success": False, "state": "error", "message": f"Invalid bot_type: {bot_type}"}
    if custom and self_mode:
        return {"success": False, "state": "error", "message": "custom and self are mutually exclusive"}
    if bot_type != "beta" and (custom or self_mode):
        return {"success": False, "state": "error", "message": "custom/self only valid for bot_type=beta"}

    missing = [n for n, v in (
        ("channel", channel),
        ("channel_id", channel_id),
        ("token", token),
        ("refresh", refresh),
        ("apitoken", apitoken),
    ) if not v]
    if missing:
        return {"success": False, "state": "error", "message": f"Missing fields: {', '.join(missing)}"}

    python_bin = PYTHON_BY_TYPE.get(bot_type)
    script = BOT_HOME / SCRIPT_BY_TYPE[bot_type]
    if not python_bin or not python_bin.is_file():
        return {
            "success": False,
            "state": "error",
            "message": f"Python venv missing for {bot_type}: {python_bin}",
        }
    if not script.is_file():
        return {"success": False, "state": "error", "message": f"Bot script missing: {script}"}

    other_msg = ""
    for other in BOT_TYPES:
        if other == bot_type:
            continue
        other_pids = find_pids(other, channel)
        if other == "beta":
            other_pids = list(dict.fromkeys(other_pids + find_pids("custom", channel)))
        if other_pids:
            killed, _denied = _kill_pids(other_pids)
            other_msg += f"Stopped {other} bot (PIDs: {', '.join(str(p) for p in killed)}). "
            await asyncio.sleep(0.4)

    existing = find_pids(bot_type, channel)
    if bot_type == "beta":
        existing = list(dict.fromkeys(existing + find_pids("custom", channel)))
    if existing:
        if version:
            write_version(bot_type, channel, version)
        ver = read_version(bot_type, channel)
        snapshot_mark_started(
            channel,
            "custom" if custom else bot_type,
            pid=existing[0],
            version=ver,
            custom=custom,
        )
        return {
            "success": True,
            "state": "already_running",
            "running": True,
            "pid": existing[0],
            "bot_type": bot_type,
            "version": ver,
            "message": f"{other_msg}Bot is already running (PID {existing[0]}). No action taken.",
            "channel": channel,
        }

    crash_log = CRASH_DIR / f"{channel}.log"
    crash_log.parent.mkdir(parents=True, exist_ok=True)

    # Build argv without shell-quoting for the python process itself;
    # outer shell only wraps tee + screen.
    argv = [
        str(python_bin),
        "-u",
        str(script),
        "-channel",
        channel,
        "-channelid",
        str(channel_id),
        "-token",
        token,
        "-refresh",
        refresh,
        "-apitoken",
        apitoken,
    ]
    if custom and botusername:
        argv.extend(["-custom", "-botusername", botusername])
    if self_mode:
        argv.append("-self")

    # Escape for bash -c so tokens with special chars stay intact
    def sh_quote(s: str) -> str:
        return "'" + s.replace("'", "'\"'\"'") + "'"

    inner = " ".join(sh_quote(a) for a in argv)
    tee_cmd = f"{inner} 2>&1 | tee -a {sh_quote(str(crash_log))}"
    session = screen_session_name(channel)
    start_cmd = f"screen -dmS {sh_quote(session)} bash -c {sh_quote(tee_cmd)}"

    code, out, err = await _run(start_cmd, shell=True)
    if code != 0:
        return {
            "success": False,
            "state": "error",
            "message": f"Failed to start screen session (exit {code}): {err or out}",
            "channel": channel,
            "bot_type": bot_type,
        }

    await asyncio.sleep(0.5)
    if version:
        write_version(bot_type, channel, version)

    new_pids = find_pids(bot_type, channel)
    if bot_type == "beta" and not new_pids:
        new_pids = find_pids("custom", channel)

    effective_type = "custom" if custom else bot_type
    if new_pids:
        ver = read_version(bot_type, channel)
        snapshot_mark_started(
            channel,
            effective_type,
            pid=new_pids[0],
            version=ver,
            custom=custom,
        )
        return {
            "success": True,
            "state": "started",
            "running": True,
            "pid": new_pids[0],
            "bot_type": bot_type,
            "version": ver,
            "message": f"{other_msg}Bot started successfully",
            "channel": channel,
        }
    # Start was requested; record expected even if PID not visible yet so a
    # quick crash still leaves a recovery row after the next scan cycle.
    snapshot_mark_started(
        channel,
        effective_type,
        pid=None,
        version=version,
        custom=custom,
    )
    return {
        "success": True,
        "state": "start_pending",
        "running": False,
        "pid": None,
        "bot_type": bot_type,
        "version": version,
        "message": f"{other_msg}Bot start command sent. Status will update shortly.",
        "channel": channel,
    }


async def restart_bot(**kwargs: Any) -> dict[str, Any]:
    channel = kwargs.get("channel", "")
    bot_type = kwargs.get("bot_type", "stable")
    stop_res = await stop_bot(channel, bot_type)
    # Only start once the stop is confirmed - otherwise it just no-ops against the old process.
    if not stop_res.get("success"):
        stop_res["restarted_from"] = stop_res.get("state")
        return stop_res
    await asyncio.sleep(0.5)
    start_res = await start_bot(**kwargs)
    start_res["restarted_from"] = stop_res.get("state")
    if start_res.get("state") == "started":
        start_res["state"] = "restarted"
    return start_res
