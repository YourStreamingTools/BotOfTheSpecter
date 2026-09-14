#!/usr/bin/env python3
"""Internal VOD file server. Bind to the VPC address only; Caddy on web1 proxies it."""
import os
import re
from aiohttp import web
from ffmpeg_jobs import content_disposition_attachment, download_mp4_name

ROOT = os.getenv("STREAM_FILES_ROOT") or os.getenv("STREAM_ROOT_PATH") or "/var/lib/specter-stream"
BIND_HOST = os.getenv("VOD_INTERNAL_HOST", "10.240.0.9")
BIND_PORT = int(os.getenv("VOD_INTERNAL_PORT") or "8092")
SAFE_USER = re.compile(r"^[a-zA-Z0-9_]{1,64}$")
SAFE_FILE = re.compile(r"^[^/\\]+\.mp4$", re.IGNORECASE)


def _safe_path(username: str, filename: str) -> str | None:
    if not SAFE_USER.match(username) or not SAFE_FILE.match(filename):
        return None
    if filename != os.path.basename(filename) or ".." in filename:
        return None
    path = os.path.realpath(os.path.join(ROOT, username, filename))
    root = os.path.realpath(os.path.join(ROOT, username))
    if path != root and not path.startswith(root + os.sep):
        return None
    if not path.lower().endswith(".mp4") or not os.path.isfile(path):
        return None
    return path


def _download_name_from_request(request: web.Request, path: str) -> str:
    pretty = (request.match_info.get("download_name") or "").strip()
    if pretty and SAFE_FILE.match(pretty) and pretty == os.path.basename(pretty) and ".." not in pretty:
        from ffmpeg_jobs import sanitize_download_basename

        base = sanitize_download_basename(os.path.splitext(pretty)[0] if pretty.lower().endswith(".mp4") else pretty)
        return base if base.lower().endswith(".mp4") else base + ".mp4"
    fallback = os.path.splitext(os.path.basename(path))[0]
    return download_mp4_name(path, fallback)


async def handle_vod(request: web.Request) -> web.StreamResponse:
    username = request.match_info["username"]
    filename = request.match_info["filename"]
    path = _safe_path(username, filename)
    if not path:
        return web.Response(status=404, text="not found")
    download_name = _download_name_from_request(request, path)
    return web.FileResponse(
        path,
        headers={
            "Content-Type": "video/mp4",
            "Content-Disposition": content_disposition_attachment(download_name),
        },
    )


def main() -> None:
    os.makedirs(ROOT, exist_ok=True)
    app = web.Application()
    app.router.add_get("/{username}/{filename}/{download_name}", handle_vod)
    app.router.add_get("/{username}/{filename}", handle_vod)
    web.run_app(app, host=BIND_HOST, port=BIND_PORT, print=None)


if __name__ == "__main__":
    main()
