#!/usr/bin/env python3
"""Consume webhook requests as the same user as the scheduled sync."""
import os
from pathlib import Path
import subprocess
import time

QUEUE_DIR = Path(os.environ.get('SYNC_QUEUE_DIR', '/run/gdrive-sync'))
POLL_SECONDS = 2
RETRY_SECONDS = 30


def process_pending(queue_dir=QUEUE_DIR, command=('/usr/local/bin/run-sync.sh',)):
    pending = queue_dir / 'pending'
    processing = queue_dir / 'processing'
    # Retain a failed/interrupted request until a sync succeeds. A notification
    # arriving during sync creates a separate pending request for the next run.
    if not processing.exists():
        try:
            pending.replace(processing)
        except FileNotFoundError:
            return False

    print('[sync-worker] Processing queued notification', flush=True)
    env = dict(os.environ, SYNC_WAIT_FOR_LOCK='1', PYTHONUNBUFFERED='1')
    subprocess.run(command, env=env, check=True)
    processing.unlink()
    return True


def main():
    print('[sync-worker] Waiting for webhook notifications', flush=True)
    while True:
        try:
            process_pending()
        except (OSError, subprocess.CalledProcessError) as exc:
            print(f'[sync-worker] Sync failed; retrying in {RETRY_SECONDS}s: {exc}', flush=True)
            time.sleep(RETRY_SECONDS)
        else:
            time.sleep(POLL_SECONDS)


if __name__ == '__main__':
    main()
