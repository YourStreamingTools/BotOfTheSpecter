#!/usr/bin/env python3
import sys
import argparse
import signal
import os
import tempfile
import zipfile
import smtplib
import shutil
from email.message import EmailMessage
from email.utils import formataddr
import traceback
import datetime
import asyncio
import gc
import aiomysql
from dotenv import load_dotenv
import json
import asyncio.subprocess
import time
from pathlib import Path
import pymysql
import boto3
from botocore.client import Config
import socket
import urllib3.util.connection as urllib_conn

# Patch urllib3 to prefer IPv4 connections to avoid issues on IPv6-disabled systems
_orig_create_connection = urllib_conn.create_connection
def patched_create_connection(address, *args, **kwargs):
    host, port = address
    # Force IPv4 by resolving with AF_INET
    ipv4_addrs = socket.getaddrinfo(host, port, socket.AF_INET, socket.SOCK_STREAM)
    if ipv4_addrs:
        # Use the first IPv4 address
        ipv4_addr = ipv4_addrs[0][4]
        return _orig_create_connection(ipv4_addr, *args, **kwargs)
    # Fallback to original if no IPv4 found
    return _orig_create_connection(address, *args, **kwargs)
urllib_conn.create_connection = patched_create_connection

# Admin notification address for export failures (fixed)
ADMIN_NOTIFICATION_EMAIL = 'admin@botofthespecter.com'

# Tables that are optional and should not mark the whole export as failed if missing
OPTIONAL_TABLES = {'server_management'}

# Load environment variables
load_dotenv()

# Default sender display name for emails
SMTP_FROM_NAME = os.environ.get('SMTP_FROM_NAME') or 'BotOfTheSpecter'

# Maximum attachment size allowed for email (50 MB). Larger files must be delivered via S3 signed link.
MAX_EMAIL_ZIP_SIZE = 50 * 1024 * 1024

# CloudFlare R2 configuration for large file delivery
S3_HOST = os.environ.get('S3_ENDPOINT_HOSTNAME')
S3_KEY = os.environ.get('S3_ACCESS_KEY')
S3_SECRET = os.environ.get('S3_SECRET_KEY')
S3_BUCKET = os.environ.get('S3_BUCKET_NAME') or 'specterexports'
S3_VERIFY = os.environ.get('S3_VERIFY', '1').lower() not in ('0', 'false', 'no')
S3_ALWAYS_UPLOAD = os.environ.get('S3_ALWAYS_UPLOAD', 'False').lower() in ('true', '1', 'yes')

LOG_FILE = '/home/botofthespecter/export_queue/export_user_data.log'

# Global dry-run flag (set by CLI) so top-level exception handler can respect it
DRY_RUN = False

def log(msg):
    ts = datetime.datetime.now(datetime.timezone.utc).isoformat()
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(f"[{ts}] {msg}\n")

def make_r2_client():
    if not boto3:
        raise RuntimeError('boto3 not installed; cannot upload to R2')
    if not all([S3_HOST, S3_KEY, S3_SECRET]):
        raise RuntimeError('R2 configuration missing (S3_ENDPOINT_HOSTNAME, S3_ACCESS_KEY, S3_SECRET_KEY)')
    endpoint = f'https://{S3_HOST}' if not S3_HOST.startswith('http') else S3_HOST
    cfg = Config(
        signature_version='s3v4',
        s3={'addressing_style': 'virtual'},
        connect_timeout=10,
        read_timeout=120,
        retries={'max_attempts': 4, 'mode': 'standard'},
    )
    session = boto3.session.Session()
    client = session.client(
        's3',
        endpoint_url=endpoint,
        aws_access_key_id=S3_KEY,
        aws_secret_access_key=S3_SECRET,
        region_name='auto',  # Required by R2
        config=cfg
    )
    try:
        client._endpoint.http_session.verify = S3_VERIFY
    except Exception:
        pass
    return client

async def upload_to_r2(local_path, key):
    try:
        client = make_r2_client()
        log(f'Uploading {local_path} to R2 bucket {S3_BUCKET} with key {key}')
        with open(local_path, 'rb') as fh:
            client.put_object(Bucket=S3_BUCKET, Key=key, Body=fh)
        log(f'Upload succeeded')
        # Generate presigned URL valid for 7 days (604800 seconds)
        url = client.generate_presigned_url(
            'get_object',
            Params={'Bucket': S3_BUCKET, 'Key': key},
            ExpiresIn=604800
        )
        log(f'Generated presigned URL valid for 7 days')
        return url
    except Exception as e:
        log(f'R2 upload failed: {e}\n' + traceback.format_exc())
        raise

async def create_zip(username, out_path):
    safe_name = username or 'unknown'
    tmpdir = tempfile.mkdtemp(prefix=f'user_export_{safe_name}_')
    try:
        had_error = False
        # Create database subdirectory for JSON files
        db_json_dir = os.path.join(tmpdir, 'database')
        os.makedirs(db_json_dir, exist_ok=True)
        # Attempt to export database as JSON files into database subdirectory
        try:
            await export_db_as_json(username, db_json_dir)
        except Exception as e:
            log(f'Database export failed: {e}\n' + traceback.format_exc())
            had_error = True
        # Also create a full SQL dump of the user's database (mysqldump)
        try:
            await export_db_sql_dump(username, tmpdir)
        except Exception as e:
            log(f'SQL dump failed: {e}\n' + traceback.format_exc())
            had_error = True
        # Fetch the central website.users row for this username and write to JSON
        try:
            website_row = await fetch_website_user_row(username)
            if website_row is not None:
                user_data_path = os.path.join(db_json_dir, 'user_data.json')
                with open(user_data_path, 'w', encoding='utf-8') as f:
                    json.dump({'table': 'users', 'rows': [website_row]}, f, default=str, ensure_ascii=False, indent=2)
        except Exception as e:
            log(f'Failed to fetch website.users row: {e}\n' + traceback.format_exc())
            had_error = True
        # Export central tables that relate to the website user (e.g., streamlabs_tokens, streamelements_tokens)
        try:
            # prefer twitch_user_id column from website.users
            twitch_id = None
            if website_row is not None:
                twitch_id = website_row.get('twitch_user_id') or website_row.get('twitch_id')
            # For both tables, export rows where twitch_user_id matches. If twitch_id missing, create empty files.
            await export_website_table_filtered('streamlabs_tokens', 'twitch_user_id', twitch_id, db_json_dir)
            await export_website_table_filtered('streamelements_tokens', 'twitch_user_id', twitch_id, db_json_dir)
            # Export spotify_tokens filtered by website user id
            try:
                await export_website_table_filtered('spotify_tokens', 'user_id', website_row.get('id') if website_row is not None else None, db_json_dir)
            except Exception as e:
                log(f'Failed to export spotify_tokens for user {username}: {e}\n' + traceback.format_exc())
                had_error = True
            try:
                await export_website_table_filtered('discord_users', 'user_id', (website_row.get('id') if website_row is not None else None), db_json_dir)
            except Exception as e:
                log(f'Failed to export discord_users for user {username}: {e}\n' + traceback.format_exc())
                had_error = True
            try:
                await export_website_table_filtered('custom_bots', 'channel_id', (website_row.get('id') if website_row is not None else None), db_json_dir)
            except Exception as e:
                log(f'Failed to export custom_bots for user {username}: {e}\n' + traceback.format_exc())
                had_error = True
            try:
                # export server_management (discord-related settings) and rename to requested filename
                server_id_key = (website_row.get('id') if website_row is not None else None)
                await export_website_table_filtered('server_management', 'server_id', server_id_key, db_json_dir)
                src = os.path.join(tmpdir, 'server_management.json')
                dst = os.path.join(tmpdir, 'discord_server_managemenet.json')
                if os.path.exists(src):
                    try:
                        os.replace(src, dst)
                    except Exception:
                        # best effort; if rename fails, leave original
                        log(f'Failed to rename server_management.json to discord_server_managemenet.json for {username}')
                        had_error = True
            except Exception as e:
                log(f'Failed to export server_management for user {username}: {e}\n' + traceback.format_exc())
                had_error = True
        except Exception:
            # defensive - any error already logged above
            pass
        # Gather media entries from mounted media drive; we will add them directly to the ZIP
        try:
            media_files, media_empty_dirs = gather_media_entries(username)
        except Exception as e:
            log(f'Failed to gather media files for {username}: {e}\n' + traceback.format_exc())
            media_files, media_empty_dirs = [], []
        # Gather bot logs (rotated files like username.txt, username.txt.1, etc.)
        try:
            log_files, log_empty_dirs = gather_bot_logs(username)
        except Exception as e:
            log(f'Failed to gather bot logs for {username}: {e}\n' + traceback.format_exc())
            log_files, log_empty_dirs = [], []
        # Include files present in tmpdir into the zip, then add media files directly (stored, no compression)
        with zipfile.ZipFile(out_path, 'w', compression=zipfile.ZIP_DEFLATED) as zf:
            # Add all generated export files from tmpdir (JSON, SQL, etc.) using default compression
            for root, dirs, files in os.walk(tmpdir):
                for fname in files:
                    full = os.path.join(root, fname)
                    arc = os.path.relpath(full, tmpdir).replace('\\', '/')
                    zf.write(full, arcname=arc)
            # Add media files directly from /mnt/media and store them without compression
            for src, arc in media_files:
                try:
                    # ensure arc uses forward slashes
                    arcname = arc.replace('\\', '/')
                    zf.write(src, arcname=arcname, compress_type=zipfile.ZIP_STORED)
                except Exception as e:
                    log(f'Failed to add media file to zip {src}: {e}\n' + traceback.format_exc())
            # Add bot log files (these live under /home/botofthespecter/logs/logs/<subfolder>)
            for src, arc in log_files:
                try:
                    arcname = arc.replace('\\', '/')
                    # logs can be compressed normally
                    zf.write(src, arcname=arcname)
                except Exception as e:
                    log(f'Failed to add log file to zip {src}: {e}\n' + traceback.format_exc())
            # Add empty directory entries for media dirs that had no files
            for arcdir in media_empty_dirs:
                if not arcdir.endswith('/'):
                    arcdir = arcdir + '/'
                try:
                    zinfo = zipfile.ZipInfo(arcdir)
                    # mark as directory
                    zinfo.external_attr = 0o40775 << 16
                    zf.writestr(zinfo, b'')
                except Exception as e:
                    log(f'Failed to add empty media dir to zip {arcdir}: {e}\n' + traceback.format_exc())
            # Add empty directory entries for log dirs that had no logs
            for arcdir in log_empty_dirs:
                if not arcdir.endswith('/'):
                    arcdir = arcdir + '/'
                try:
                    zinfo = zipfile.ZipInfo(arcdir)
                    zinfo.external_attr = 0o40775 << 16
                    zf.writestr(zinfo, b'')
                except Exception as e:
                    log(f'Failed to add empty log dir to zip {arcdir}: {e}\n' + traceback.format_exc())
        return out_path, had_error
    finally:
        # Cleanup tempdir unless running in dry-run/benchmark mode.
        try:
            if tmpdir and os.path.exists(tmpdir) and not DRY_RUN:
                try:
                    # attempt to remove temp files to free pagecache/swap used by Python
                    shutil.rmtree(tmpdir, ignore_errors=True)
                    # ensure data is flushed to disk
                    try:
                        os.sync()
                    except Exception:
                        # os.sync may not be available on all platforms
                        pass
                except Exception as e:
                    log(f'Failed to remove tmpdir {tmpdir}: {e}\n' + traceback.format_exc())
        except Exception:
            pass
        # Hint Python to free memory held by objects
        try:
            gc.collect()
        except Exception:
            pass

async def open_db_connection(db_name='website'):
    if not aiomysql:
        raise RuntimeError('aiomysql not installed')
    db_host = os.environ.get('SQL_HOST') or os.environ.get('DB_HOST')
    db_user = os.environ.get('SQL_USER') or os.environ.get('DB_USER')
    db_password = os.environ.get('SQL_PASSWORD') or os.environ.get('DB_PASSWORD')
    if not all([db_host, db_user, db_password]):
        raise RuntimeError('Database connection info missing in environment (SQL_HOST/SQL_USER/SQL_PASSWORD)')
    port = 3306
    return await aiomysql.connect(host=db_host, user=db_user, password=db_password, db=db_name, port=port, cursorclass=aiomysql.DictCursor)

async def export_db_as_json(db_name, out_dir):
    conn = await open_db_connection(db_name)
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            # get tables
            await cur.execute("SHOW TABLES")
            rows = await cur.fetchall()
            # rows are dicts with a single value; extract table names
            table_names = []
            for r in rows:
                for v in r.values():
                    table_names.append(v)
            for table in table_names:
                out_file = os.path.join(out_dir, f"{table}.json")
                await cur.execute(f"SELECT * FROM `{table}`")
                # Stream rows to JSON array to avoid building a huge list in memory
                with open(out_file, 'w', encoding='utf-8') as f:
                    f.write('[\n')
                    first = True
                    batch_size = 500
                    while True:
                        rows_batch = await cur.fetchmany(batch_size)
                        if not rows_batch:
                            break
                        for row in rows_batch:
                            if not first:
                                f.write(',\n')
                            f.write(json.dumps(row, default=str, ensure_ascii=False))
                            first = False
                    f.write('\n]\n')
    finally:
        try:
            conn.close()
        except Exception:
            pass

CHILD_PROCS = []
MONITOR_STOP = None


async def export_db_sql_dump(db_name, out_dir):
    if not db_name:
        raise RuntimeError('No database name provided for SQL dump')
    db_host = os.environ.get('SQL_HOST') or os.environ.get('DB_HOST')
    db_user = os.environ.get('SQL_USER') or os.environ.get('DB_USER')
    db_password = os.environ.get('SQL_PASSWORD') or os.environ.get('DB_PASSWORD')
    if not all([db_host, db_user, db_password]):
        raise RuntimeError('Database connection info missing in environment (SQL_HOST/SQL_USER/SQL_PASSWORD)')
    out_file = os.path.join(out_dir, f"{db_name}.sql")
    # Build mysqldump command
    cmd = [
        'mysqldump',
        '--host', db_host,
        '--user', db_user,
        '--port', '3306',
        '--routines',
        '--triggers',
        '--single-transaction',
        '--quick',
        db_name,
    ]
    env = os.environ.copy()
    env['MYSQL_PWD'] = db_password
    # Run mysqldump asynchronously and stream stdout to file to avoid buffering large dumps
    try:
        proc = await asyncio.create_subprocess_exec(*cmd, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE, env=env)
        try:
            CHILD_PROCS.append(proc)
        except Exception:
            pass
    except FileNotFoundError:
        log('mysqldump binary not found in PATH; falling back to connection-based SQL dump')
        # fallback: perform SQL dump via DB connection
        return await export_db_sql_dump_via_connection(db_name, out_dir)
    stderr = b''
    try:
        with open(out_file, 'wb') as f:
            assert proc.stdout is not None
            while True:
                chunk = await proc.stdout.read(65536)
                if not chunk:
                    break
                f.write(chunk)
            # read stderr after stdout finished
            if proc.stderr is not None:
                stderr = await proc.stderr.read()
            returncode = await proc.wait()
    except Exception:
        # If writing fails, try to terminate process
        try:
            proc.kill()
        except Exception:
            pass
        raise
    if returncode != 0:
        err_text = stderr.decode(errors='ignore') if stderr else '(no stderr)'
        log(f'mysqldump failed for {db_name}: {err_text}')
        # write error file for debugging
        try:
            with open(out_file, 'ab') as f:
                f.write(b'\n-- mysqldump failed\n')
                if stderr:
                    f.write(stderr)
        except Exception:
            log(f'Failed to write mysqldump error file for {db_name}')
        raise RuntimeError(f'mysqldump failed: {err_text}')


async def export_db_sql_dump_via_connection(db_name, out_dir):
    out_file = os.path.join(out_dir, f"{db_name}.sql")
    conn = await open_db_connection(db_name)
    try:
        async with conn.cursor() as cur:
            await cur.execute("SHOW TABLES")
            rows = await cur.fetchall()
            table_names = []
            for r in rows:
                if isinstance(r, dict):
                    for v in r.values():
                        table_names.append(v)
                elif isinstance(r, (list, tuple)):
                    table_names.append(r[0])
            with open(out_file, 'w', encoding='utf-8') as f:
                f.write(f"-- SQL dump generated by fallback at {datetime.datetime.now(datetime.timezone.utc).isoformat()}\n")
                for table in table_names:
                    # get create table
                    await cur.execute(f"SHOW CREATE TABLE `{table}`")
                    create_row = await cur.fetchone()
                    create_stmt = None
                    if isinstance(create_row, dict):
                        vals = list(create_row.values())
                        if len(vals) > 1:
                            create_stmt = vals[1]
                        elif vals:
                            create_stmt = vals[0]
                    elif isinstance(create_row, (list, tuple)):
                        if len(create_row) > 1:
                            create_stmt = create_row[1]
                        else:
                            create_stmt = create_row[0]
                    if create_stmt:
                        f.write(f"\n--\n-- Table structure for `{table}`\n--\n\n")
                        f.write(create_stmt + ";\n\n")
                    # dump rows
                    await cur.execute(f"SELECT * FROM `{table}`")
                    cols = [d[0] for d in cur.description]
                    batch_size = 1000
                    while True:
                        rows_batch = await cur.fetchmany(batch_size)
                        if not rows_batch:
                            break
                        values_lines = []
                        for row in rows_batch:
                            # row can be tuple or dict depending on cursor
                            if isinstance(row, dict):
                                vals = [row.get(c) for c in cols]
                            else:
                                vals = list(row)
                            esc = []
                            for v in vals:
                                if v is None:
                                    esc.append('NULL')
                                else:
                                    s = str(v).replace("'", "''")
                                    esc.append(f"'{s}'")
                            values_lines.append('(' + ','.join(esc) + ')')
                        f.write(f"INSERT INTO `{table}` (`" + '`,`'.join(cols) + "`) VALUES\n")
                        f.write(',\n'.join(values_lines) + ";\n")
    finally:
        try:
            conn.close()
        except Exception:
            pass
    return out_file

async def get_user_contact_info(username):
    conn = await open_db_connection('website')
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            # try common users table
            await cur.execute("SELECT id, email FROM users WHERE username = %s LIMIT 1", (username,))
            row = await cur.fetchone()
            if row:
                return {'id': row.get('id'), 'email': row.get('email')}
            # fallback to other common table names
            await cur.execute("SELECT id, email FROM `user` WHERE username = %s LIMIT 1", (username,))
            row = await cur.fetchone()
            if row:
                return {'id': row.get('id'), 'email': row.get('email')}
            return None
    finally:
        try:
            conn.close()
        except Exception:
            pass

async def fetch_website_user_row(username):
    conn = await open_db_connection('website')
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute("SELECT * FROM users WHERE username = %s LIMIT 1", (username,))
            row = await cur.fetchone()
            return row
    finally:
        try:
            conn.close()
        except Exception:
            pass

async def export_website_table_filtered(table_name, key_column, key_value, out_dir):
    out_file = os.path.join(out_dir, f"{table_name}.json")
    # If no key provided, write empty rows
    if key_value is None:
        with open(out_file, 'w', encoding='utf-8') as f:
            json.dump({'table': table_name, 'rows': []}, f, default=str, ensure_ascii=False, indent=2)
        return
    conn = await open_db_connection('website')
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            q = f"SELECT * FROM `{table_name}` WHERE `{key_column}` = %s"
            try:
                await cur.execute(q, (key_value,))
            except Exception as e:
                # If the table is missing and it's optional, write empty rows instead of failing
                if isinstance(e, pymysql.err.ProgrammingError) and getattr(e, 'args', None) and e.args[0] == 1146 and table_name in OPTIONAL_TABLES:
                    log(f'Optional table {table_name} does not exist; writing empty rows')
                    with open(out_file, 'w', encoding='utf-8') as f:
                        json.dump({'table': table_name, 'rows': []}, f, default=str, ensure_ascii=False, indent=2)
                    return
                raise
            # Stream rows to file to avoid big memory usage
            with open(out_file, 'w', encoding='utf-8') as f:
                f.write('{"table":')
                f.write(json.dumps(table_name))
                f.write(',"rows":[\n')
                first = True
                batch_size = 500
                while True:
                    rows_batch = await cur.fetchmany(batch_size)
                    if not rows_batch:
                        break
                    for row in rows_batch:
                        if not first:
                            f.write(',\n')
                        f.write(json.dumps(row, default=str, ensure_ascii=False))
                        first = False
                f.write('\n]}')
    finally:
        try:
            conn.close()
        except Exception:
            pass


def gather_media_entries(username):
    media_root = '/mnt/media'
    media_types = ['soundalerts', 'videoalerts', 'walkons']
    files = []
    empty_dirs = []
    for m in media_types:
        src_dir = os.path.join(media_root, m, username)
        try:
            exists = os.path.exists(src_dir)
        except Exception as e:
            # I/O error when checking path (bad mount); write marker and treat as empty
            err_text = f'Media path check failed for {src_dir}: {e}'
            log(err_text)
            try:
                _write_manual_notification_marker(None, username, err_text)
            except Exception:
                log(f'Failed to write manual notification marker for media I/O error: {src_dir}')
            empty_dirs.append(os.path.join(m, username).replace('\\', '/') + '/')
            continue
        if exists:
            try:
                for root, dirs, filenames in os.walk(src_dir):
                    for fname in filenames:
                        full = os.path.join(root, fname)
                        rel = os.path.relpath(full, src_dir)
                        arc = os.path.join(m, username, rel).replace('\\', '/')
                        files.append((full, arc))
            except Exception as e:
                # I/O error while walking the directory; write marker and treat as empty
                err_text = f'Media walk failed for {src_dir}: {e}'
                log(err_text)
                try:
                    _write_manual_notification_marker(None, username, err_text)
                except Exception:
                    log(f'Failed to write manual notification marker for media walk error: {src_dir}')
                empty_dirs.append(os.path.join(m, username).replace('\\', '/') + '/')
        else:
            # indicate an empty directory entry for the archive
            empty_dirs.append(os.path.join(m, username).replace('\\', '/') + '/')
    return files, empty_dirs


def gather_bot_logs(username):
    logs_root = '/home/botofthespecter/logs/logs'
    subfolders = ['bot', 'websocket', 'twitch', 'event_log', 'chat_history', 'chat', 'api']
    files = []
    empty_dirs = []
    for sub in subfolders:
        src_dir = os.path.join(logs_root, sub)
        try:
            exists = os.path.exists(src_dir)
        except Exception as e:
            err_text = f'Log path check failed for {src_dir}: {e}'
            log(err_text)
            try:
                _write_manual_notification_marker(None, username, err_text)
            except Exception:
                log(f'Failed to write manual notification marker for logs I/O error: {src_dir}')
            empty_dirs.append(os.path.join('logs', sub).replace('\\', '/') + '/')
            continue
        found = False
        if exists:
            try:
                for fname in os.listdir(src_dir):
                    # match username.txt and rotated variants like username.txt.1, username.txt.2, etc.
                    if fname == f"{username}.txt" or fname.startswith(f"{username}.txt."):
                        full = os.path.join(src_dir, fname)
                        arc = os.path.join('logs', sub, fname).replace('\\', '/')
                        files.append((full, arc))
                        found = True
            except Exception as e:
                err_text = f'Log walk failed for {src_dir}: {e}'
                log(err_text)
                try:
                    _write_manual_notification_marker(None, username, err_text)
                except Exception:
                    log(f'Failed to write manual notification marker for logs walk error: {src_dir}')
        if not exists or not found:
            # create an archive dir entry so admins can see that folder was expected
            empty_dirs.append(os.path.join('logs', sub).replace('\\', '/') + '/')
    return files, empty_dirs

async def _resource_monitor(interval, out_metrics):
    try:
        import psutil
    except Exception:
        log('psutil not available; benchmark monitoring disabled')
        out_metrics['available'] = False
        return
    out_metrics['available'] = True
    proc = psutil.Process()
    peak_rss = 0
    cpu_samples = []
    start = time.time()
    while True:
        if MONITOR_STOP and MONITOR_STOP.is_set():
            break
        try:
            rss = proc.memory_info().rss
            # include children
            for c in proc.children(recursive=True):
                try:
                    rss += c.memory_info().rss
                except Exception:
                    pass
            if rss > peak_rss:
                peak_rss = rss
            # cpu_percent with interval=None returns since last call; call once per loop
            cpu = proc.cpu_percent(interval=None)
            for c in proc.children(recursive=True):
                try:
                    cpu += c.cpu_percent(interval=None)
                except Exception:
                    pass
            cpu_samples.append(cpu)
        except Exception:
            pass
        await asyncio.sleep(interval)
    duration = time.time() - start
    out_metrics['peak_rss'] = peak_rss
    out_metrics['cpu_max'] = max(cpu_samples) if cpu_samples else 0.0
    out_metrics['cpu_avg'] = (sum(cpu_samples) / len(cpu_samples)) if cpu_samples else 0.0
    out_metrics['duration'] = duration

def send_email(smtp_host, smtp_port, smtp_username, smtp_password, from_addr, to_addr, subject, body, attachment_path, html_body=None):
    msg = EmailMessage()
    from_name = os.environ.get('SMTP_FROM_NAME') or SMTP_FROM_NAME
    msg['From'] = formataddr((from_name, from_addr))
    msg['To'] = to_addr
    msg['Subject'] = subject
    msg.set_content(body)
    # Add HTML version if provided
    if html_body:
        msg.add_alternative(html_body, subtype='html')
    # attach file if present
    if attachment_path and os.path.exists(attachment_path):
        with open(attachment_path, 'rb') as f:
            data = f.read()
        msg.add_attachment(data, maintype='application', subtype='zip', filename=os.path.basename(attachment_path))
    port = int(smtp_port) if smtp_port else 0
    last_exc = None
    # Try up to 3 attempts with backoff
    for attempt in range(3):
        try:
            # If port is 465, use SMTP_SSL
            if port == 465:
                with smtplib.SMTP_SSL(smtp_host, port, timeout=30) as s:
                    if smtp_username and smtp_password:
                        s.login(smtp_username, smtp_password)
                    s.send_message(msg)
            else:
                with smtplib.SMTP(smtp_host, port or 25, timeout=30) as s:
                    s.ehlo()
                    try:
                        s.starttls()
                        s.ehlo()
                    except Exception:
                        # starttls may fail if server doesn't support it; fallback to SSL on next attempt
                        pass
                    if smtp_username and smtp_password:
                        s.login(smtp_username, smtp_password)
                    s.send_message(msg)
            return
        except Exception as e:
            last_exc = e
            log(f'SMTP attempt {attempt+1} failed: {e}')
            # small backoff
            time.sleep(2 ** attempt)
            # on next attempt, if not using SSL and starttls failed earlier, try SSL
            if port != 465:
                port = 465
    # if we reach here, all attempts failed
    raise last_exc


def _write_manual_notification_marker(attachment_path, username, error_text):
    """Write a local marker file next to the ZIP so admin can find failed exports."""
    try:
        if attachment_path and os.path.exists(attachment_path):
            base = os.path.splitext(os.path.basename(attachment_path))[0]
            marker_name = f"{base}_NEEDS_MANUAL_NOTIFICATION.txt"
            marker_path = os.path.join(os.path.dirname(attachment_path), marker_name)
        else:
            exports_dir = os.path.join(os.path.dirname(__file__), 'exports')
            os.makedirs(exports_dir, exist_ok=True)
            marker_path = os.path.join(exports_dir, f"manual_notification_{username}_{int(datetime.datetime.now(datetime.timezone.utc).timestamp())}.txt")
        with open(marker_path, 'w', encoding='utf-8') as f:
            f.write(f"Timestamp: {datetime.datetime.now(datetime.timezone.utc).isoformat()}\n")
            f.write(f"Username: {username}\n")
            f.write(f"Error: {error_text}\n")
            if attachment_path:
                f.write(f"Attachment: {attachment_path}\n")
        log(f'Wrote manual notification marker: {marker_path}')
    except Exception as e:
        log(f'Failed to write manual notification marker: {e}')

def send_admin_notification(username, error_text, attachment_path=None, user_id=None, user_email=None):
    smtp_host = os.environ.get('SMTP_HOST')
    smtp_port = os.environ.get('SMTP_PORT')
    smtp_username = os.environ.get('SMTP_USERNAME')
    smtp_password = os.environ.get('SMTP_PASSWORD')
    from_email = os.environ.get('SMTP_FROM') or smtp_username
    admin = ADMIN_NOTIFICATION_EMAIL
    if not smtp_host or not from_email:
        log('SMTP not configured; cannot send admin notification')
        return
    subject = f'User data export FAILED for {username or "(unknown)"}'
    details = [f"Username: {username or '(unknown)'}"]
    if user_id is not None:
        details.append(f"User ID: {user_id}")
    if user_email:
        details.append(f"User email: {user_email}")
    details_text = '\n'.join(details)
    body = (
        f"An automated export for the following user failed and requires manual intervention:\n\n"
        f"{details_text}\n\n"
        f"Error details:\n{error_text}\n\n"
        f"See {LOG_FILE} for more info. The export ZIP (if any) is attached."
    )
    details_html = '<br>'.join(details)
    html_body = f"""
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 30px; border-left: 4px solid #ef4444;">
                            <h1 style="color: #dc2626; font-size: 24px; margin: 0 0 20px 0;">⚠️ Export Failed</h1>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 0 0 20px 0;">
                                An automated export for the following user failed and requires manual intervention:
                            </p>
                            <div style="background-color: #f9fafb; border-radius: 6px; padding: 20px; margin: 0 0 20px 0;">
                                <p style="color: #374151; font-size: 14px; line-height: 20px; margin: 0;">
                                    {details_html}
                                </p>
                            </div>
                            <h2 style="color: #374151; font-size: 18px; margin: 0 0 10px 0;">Error Details:</h2>
                            <div style="background-color: #fef2f2; border-left: 3px solid #ef4444; padding: 15px; margin: 0 0 20px 0;">
                                <pre style="color: #991b1b; font-size: 12px; line-height: 18px; margin: 0; white-space: pre-wrap; word-wrap: break-word; font-family: 'Courier New', monospace;">{error_text}</pre>
                            </div>
                            <p style="color: #6b7280; font-size: 14px; line-height: 20px; margin: 0 0 20px 0;">
                                See <code style="background-color: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 13px;">{LOG_FILE}</code> for more info.
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 20px 0 0 0;">
                                Regards,<br>
                                <strong>BotOfTheSpecter Automated Exports</strong>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
"""
    try:
        send_email(smtp_host, smtp_port, smtp_username, smtp_password, from_email, admin, subject, body, attachment_path, html_body=html_body)
        log(f'Admin notification sent to {admin} for {username}')
        return True
    except Exception as e:
        err_text = str(e) + '\n' + traceback.format_exc()
        log(f'Failed to send admin notification: {err_text}')
        # write local marker file so operator can find and manually notify
        try:
            _write_manual_notification_marker(attachment_path, username, err_text)
        except Exception as ex:
            log(f'Failed to write manual notification marker: {ex}\n' + traceback.format_exc())
        return False

def send_admin_success_report(username, sent_type, sent_value=None, user_email=None):
    smtp_host = os.environ.get('SMTP_HOST')
    smtp_port = os.environ.get('SMTP_PORT')
    smtp_username = os.environ.get('SMTP_USERNAME')
    smtp_password = os.environ.get('SMTP_PASSWORD')
    from_email = os.environ.get('SMTP_FROM') or smtp_username
    admin = ADMIN_NOTIFICATION_EMAIL
    if not smtp_host or not from_email:
        log('SMTP not configured; cannot send admin success report')
        return False
    subject = f'User data export completed: {username}'
    # human-readable size
    filesize_text = 'unknown'
    try:
        if sent_type == 'zip' and sent_value and os.path.exists(sent_value):
            size = os.path.getsize(sent_value)
            for unit in ['B','KB','MB','GB','TB']:
                if size < 1024.0:
                    filesize_text = f"{size:.1f} {unit}"
                    break
                size /= 1024.0
    except Exception:
        filesize_text = 'unknown'
    now_iso = datetime.datetime.now(datetime.timezone.utc).isoformat()
    lines = [
        'Admins,',
        '',
        'A user data export completed successfully.',
        '',
        f'Username: {username}',
        f'Request timestamp (UTC): {now_iso}',
    ]
    if user_email:
        lines.append(f'User email: {user_email}')
    # Delivery details
    if sent_type == 'zip' and sent_value:
        lines.append(f'Delivery: ZIP file emailed to user')
        lines.append(f'Filename: {os.path.basename(sent_value)}')
        lines.append(f'File size: {filesize_text}')
        # include local path so admins can inspect
        lines.append(f'Local path: {sent_value}')
    elif sent_type == 'link' and sent_value:
        lines.append('Delivery: Signed download link provided to user')
        lines.append(f'Link: {sent_value}')
    else:
        lines.append(f'Delivery: {sent_type or "(unspecified)"} {sent_value or ""}')
    lines.extend([
        '',
        f'Log file: {LOG_FILE}',
        '',
        'If you need to resend or inspect the archive, locate the local path above and follow standard procedures.',
        '',
        'Regards,',
        'BotOfTheSpecter Automated Exports',
    ])
    body = '\n'.join(lines)
    
    # Build HTML version
    delivery_html = ''
    if sent_type == 'zip' and sent_value:
        delivery_html = f"""
            <div style="background-color: #f0fdf4; border-left: 3px solid #22c55e; padding: 15px; margin: 20px 0;">
                <p style="color: #166534; font-size: 14px; line-height: 20px; margin: 0 0 8px 0;"><strong>Delivery:</strong> ZIP file emailed to user</p>
                <p style="color: #166534; font-size: 14px; line-height: 20px; margin: 0 0 8px 0;"><strong>Filename:</strong> {os.path.basename(sent_value)}</p>
                <p style="color: #166534; font-size: 14px; line-height: 20px; margin: 0 0 8px 0;"><strong>File size:</strong> {filesize_text}</p>
                <p style="color: #166534; font-size: 14px; line-height: 20px; margin: 0;"><strong>Local path:</strong> <code style="background-color: #dcfce7; padding: 2px 6px; border-radius: 3px; font-size: 12px;">{sent_value}</code></p>
            </div>
"""
    elif sent_type == 'link' and sent_value:
        delivery_html = f"""
            <div style="background-color: #eff6ff; border-left: 3px solid #3b82f6; padding: 15px; margin: 20px 0;">
                <p style="color: #1e40af; font-size: 14px; line-height: 20px; margin: 0 0 8px 0;"><strong>Delivery:</strong> Signed download link provided to user</p>
                <p style="color: #1e40af; font-size: 14px; line-height: 20px; margin: 0; word-break: break-all;"><strong>Link:</strong> <a href="{sent_value}" style="color: #2563eb;">{sent_value[:80]}...</a></p>
            </div>
"""
    
    html_body = f"""
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 30px; border-left: 4px solid #22c55e;">
                            <h1 style="color: #16a34a; font-size: 24px; margin: 0 0 20px 0;">✅ Export Completed</h1>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 0 0 20px 0;">
                                A user data export completed successfully.
                            </p>
                            <div style="background-color: #f9fafb; border-radius: 6px; padding: 20px; margin: 0 0 20px 0;">
                                <p style="color: #374151; font-size: 14px; line-height: 20px; margin: 0 0 8px 0;"><strong>Username:</strong> {username}</p>
                                <p style="color: #374151; font-size: 14px; line-height: 20px; margin: 0 0 8px 0;"><strong>Request timestamp (UTC):</strong> {now_iso}</p>
                                {f'<p style="color: #374151; font-size: 14px; line-height: 20px; margin: 0;"><strong>User email:</strong> {user_email}</p>' if user_email else ''}
                            </div>
                            {delivery_html}
                            <p style="color: #6b7280; font-size: 14px; line-height: 20px; margin: 20px 0;">
                                Log file: <code style="background-color: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 13px;">{LOG_FILE}</code>
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 20px 0 0 0;">
                                Regards,<br>
                                <strong>BotOfTheSpecter Automated Exports</strong>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
"""
    try:
        send_email(smtp_host, smtp_port, smtp_username, smtp_password, from_email, admin, subject, body, None, html_body=html_body)
        log(f'Admin success report sent to {admin} for {username}')
        return True
    except Exception as e:
        err_text = str(e) + '\n' + traceback.format_exc()
        log(f'Failed to send admin success report: {err_text}')
        try:
            _write_manual_notification_marker(None, username, err_text)
        except Exception as ex:
            log(f'Failed to write manual notification marker for admin success report: {ex}\n' + traceback.format_exc())
        return False

async def main():
    try:
        # parse CLI args
        p = argparse.ArgumentParser()
        p.add_argument('username', help='username to export')
        p.add_argument('--dry-run', action='store_true', help='Do not send emails; only create zip')
        p.add_argument('--no-delete', action='store_true', help="Don't delete the ZIP after emailing the user")
        p.add_argument('--benchmark', action='store_true', help='Run in dry-run and measure CPU/memory use (requires psutil)')
        args = p.parse_args()
        username = args.username
        dry_run = args.dry_run or args.benchmark
        # set global flag so outer exception handlers can respect dry-run
        global DRY_RUN
        DRY_RUN = dry_run
        no_delete = args.no_delete
        benchmark = args.benchmark
        # We'll look up contact info (email) from the central website DB
        log(f'Starting export for username={username}')
        email = ''
        try:
            contact = await get_user_contact_info(username)
            if contact:
                email = contact.get('email') or ''
                log(f'Found contact info for {username}: email={email}')
            else:
                log(f'No contact info found for username={username}')
        except Exception as e:
            log(f'Failed to lookup contact info: {e}\n' + traceback.format_exc())
        out_dir = os.path.join(os.path.dirname(__file__), 'exports')
        os.makedirs(out_dir, exist_ok=True)
        out_zip = os.path.join(out_dir, f'user_export_{username}_{int(datetime.datetime.now(datetime.timezone.utc).timestamp())}.zip')
        monitor_task = None
        metrics = {}
        try:
            if benchmark:
                try:
                    global MONITOR_STOP
                    MONITOR_STOP = asyncio.Event()
                    monitor_task = asyncio.create_task(_resource_monitor(1.0, metrics))
                except Exception as e:
                    log(f'Failed to start resource monitor: {e}\n' + traceback.format_exc())
            out_zip, had_error = await create_zip(username, out_zip)
        except asyncio.CancelledError:
            log('Export cancelled before completion')
            # attempt to kill any child procs
            for pproc in CHILD_PROCS:
                try:
                    pproc.kill()
                except Exception:
                    pass
            return 130
        log(f'Created zip at {out_zip} (had_error={had_error})')
        # Reload environment variables to ensure we have the latest S3_ALWAYS_UPLOAD setting
        load_dotenv(override=True)
        s3_always_upload = os.environ.get('S3_ALWAYS_UPLOAD', 'False').lower() in ('true', '1', 'yes')
        # determine if zip is too large to email or if we should always upload to R2
        large_zip = False
        upload_to_r2_required = s3_always_upload  # Upload to R2 if always_upload is enabled
        try:
            if os.path.exists(out_zip):
                size = os.path.getsize(out_zip)
                if size > MAX_EMAIL_ZIP_SIZE:
                    large_zip = True
                    upload_to_r2_required = True  # Also upload if file is too large
                    log(f'Zip file {out_zip} is too large to email ({size} bytes)')
                elif s3_always_upload:
                    log(f'S3_ALWAYS_UPLOAD is enabled; will upload to R2 regardless of size ({size} bytes)')
        except Exception as e:
            log(f'Failed to stat zip file size: {e}')
        # Try to send email if SMTP configured. If any export step failed, do NOT email the user; notify admin instead.
        smtp_host = os.environ.get('SMTP_HOST')
        smtp_port = os.environ.get('SMTP_PORT')
        smtp_username = os.environ.get('SMTP_USERNAME')
        smtp_password = os.environ.get('SMTP_PASSWORD')
        from_email = os.environ.get('SMTP_FROM') or smtp_username
        if had_error:
            # send admin notification and do not email user
            try:
                if dry_run:
                    log('Dry-run: would notify admin of failure; skipping email')
                else:
                    if large_zip:
                        send_admin_notification(username, f'Export completed but ZIP is too large to email (>{MAX_EMAIL_ZIP_SIZE} bytes). Manual upload to S3 required.', None, user_email=email)
                    else:
                        send_admin_notification(username, 'One or more export steps failed; manual export required.', out_zip, user_email=email)
            except Exception as e:
                log(f'Failed to send admin notification: {e}\n' + traceback.format_exc())
            log(f'Export for {username} completed with errors; admin notified; user not emailed')
        else:
            # Handle files by uploading to R2 if required (either large or S3_ALWAYS_UPLOAD is enabled)
            download_link = None
            if upload_to_r2_required:
                try:
                    if dry_run:
                        log('Dry-run: file would be uploaded to R2; skipping')
                    else:
                        # Generate R2 key with username and date
                        date_str = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d')
                        timestamp = int(datetime.datetime.now(datetime.timezone.utc).timestamp())
                        r2_key = f'user-exports/{username}/BotOfTheSpecter_Export_{username}_{date_str}_{timestamp}.zip'
                        download_link = await upload_to_r2(out_zip, r2_key)
                        log(f'Export uploaded to R2: {r2_key}')
                except Exception as e:
                    log(f'Failed to upload to R2: {e}\n' + traceback.format_exc())
                    # Fallback: notify admin to handle manually
                    try:
                        if large_zip:
                            send_admin_notification(username, f'Export completed but R2 upload failed: {e}. ZIP is too large to email (>{MAX_EMAIL_ZIP_SIZE} bytes). Manual upload required.', None, user_email=email)
                        else:
                            send_admin_notification(username, f'Export completed but R2 upload failed: {e}. S3_ALWAYS_UPLOAD is enabled but upload failed. Manual upload required.', None, user_email=email)
                    except Exception:
                        log('Failed to send admin notification about R2 upload failure')
                    log(f'Export for {username} completed but R2 upload failed; admin notified')
                    return 1
            if smtp_host and from_email and email:
                try:
                    user_subject = 'BotOfTheSpecter: Data Export from Dashboard'
                    if download_link:
                        user_body = (
                            f"Hi {username},\n\n"
                            "Thanks for requesting your data from our system.\n\n"
                            "Your export file has been uploaded to a secure download link.\n\n"
                            "Download your data here (valid for 7 days):\n"
                            f"{download_link}\n\n"
                            "Please download your data within 7 days. After that, the link will expire and you'll need to request a new export.\n\n"
                            "Regards,\n"
                            "BotOfTheSpecter Dashboard Systems"
                        )
                        user_html_body = f"""
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h1 style="color: #333333; font-size: 24px; margin: 0 0 20px 0;">Hi {username},</h1>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 0 0 20px 0;">
                                Thanks for requesting your data from our system.
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 0 0 30px 0;">
                                Your export file has been uploaded to a secure download link.
                            </p>
                            <table role="presentation" style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td align="center" style="padding: 0 0 30px 0;">
                                        <a href="{download_link}" style="display: inline-block; padding: 15px 40px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;">Download Your Data</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="color: #999999; font-size: 14px; line-height: 20px; margin: 0 0 20px 0;">
                                Please download your data within <strong>7 days</strong>. After that, the link will expire and you'll need to request a new export.
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 30px 0 0 0;">
                                Regards,<br>
                                <strong>BotOfTheSpecter Dashboard Systems</strong>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
"""
                    else:
                        user_body = (
                            f"Hi {username},\n\n"
                            "Thanks for requesting your data from our system.\n\n"
                            "We have attached the export to this email so you may download this at any time.\n\n"
                            "Regards,\n"
                            "BotOfTheSpecter Dashboard Systems"
                        )
                        user_html_body = f"""
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h1 style="color: #333333; font-size: 24px; margin: 0 0 20px 0;">Hi {username},</h1>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 0 0 20px 0;">
                                Thanks for requesting your data from our system.
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 0 0 30px 0;">
                                We have attached the export to this email so you may download this at any time.
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 24px; margin: 30px 0 0 0;">
                                Regards,<br>
                                <strong>BotOfTheSpecter Dashboard Systems</strong>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
"""
                    if dry_run:
                        log(f'Dry-run: would send email to {email} with {"download link" if download_link else "attachment " + out_zip}')
                    else:
                        # Don't attach file if we have a download link (R2 upload)
                        attachment = None if download_link else out_zip
                        send_email(smtp_host, smtp_port, smtp_username, smtp_password, from_email, email,
                                   user_subject,
                                   user_body, attachment, html_body=user_html_body)
                        log(f'Email sent to {email} with {"download link" if download_link else "attachment"}')
                    # remove the zip now that it's been emailed to the user
                    try:
                        if monitor_task is not None and metrics.get('available'):
                            # ensure benchmark metrics are written before potential deletion
                            metrics_path = out_zip + '.benchmark.json'
                            if not os.path.exists(metrics_path):
                                try:
                                    with open(metrics_path, 'w', encoding='utf-8') as mf:
                                        json.dump(metrics, mf, default=str, ensure_ascii=False, indent=2)
                                    log(f'Wrote benchmark metrics to {metrics_path}')
                                except Exception as e:
                                    log(f'Failed to write benchmark metrics before deletion: {e}\n' + traceback.format_exc())
                        if not no_delete:
                            if os.path.exists(out_zip):
                                os.remove(out_zip)
                                log(f'Removed exported zip {out_zip} after emailing user')
                        else:
                            log(f'Keeping exported zip {out_zip} due to --no-delete')
                    except Exception as e:
                        log(f'Failed to remove exported zip {out_zip}: {e}\n' + traceback.format_exc())
                    # Send plain-text admin report (no attachment)
                    try:
                        if dry_run:
                            log('Dry-run: would send admin success report')
                        else:
                            if download_link:
                                send_admin_success_report(username, 'link', sent_value=download_link, user_email=email)
                            else:
                                send_admin_success_report(username, 'zip', sent_value=out_zip, user_email=email)
                    except Exception as e:
                        log(f'Failed to send admin success report: {e}\n' + traceback.format_exc())
                    # If benchmarking, stop monitor and write metrics
                    if monitor_task is not None:
                        try:
                            MONITOR_STOP.set()
                            await monitor_task
                            # write metrics to JSON next to the zip (or in exports dir if zip removed)
                            try:
                                metrics_path = out_zip + '.benchmark.json'
                                with open(metrics_path, 'w', encoding='utf-8') as mf:
                                    json.dump(metrics, mf, default=str, ensure_ascii=False, indent=2)
                                log(f'Wrote benchmark metrics to {metrics_path}')
                            except Exception as e:
                                log(f'Failed to write benchmark metrics: {e}\n' + traceback.format_exc())
                        except Exception as e:
                            log(f'Failed to stop/wait for monitor task: {e}\n' + traceback.format_exc())
                except Exception as e:
                    log(f'Failed to send email: {e}\n' + traceback.format_exc())
                    # attempt to notify admin about email failure (attach zip if not uploaded to R2)
                    try:
                        send_admin_notification(username, f'Failed to send user email: {e}', out_zip if not download_link else None, user_email=email)
                    except Exception:
                        log('Failed to notify admin about email failure')
            else:
                log('SMTP not configured or recipient missing; export left on disk')
                # attempt to notify admin about successful export (will create marker file if SMTP fails)
                try:
                    send_admin_notification(username, 'Export completed successfully; SMTP not configured so export left on disk.', attachment_path=out_zip, user_email=email)
                except Exception as e:
                    log(f'Failed to notify admin of successful export when SMTP missing: {e}\n' + traceback.format_exc())
        return 0
    except Exception as e:
        err_text = str(e) + '\n' + traceback.format_exc()
        log('Unhandled error: ' + err_text)
        try:
            if not DRY_RUN:
                send_admin_notification(username if 'username' in locals() else '(unknown)', err_text, None,
                                        user_email=email if 'email' in locals() else None)
            else:
                log('Dry-run: suppressed admin notification for unhandled error')
        except Exception:
            log('Failed to send admin notification for unhandled error')
        return 2

if __name__ == '__main__':
    try:
        exit_code = asyncio.run(main())
        sys.exit(exit_code)
    except KeyboardInterrupt:
        log('Interrupted by user (KeyboardInterrupt)')
        # attempt to kill any child subprocesses
        try:
            for pproc in CHILD_PROCS:
                try:
                    pproc.kill()
                except Exception:
                    pass
        except Exception:
            pass
        sys.exit(130)