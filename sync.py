#!/usr/bin/env python3
import os
import fcntl
import time
import requests
from pathlib import Path
import sys
import json
from concurrent.futures import ThreadPoolExecutor, as_completed

LOCK_FILE = '/tmp/sync.lock'
LAST_FULL_SYNC_FILE = os.path.join('/var/www/html/images', '.last_full_sync')
FULL_SYNC_INTERVAL = 3600

FOLDER_ID = os.environ.get('GDRIVE_FOLDER_ID')
CLIENT_ID = os.environ.get('GDRIVE_CLIENT_ID')
CLIENT_SECRET = os.environ.get('GDRIVE_CLIENT_SECRET')
REFRESH_TOKEN = os.environ.get('GDRIVE_REFRESH_TOKEN')
DEST_DIR = '/var/www/html/images'
BASE_URL = 'https://www.googleapis.com/drive/v3/files'
DOWNLOAD_WORKERS = 4

PAGE_TOKEN_FILE = os.path.join(DEST_DIR, '.page_token')
KNOWN_IDS_FILE = os.path.join(DEST_DIR, '.known_ids.json')

if not all([FOLDER_ID, CLIENT_ID, CLIENT_SECRET, REFRESH_TOKEN]):
    print("ERROR: GDRIVE_FOLDER_ID, GDRIVE_CLIENT_ID, GDRIVE_CLIENT_SECRET and GDRIVE_REFRESH_TOKEN must be set.")
    sys.exit(1)

def get_access_token():
    r = requests.post('https://oauth2.googleapis.com/token', data={
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'refresh_token': REFRESH_TOKEN,
        'grant_type': 'refresh_token',
    })
    r.raise_for_status()
    return r.json()['access_token']

def get_start_page_token(token):
    r = requests.get('https://www.googleapis.com/drive/v3/changes/startPageToken',
        headers={'Authorization': f'Bearer {token}'},
        params={'supportsAllDrives': 'true'})
    r.raise_for_status()
    return r.json()['startPageToken']

def check_changes(page_token, token, known_ids):
    """Check if any changes since page_token affect files in our folder tree."""
    headers = {'Authorization': f'Bearer {token}'}
    current_token = page_token
    relevant = False

    while True:
        r = requests.get('https://www.googleapis.com/drive/v3/changes',
            params={
                'pageToken': current_token,
                'fields': 'nextPageToken,newStartPageToken,changes(fileId,removed,file(id,parents))',
                'supportsAllDrives': 'true',
                'includeItemsFromAllDrives': 'true',
                'pageSize': 1000,
            },
            headers=headers)
        r.raise_for_status()
        data = r.json()

        if not relevant:
            for change in data.get('changes', []):
                file_id = change['fileId']
                if file_id in known_ids:
                    relevant = True
                    break
                if not change.get('removed'):
                    parents = change.get('file', {}).get('parents', [])
                    if any(p in known_ids for p in parents):
                        relevant = True
                        break

        if 'newStartPageToken' in data:
            return relevant, data['newStartPageToken']
        current_token = data['nextPageToken']

def list_files(parent_id, token):
    params = {
        'q': f"'{parent_id}' in parents and trashed = false",
        'fields': 'nextPageToken,files(id, name, mimeType, md5Checksum)',
        'supportsAllDrives': 'true',
        'includeItemsFromAllDrives': 'true',
        'pageSize': 1000
    }
    headers = {'Authorization': f'Bearer {token}'}
    files = []
    try:
        while True:
            r = requests.get(BASE_URL, params=params, headers=headers)
            r.raise_for_status()
            data = r.json()
            files.extend(data.get('files', []))
            next_token = data.get('nextPageToken')
            if not next_token:
                break
            params['pageToken'] = next_token
    except Exception as e:
        print(f"Error listing files in {parent_id}: {e}")
    return files

def download_file(file_id, dest_path, token):
    headers = {'Authorization': f'Bearer {token}'}
    try:
        with requests.get(f"{BASE_URL}/{file_id}", params={'alt': 'media'}, headers=headers, stream=True) as r:
            r.raise_for_status()
            with open(dest_path, 'wb') as f:
                for chunk in r.iter_content(chunk_size=65536):
                    f.write(chunk)
        print(f"  Downloaded: {dest_path}")
        return True
    except Exception as e:
        print(f"  Failed to download {dest_path}: {e}")
        return False

def collect_files(folder_id, local_path, token, manifest, relative_path=""):
    Path(local_path).mkdir(parents=True, exist_ok=True)
    items = list_files(folder_id, token)
    active_hashes = {}
    pending_downloads = []
    known_ids = {folder_id}

    for item in items:
        name = item['name']
        item_id = item['id']
        mime_type = item['mimeType']
        target_path = os.path.join(local_path, name)
        rel_path = os.path.join(relative_path, name) if relative_path else name

        if mime_type == 'application/vnd.google-apps.folder':
            sub_hashes, sub_pending, sub_ids = collect_files(item_id, target_path, token, manifest, rel_path)
            active_hashes.update(sub_hashes)
            pending_downloads.extend(sub_pending)
            known_ids.update(sub_ids)
        else:
            known_ids.add(item_id)
            drive_md5 = item.get('md5Checksum')
            active_hashes[rel_path] = drive_md5
            if not os.path.exists(target_path):
                pending_downloads.append((item_id, target_path))
            elif drive_md5 and manifest.get(rel_path) != drive_md5:
                print(f"  Changed: {rel_path}")
                pending_downloads.append((item_id, target_path))

    return active_hashes, pending_downloads, known_ids

if __name__ == '__main__':
    lock_fd = open(LOCK_FILE, 'w')
    try:
        # Webhook requests wait for cron/startup sync instead of being discarded.
        flags = fcntl.LOCK_EX
        if os.environ.get('SYNC_WAIT_FOR_LOCK') != '1':
            flags |= fcntl.LOCK_NB
        fcntl.flock(lock_fd, flags)
    except BlockingIOError:
        print("Another sync is already running, skipping.")
        sys.exit(0)

    print(f"Starting sync: Drive:{FOLDER_ID} -> {DEST_DIR}")

    token = get_access_token()

    # Force a full sync every FULL_SYNC_INTERVAL seconds to catch missed changes
    force_full = False
    if os.path.exists(LAST_FULL_SYNC_FILE):
        last_full = os.path.getmtime(LAST_FULL_SYNC_FILE)
        if time.time() - last_full >= FULL_SYNC_INTERVAL:
            force_full = True
            print("Forcing periodic full sync...")
    else:
        force_full = True

    # Quick check: skip full sync if no relevant changes detected
    if not force_full and os.path.exists(PAGE_TOKEN_FILE) and os.path.exists(KNOWN_IDS_FILE):
        try:
            with open(PAGE_TOKEN_FILE) as f:
                saved_page_token = f.read().strip()
            with open(KNOWN_IDS_FILE) as f:
                known_ids = set(json.load(f))

            has_changes, new_page_token = check_changes(saved_page_token, token, known_ids)

            with open(PAGE_TOKEN_FILE, 'w') as f:
                f.write(new_page_token)

            if not has_changes:
                print("No relevant changes, skipping.")
                sys.exit(0)

            print("Changes detected, running full sync...")
        except Exception as e:
            print(f"Changes check failed ({e}), falling back to full sync...")

    # 1. Load existing manifest
    manifest_path = os.path.join(DEST_DIR, 'manifest.json')
    old_manifest = {}
    if os.path.exists(manifest_path):
        with open(manifest_path) as f:
            data = json.load(f)
            if isinstance(data, dict):
                old_manifest = data

    # 2. Snapshot page token BEFORE walking tree so we catch changes during sync
    new_page_token = get_start_page_token(token)

    # 3. Walk Drive tree
    active_hashes, pending, known_ids = collect_files(FOLDER_ID, DEST_DIR, token, old_manifest)
    print(f"Found {len(active_hashes)} files, {len(pending)} need downloading.")

    # 4. Download changed/new files in parallel
    if pending:
        with ThreadPoolExecutor(max_workers=DOWNLOAD_WORKERS) as pool:
            futures = {pool.submit(download_file, fid, path, token): path for fid, path in pending}
            for future in as_completed(futures):
                future.result()

    # 5. Write manifest, known IDs, and page token
    with open(manifest_path, 'w') as f:
        json.dump(active_hashes, f)
    print(f"Manifest created with {len(active_hashes)} files.")

    with open(KNOWN_IDS_FILE, 'w') as f:
        json.dump(list(known_ids), f)

    with open(PAGE_TOKEN_FILE, 'w') as f:
        f.write(new_page_token)

    # 6. Prune local files no longer in Google Drive
    valid_paths = set(active_hashes.keys())
    valid_paths.add('manifest.json')
    valid_paths.add('.page_token')
    valid_paths.add('.known_ids.json')
    valid_paths.add('.last_full_sync')

    for root, dirs, files in os.walk(DEST_DIR, topdown=False):
        for name in files:
            full_path = os.path.join(root, name)
            rel_path = os.path.relpath(full_path, DEST_DIR)
            if rel_path not in valid_paths:
                os.remove(full_path)
                print(f"  Deleted orphaned file: {rel_path}")

        for name in dirs:
            full_path = os.path.join(root, name)
            if not os.listdir(full_path):
                os.rmdir(full_path)
                print(f"  Deleted empty directory: {name}")

    # Record full sync timestamp
    with open(LAST_FULL_SYNC_FILE, 'w') as f:
        f.write(str(int(time.time())))

    print("Sync finished.")
