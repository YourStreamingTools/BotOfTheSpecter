#!/usr/bin/env python3
"""
Internal storage HTTP for the storage server (syd1). Bind to the VPC address only;
Caddy on web1 proxies the public hosts here and falls back to Mega S4.

  /vods/{user}/{file}[/{download_name}]  recordings + stored Twitch VODs
  /media/{store}/{path}                  cached read-only Mega S4 mounts
                                         (specter-media-mount@{store})
  /list/{store}/{user}                   JSON file names in a channel's folder,
                                         top level and one folder down (for the API;
                                         the public hosts never reach it)

The old un-prefixed VOD paths (/{user}/{file}) still work.
"""
import os
import re
import asyncio
import mimetypes
from aiohttp import web
from ffmpeg_jobs import content_disposition_attachment, download_mp4_name

ROOT = os.getenv("STREAM_FILES_ROOT") or os.getenv("STREAM_ROOT_PATH") or "/var/lib/specter-stream"
BIND_HOST = os.getenv("STORAGE_HTTP_HOST") or os.getenv("VOD_INTERNAL_HOST") or "10.240.0.9"
BIND_PORT = int(os.getenv("STORAGE_HTTP_PORT") or os.getenv("VOD_INTERNAL_PORT") or "8092")
MEDIA_ROOT = os.getenv("STORAGE_MEDIA_ROOT") or "/mnt/specter-media"
MEDIA_STORES = {"cdn", "media", "soundalerts", "videoalerts", "walkons", "usermusic"}
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


def _media_path(store: str, rel: str) -> str | None:
    base = os.path.join(MEDIA_ROOT, store)
    parts = rel.split("/")
    if "\x00" in rel or any(p in ("", ".", "..") or p.startswith(".") for p in parts):
        return None
    path = os.path.realpath(os.path.join(base, *parts))
    if not path.startswith(os.path.realpath(base) + os.sep) or not os.path.isfile(path):
        return None
    return path


async def handle_media(request: web.Request) -> web.StreamResponse:
    store = request.match_info["store"]
    if store not in MEDIA_STORES:
        return web.Response(status=404, text="not found")
    # Mount down: 503 so Caddy on web1 serves straight from Mega S4 instead.
    if not await asyncio.to_thread(os.path.ismount, os.path.join(MEDIA_ROOT, store)):
        return web.Response(status=503, text="store unavailable")
    # First lookup in a directory lists it from S4; keep that off the event loop.
    path = await asyncio.to_thread(_media_path, store, request.match_info["path"])
    if not path:
        return web.Response(status=404, text="not found")
    ctype = mimetypes.guess_type(path)[0] or "application/octet-stream"
    return web.FileResponse(path, headers={"Content-Type": ctype})


def _list_files(store: str, username: str) -> list[str] | None:
    # Same layout as the dashboard's Media page: {user}/file and {user}/folder/file
    base = os.path.join(MEDIA_ROOT, store, username)
    if not os.path.isdir(base):
        return []
    names: list[str] = []
    try:
        with os.scandir(base) as top:
            for entry in top:
                if entry.name.startswith("."):
                    continue
                if entry.is_file():
                    names.append(entry.name)
                elif entry.is_dir():
                    with os.scandir(entry.path) as sub:
                        names.extend(f"{entry.name}/{s.name}" for s in sub if not s.name.startswith(".") and s.is_file())
    except OSError:
        return None
    names.sort(key=str.lower)
    return names


async def handle_list(request: web.Request) -> web.Response:
    store = request.match_info["store"]
    username = request.match_info["username"]
    if store not in MEDIA_STORES or not SAFE_USER.match(username):
        return web.json_response({"error": "not found"}, status=404)
    if not await asyncio.to_thread(os.path.ismount, os.path.join(MEDIA_ROOT, store)):
        return web.json_response({"error": "store unavailable"}, status=503)
    files = await asyncio.to_thread(_list_files, store, username)
    if files is None:
        return web.json_response({"error": "list failed"}, status=503)
    return web.json_response({"store": store, "user": username, "files": files})


def main() -> None:
    os.makedirs(ROOT, exist_ok=True)
    app = web.Application()
    app.router.add_get("/media/{store}/{path:.+}", handle_media)
    app.router.add_get("/list/{store}/{username}", handle_list)
    app.router.add_get("/vods/{username}/{filename}/{download_name}", handle_vod)
    app.router.add_get("/vods/{username}/{filename}", handle_vod)
    app.router.add_get("/{username}/{filename}/{download_name}", handle_vod)
    app.router.add_get("/{username}/{filename}", handle_vod)
    web.run_app(app, host=BIND_HOST, port=BIND_PORT, print=None)


if __name__ == "__main__":
    main()
