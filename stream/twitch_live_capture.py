"""Record one live Twitch stream to MP4, leaving Twitch's stitched ads out.

The recorder (twitch-recorder.py) starts one of these per recording. It reads the
live media playlist itself, drops segments Twitch marks as ads (the pre-roll and
the "commercial break" image), and pipes the real live segments into ffmpeg,
which copies them into a fragmented MP4. Nothing is re-encoded.

Why not hand the playlist URL to ffmpeg: ffmpeg records whatever Twitch serves,
ads included, and if the recording starts on an ad with no audio track ffmpeg
never adds the stream's audio later, so the whole file is silent.

SIGTERM or SIGINT ends the capture cleanly: ffmpeg gets end of input and closes
the file. Exit code is ffmpeg's.

Usage:
  twitch_live_capture.py --login NAME --output FILE.mp4 [--playlist URL] [--ads cut|gap|keep]

  --ads cut   drop ad segments and join the stream either side (default)
  --ads gap   drop ad segments but keep the stream's timeline, so the ad time stays as a held frame
  --ads keep  write ad segments too (old behaviour), after the first live segment
"""
from __future__ import annotations

import argparse
import asyncio
import datetime
import signal
import sys
import time
from typing import List, Optional

import aiohttp

from twitch_live_hls import parse_media_playlist, twitch_live_hls

USER_AGENT = "Mozilla/5.0"
SEGMENT_RETRIES = 3
OFFLINE_GRACE_SECONDS = 90
FFMPEG_EXIT_WAIT_SECONDS = 20


def log(message: str) -> None:
    stamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[capture {stamp}] {message}", file=sys.stderr, flush=True)


def ffmpeg_command(output: str, ads: str) -> List[str]:
    cmd = [
        "ffmpeg",
        "-hide_banner",
        "-loglevel", "warning",
        "-stats",
        "-f", "mpegts",
        "-i", "pipe:0",
    ]
    if ads == "cut":
        # A dropped ad leaves a jump in the stream clock; join anything over 1s
        cmd += ["-dts_delta_threshold", "1"]
    elif ads == "gap":
        # Never rejoin the clock, so the file keeps the stream's real timeline
        cmd += ["-dts_delta_threshold", "86400"]
    cmd += [
        "-c", "copy",
        "-bsf:a", "aac_adtstoasc",
        # delay_moov: write the header after the first packets, so the AAC
        # config from aac_adtstoasc is in it. Without it the audio track has no
        # AudioSpecificConfig and plays silent.
        "-movflags", "frag_keyframe+empty_moov+delay_moov+default_base_moof",
        "-f", "mp4",
        output,
    ]
    return cmd


class Capture:
    def __init__(self, login: str, output: str, playlist_url: Optional[str], ads: str):
        self.login = login
        self.output = output
        self.playlist_url = playlist_url
        self.ads = ads
        self.stop = asyncio.Event()
        self.ffmpeg: Optional[asyncio.subprocess.Process] = None
        self.last_sequence: Optional[int] = None
        self.wrote_live = False
        self.in_ad = False
        self.ad_segments = 0
        self.ad_seconds = 0.0
        self.unflagged_titles = set()
        self.offline_since: Optional[float] = None

    async def refresh_playlist_url(self, session: aiohttp.ClientSession) -> bool:
        try:
            is_live, url, err, _meta = await twitch_live_hls(session, self.login)
        except Exception as e:
            log(f"Could not look up live playlist: {e}")
            return False
        if url:
            self.playlist_url = url
            self.offline_since = None
            log("Got a fresh live playlist")
            return True
        if is_live is False:
            if self.offline_since is None:
                self.offline_since = time.monotonic()
            log("Channel reports offline")
        else:
            log(f"Live playlist lookup failed: {err}")
        return False

    async def fetch_text(self, session: aiohttp.ClientSession, url: str):
        async with session.get(url, headers={"User-Agent": USER_AGENT}) as resp:
            return resp.status, await resp.text(), str(resp.url)

    async def fetch_segment(self, session: aiohttp.ClientSession, url: str) -> Optional[bytes]:
        for attempt in range(1, SEGMENT_RETRIES + 1):
            try:
                async with session.get(url, headers={"User-Agent": USER_AGENT}) as resp:
                    if resp.status == 200:
                        return await resp.read()
                    log(f"Segment HTTP {resp.status} (try {attempt})")
            except Exception as e:
                log(f"Segment fetch failed (try {attempt}): {e}")
            await asyncio.sleep(0.5 * attempt)
        return None

    async def start_ffmpeg(self) -> None:
        self.ffmpeg = await asyncio.create_subprocess_exec(
            *ffmpeg_command(self.output, self.ads),
            stdin=asyncio.subprocess.PIPE,
        )
        log(f"ffmpeg started pid={self.ffmpeg.pid} ads={self.ads}")

    async def write(self, data: bytes) -> bool:
        if self.ffmpeg is None or self.ffmpeg.stdin is None or self.ffmpeg.returncode is not None:
            return False
        try:
            self.ffmpeg.stdin.write(data)
            await self.ffmpeg.stdin.drain()
            return True
        except (BrokenPipeError, ConnectionResetError):
            log("ffmpeg closed its input")
            return False

    def note_ad(self, segment) -> None:
        if not self.in_ad:
            self.in_ad = True
            self.ad_segments = 0
            self.ad_seconds = 0.0
            where = "before the stream" if not self.wrote_live else "mid-stream"
            log(f"Ad break started {where} at segment {segment.sequence} (title {segment.title!r})")
        self.ad_segments += 1
        self.ad_seconds += segment.duration

    def note_live(self) -> None:
        if self.in_ad:
            action = "kept" if self.ads == "keep" and self.wrote_live else "skipped"
            log(f"Ad break ended: {action} {self.ad_segments} segments (~{self.ad_seconds:.0f}s)")
            self.in_ad = False

    async def handle_segment(self, session: aiohttp.ClientSession, segment) -> bool:
        if segment.ad:
            self.note_ad(segment)
            if not (self.ads == "keep" and self.wrote_live):
                return True
        else:
            self.note_live()
            if segment.title != "live" and segment.title not in self.unflagged_titles:
                self.unflagged_titles.add(segment.title)
                log(f"Segment title {segment.title!r} is not 'live' and has no ad marker; recording it")
        data = await self.fetch_segment(session, segment.url)
        if data is None:
            log(f"Gave up on segment {segment.sequence}")
            return True
        if not await self.write(data):
            return False
        if not segment.ad:
            self.wrote_live = True
        return True

    async def run(self) -> int:
        await self.start_ffmpeg()
        timeout = aiohttp.ClientTimeout(total=20)
        async with aiohttp.ClientSession(timeout=timeout) as session:
            if not self.playlist_url:
                await self.refresh_playlist_url(session)
            while not self.stop.is_set():
                if self.ffmpeg.returncode is not None:
                    log(f"ffmpeg exited early with {self.ffmpeg.returncode}")
                    break
                if self.offline_since and time.monotonic() - self.offline_since > OFFLINE_GRACE_SECONDS:
                    log("Channel offline; finishing the recording")
                    break
                if not self.playlist_url:
                    await self.refresh_playlist_url(session)
                    await self.sleep(5)
                    continue
                try:
                    status, text, final_url = await self.fetch_text(session, self.playlist_url)
                except Exception as e:
                    log(f"Playlist fetch failed: {e}")
                    await self.sleep(2)
                    continue
                if status in (403, 404, 410):
                    log(f"Playlist HTTP {status}; asking Twitch again")
                    if not await self.refresh_playlist_url(session):
                        await self.sleep(5)
                    continue
                if status != 200:
                    log(f"Playlist HTTP {status}")
                    await self.sleep(2)
                    continue
                playlist = parse_media_playlist(text, final_url)
                wait = max(1.0, playlist.target_duration / 2)
                segments = playlist.segments
                if segments and self.last_sequence is not None and segments[-1].sequence < self.last_sequence - 30:
                    log("Playlist sequence went backwards; following the new numbering")
                    self.last_sequence = None
                for segment in segments:
                    if self.stop.is_set():
                        break
                    if self.last_sequence is not None:
                        if segment.sequence <= self.last_sequence:
                            continue
                        if segment.sequence > self.last_sequence + 1:
                            log(f"Missed segments {self.last_sequence + 1}..{segment.sequence - 1}")
                    self.last_sequence = segment.sequence
                    if not await self.handle_segment(session, segment):
                        self.stop.set()
                        break
                if playlist.ended:
                    log("Playlist ended")
                    break
                await self.sleep(wait)
        return await self.finish()

    async def sleep(self, seconds: float) -> None:
        try:
            await asyncio.wait_for(self.stop.wait(), timeout=seconds)
        except asyncio.TimeoutError:
            pass

    async def finish(self) -> int:
        self.note_live()
        proc = self.ffmpeg
        if proc is None:
            return 1
        if proc.stdin is not None:
            try:
                proc.stdin.close()
                await proc.stdin.wait_closed()
            except (BrokenPipeError, ConnectionResetError):
                pass
        try:
            await asyncio.wait_for(proc.wait(), timeout=FFMPEG_EXIT_WAIT_SECONDS)
        except asyncio.TimeoutError:
            log("ffmpeg slow to close; sending SIGTERM")
            proc.terminate()
            try:
                await asyncio.wait_for(proc.wait(), timeout=5)
            except asyncio.TimeoutError:
                proc.kill()
                await proc.wait()
        if not self.wrote_live:
            log("No live segments were recorded")
        log(f"ffmpeg finished with {proc.returncode}")
        return proc.returncode or 0


async def main_async(args) -> int:
    capture = Capture(args.login, args.output, args.playlist, args.ads)
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, capture.stop.set)
    return await capture.run()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--login", required=True, help="Twitch login of the channel")
    parser.add_argument("--output", required=True, help="MP4 file to write")
    parser.add_argument("--playlist", help="Live media playlist URL (looked up when missing or expired)")
    parser.add_argument("--ads", choices=("cut", "gap", "keep"), default="cut")
    args = parser.parse_args()
    return asyncio.run(main_async(args))


if __name__ == "__main__":
    sys.exit(main())
