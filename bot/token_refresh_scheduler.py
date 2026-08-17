#!/usr/bin/env python3
"""
Single entry point for all OAuth token refresh jobs, invoked every minute by
cron. Each job runs on its own interval, tracked in a small local state file
(intervals are a safety margin before the provider's real token expiry, not
the expiry itself):

  Twitch app token    every 15 minutes  (shared Helix chat token; remint if invalid or <24h)
  custom bot tokens   every 45 minutes  (Twitch access tokens last ~4h;
                                         script only refreshes rows within
                                         1h of expiry — job must run inside
                                         that window, not every 4h)
  Spotify             every 45 minutes  (Spotify access tokens last 1h;
                                         15 min buffer so songrequest /
                                         overlays never see a dead token)
  Discord             every 6 days      (Discord access tokens last 7d)
  StreamElements      every 29 days     (StreamElements access tokens last 30d)

This replaces separate cron lines with one, and replaces cron's
day-of-month field (which drifts at month boundaries and can't express
"every 6 days" or "every 29 days" cleanly) with a state file that tracks
each job's actual last-run time.

Failed jobs do not advance last_run, so the next minute's cron retries them
instead of waiting a full interval with dead tokens.

The refresh_*.py scripts are untouched and stay independently runnable
(the admin dashboard's manual "Refresh Tokens" buttons invoke them directly).
This script just imports each one's main() and calls it when due.
"""
import os
import sys
import json
import time
import fcntl
import asyncio
import logging
import traceback
from pathlib import Path
from datetime import datetime, timezone

SCRIPT_DIR = Path(__file__).resolve().parent
STATE_FILE = SCRIPT_DIR / "logs" / "token_refresh_state.json"
LOCK_FILE = SCRIPT_DIR / "logs" / "token_refresh_scheduler.lock"

JOBS = [
    # Shared Helix chat token: live-validate every 15 min; remint if invalid or within 24h.
    ("twitch_app_token", 15 * 60, "refresh_twitch_app_token"),
    # Custom bots store token_expires and only refresh when within 1h of
    # expiry. The job itself must therefore run *inside* that window (not
    # every 4h at full lifetime). 45 min cadence leaves ~15+ min remaining.
    ("custom_bot_tokens", 45 * 60, "refresh_custom_bot_tokens"),
    # Spotify access tokens last 3600s. Running at exactly 1h leaves zero
    # margin for cron lag and leaves mid-cycle OAuth links dead until the
    # next tick. 45 min keeps ~15 min of lifetime after every refresh.
    ("spotify", 45 * 60, "refresh_spotify_tokens"),
    ("discord", 6 * 86400, "refresh_discord_tokens"),
    ("streamelements", 29 * 86400, "refresh_streamelements_tokens"),
]

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    stream=sys.stdout,
)
logger = logging.getLogger("token_refresh_scheduler")


class SchedulerState:
    """Crash-safe last-run tracking for each job (temp file + os.replace)."""

    def __init__(self, path: Path):
        self.path = path
        self._data = {}
        self.load()

    def load(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        if self.path.is_file():
            try:
                with self.path.open("r", encoding="utf-8") as fh:
                    self._data = json.load(fh)
            except (json.JSONDecodeError, OSError) as e:
                logger.error(f"State file unreadable ({e}), starting fresh")
                self._data = {}
        self._data.setdefault("jobs", {})

    def save(self):
        tmp = self.path.with_suffix(self.path.suffix + ".tmp")
        with tmp.open("w", encoding="utf-8") as fh:
            json.dump(self._data, fh, indent=2)
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(tmp, self.path)

    def last_run(self, job_name):
        iso = self._data["jobs"].get(job_name, {}).get("last_run")
        if not iso:
            return None
        try:
            return datetime.fromisoformat(iso)
        except ValueError:
            return None

    def record_run(self, job_name, success):
        """Record outcome. Only advance last_run on success so a failed job
        is retried on the next minute's cron instead of waiting a full interval
        with expired access tokens."""
        self._data["jobs"].setdefault(job_name, {})
        job = self._data["jobs"][job_name]
        job["last_success"] = success
        job["last_attempt"] = datetime.now(timezone.utc).isoformat()
        if success:
            job["last_run"] = job["last_attempt"]
        self.save()


def acquire_lock():
    """Non-blocking advisory lock so an overrunning job doesn't overlap with
    the next minute's cron invocation."""
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    lock_fh = open(LOCK_FILE, "w")
    try:
        fcntl.flock(lock_fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return None
    return lock_fh


async def run_job(module_name):
    module = __import__(module_name)
    await module.main()


async def main():
    lock_fh = acquire_lock()
    if lock_fh is None:
        logger.info("Previous run still in progress, skipping this minute")
        return
    try:
        state = SchedulerState(STATE_FILE)
        now = datetime.now(timezone.utc)
        for job_name, interval_seconds, module_name in JOBS:
            last_run = state.last_run(job_name)
            if last_run is not None:
                elapsed = (now - last_run).total_seconds()
                if elapsed < interval_seconds:
                    continue
            logger.info(f"Running {job_name} (module {module_name})...")
            success = False
            try:
                await run_job(module_name)
                success = True
            except Exception as e:
                logger.error(f"{job_name} failed: {e}")
                logger.error(traceback.format_exc())
            state.record_run(job_name, success)
            logger.info(f"{job_name} {'completed' if success else 'failed'}")
    finally:
        try:
            fcntl.flock(lock_fh, fcntl.LOCK_UN)
        except OSError:
            pass
        lock_fh.close()


if __name__ == "__main__":
    asyncio.run(main())
