#!/usr/bin/env python3
import os
import sys
import time
import json
import shutil
import subprocess
from pathlib import Path

QUEUE_DIR = Path('/home/botofthespecter/export_queue')
PROCESSING_DIR = QUEUE_DIR / 'processing'
PROCESSED_DIR = QUEUE_DIR / 'processed'
FAILED_DIR = QUEUE_DIR / 'failed'
LOG_FILE = Path('/home/botofthespecter/export_queue/queue.log')

def log(msg):
    ts = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(f"[{ts}] {msg}\n")
    except Exception:
        pass

def ensure_dirs():
    for d in (QUEUE_DIR, PROCESSING_DIR, PROCESSED_DIR, FAILED_DIR):
        try:
            d.mkdir(parents=True, exist_ok=True)
        except Exception as e:
            log(f'Failed to create dir {d}: {e}')

def pick_job():
    # FIFO by filename (timestamp prefix expected)
    # Only pick .json files, ignore logs and other files
    files = sorted([p for p in QUEUE_DIR.iterdir() if p.is_file() and p.suffix == '.json'])
    return files[0] if files else None

def run_job(jobfile: Path):
    try:
        with jobfile.open('r', encoding='utf-8') as f:
            job = json.load(f)
    except Exception as e:
        log(f'Invalid job file {jobfile}: {e}')
        jobfile.rename(FAILED_DIR / jobfile.name)
        return

    username = job.get('username')
    if not username:
        log(f'Job {jobfile} missing username; moving to failed')
        jobfile.rename(FAILED_DIR / jobfile.name)
        return

    procfile = PROCESSING_DIR / jobfile.name
    try:
        jobfile.rename(procfile)
    except Exception as e:
        log(f'Failed to move job {jobfile} to processing: {e}')
        return

    log(f'Starting export for {username} (job={procfile.name})')
    cmd = ['python3', '/home/botofthespecter/export_user_data.py', username]
    try:
        # Run and wait for completion; streaming output to log
        with subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True) as p:
            for line in p.stdout or []:
                log(f'[{procfile.name}] {line.rstrip()}')
            ret = p.wait()
    except Exception as e:
        log(f'Failed to run export for {username}: {e}')
        try:
            shutil.move(str(procfile), FAILED_DIR / procfile.name)
        except Exception:
            pass
        return

    if ret == 0:
        log(f'Export succeeded for {username} (job={procfile.name})')
        try:
            shutil.move(str(procfile), PROCESSED_DIR / procfile.name)
        except Exception as e:
            log(f'Failed to move processed job {procfile}: {e}')
    else:
        log(f'Export failed for {username} (job={procfile.name}) exit={ret}')
        try:
            shutil.move(str(procfile), FAILED_DIR / procfile.name)
        except Exception as e:
            log(f'Failed to move failed job {procfile}: {e}')

def main():
    ensure_dirs()
    log('Queue worker started')
    while True:
        job = pick_job()
        if job:
            run_job(job)
            # small pause between jobs to reduce thrash
            time.sleep(1)
        else:
            time.sleep(3)

if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        log('Queue worker interrupted')
        sys.exit(0)
