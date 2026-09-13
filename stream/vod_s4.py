#!/usr/bin/env python3
"""Mega S4 helpers for extended VOD MP4s (prefix vods/{username}/)."""
import os
import re
from datetime import datetime, timedelta
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent / ".env")

import boto3
from boto3.s3.transfer import TransferConfig
from botocore.config import Config as BotoConfig

VODS_PREFIX = "vods"
SAFE_USER = re.compile(r"^[a-zA-Z0-9_]{1,64}$")
SAFE_FILE = re.compile(r"^[^/\\]+\\.mp4$", re.IGNORECASE)
TRANSFER = TransferConfig(
    multipart_threshold=64 * 1024 * 1024,
    multipart_chunksize=64 * 1024 * 1024,
    max_concurrency=4,
)


def vods_key(username: str, filename: str) -> str | None:
    if not SAFE_USER.match(username or ""):
        return None
    base = os.path.basename(filename or "")
    if not SAFE_FILE.match(base) or ".." in base:
        return None
    return f"{VODS_PREFIX}/{username}/{base}"


def s3_client():
    endpoint = (os.getenv("MEGAS4_ENDPOINT") or "").strip()
    region = (os.getenv("MEGAS4_REGION") or "g").strip() or "g"
    access = (os.getenv("MEGAS4_ACCESS_KEY") or "").strip()
    secret = (os.getenv("MEGAS4_SECRET_KEY") or "").strip()
    if not endpoint or not access or not secret:
        raise RuntimeError("MegaS4 credentials are not configured")
    return boto3.client(
        "s3",
        endpoint_url=endpoint,
        region_name=region,
        aws_access_key_id=access,
        aws_secret_access_key=secret,
        config=BotoConfig(s3={"addressing_style": "path"}),
    )


def bucket_name() -> str:
    return (os.getenv("MEGAS4_BUCKET") or "botofthespecter").strip()


def upload_vod(local_path: str, username: str, filename: str) -> str:
    key = vods_key(username, filename)
    if not key:
        raise ValueError("invalid vod key")
    if not os.path.isfile(local_path):
        raise FileNotFoundError(local_path)
    client = s3_client()
    extra = {"ContentType": "video/mp4"}
    client.upload_file(local_path, bucket_name(), key, ExtraArgs=extra, Config=TRANSFER)
    return key


def delete_vod(key: str) -> None:
    if not key or not key.startswith(VODS_PREFIX + "/"):
        raise ValueError("invalid vod key")
    s3_client().delete_object(Bucket=bucket_name(), Key=key)


def extend_until(now: datetime | None = None) -> datetime:
    return (now or datetime.utcnow()) + timedelta(days=3)
