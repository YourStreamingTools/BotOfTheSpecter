# Filenames with a YouTube or S3 transfer still queued or running. Retention must leave them on disk.
import os

import aiomysql


def _sql_kwargs():
    return {
        "host": os.getenv("SQL_HOST"),
        "user": os.getenv("SQL_USER"),
        "password": os.getenv("SQL_PASSWORD"),
        "db": "website",
    }


async def connect_website():
    cfg = _sql_kwargs()
    if not cfg["host"] or not cfg["user"] or not cfg["password"]:
        raise RuntimeError("missing SQL_HOST / SQL_USER / SQL_PASSWORD")
    return await aiomysql.connect(**cfg)


_HOLD_QUERIES = (
    """
    SELECT u.username, j.filename
    FROM youtube_vod_uploads j
    JOIN users u ON u.id = j.user_id
    WHERE j.status IN ('queued', 'pulling', 'uploading')
    """,
    """
    SELECT u.username, j.filename
    FROM user_s3_uploads j
    JOIN users u ON u.id = j.user_id
    WHERE j.status IN ('queued', 'pulling', 'uploading')
    """,
)


async def filenames_held_for_upload(conn) -> set[tuple[str, str]]:
    held = set()
    saw_table = False
    for sql in _HOLD_QUERIES:
        try:
            async with conn.cursor() as cur:
                await cur.execute(sql)
                rows = await cur.fetchall()
        except Exception:
            continue
        saw_table = True
        for username, filename in rows or []:
            username = str(username or "")
            filename = str(filename or "")
            if username and filename:
                held.add((username, filename))
    if not saw_table:
        raise RuntimeError("could not read YouTube or S3 upload tables")
    return held


def names_kept_with(filename: str) -> set[str]:
    # The MP4 plus the sidecar and any in-progress part next to it.
    filename = str(filename or "")
    if not filename:
        return set()
    base = filename[:-4] if filename.lower().endswith(".mp4") else filename
    return {
        filename,
        base + ".json",
        base + ".ffmpeg.log",
        base + ".ytdlp.log",
        base + ".fwd.log",
        filename + ".part",
    }
