#!/usr/bin/env python3
import os
import sys
import json
import uuid
import time
import requests

CLIENT_ID = os.environ.get('GDRIVE_CLIENT_ID')
CLIENT_SECRET = os.environ.get('GDRIVE_CLIENT_SECRET')
REFRESH_TOKEN = os.environ.get('GDRIVE_REFRESH_TOKEN')
WEBHOOK_URL = os.environ.get('WEBHOOK_URL')
WEBHOOK_TOKEN = os.environ.get('WEBHOOK_TOKEN')
CHANNEL_FILE = '/tmp/watch-channel.json'

if not all([CLIENT_ID, CLIENT_SECRET, REFRESH_TOKEN]):
    print("ERROR: GDRIVE_CLIENT_ID, GDRIVE_CLIENT_SECRET and GDRIVE_REFRESH_TOKEN must be set.")
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

def stop_channel(token):
    if not os.path.exists(CHANNEL_FILE):
        return
    with open(CHANNEL_FILE) as f:
        info = json.load(f)
    try:
        requests.post('https://www.googleapis.com/drive/v3/channels/stop',
            headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json'},
            json={'id': info['id'], 'resourceId': info['resourceId']})
        print(f"Stopped old channel: {info['id']}")
    except Exception as e:
        print(f"Warning: failed to stop old channel: {e}")
    os.remove(CHANNEL_FILE)

def register_channel(token):
    if not WEBHOOK_URL or not WEBHOOK_TOKEN:
        print("ERROR: WEBHOOK_URL and WEBHOOK_TOKEN must be set.")
        sys.exit(1)

    r = requests.get('https://www.googleapis.com/drive/v3/changes/startPageToken',
        headers={'Authorization': f'Bearer {token}'},
        params={'supportsAllDrives': 'true'})
    r.raise_for_status()
    page_token = r.json()['startPageToken']

    channel_id = str(uuid.uuid4())
    expiration = int((time.time() + 86400) * 1000)

    r = requests.post('https://www.googleapis.com/drive/v3/changes/watch',
        headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json'},
        params={'pageToken': page_token, 'supportsAllDrives': 'true'},
        json={
            'id': channel_id,
            'type': 'web_hook',
            'address': WEBHOOK_URL,
            'token': WEBHOOK_TOKEN,
            'expiration': expiration,
        })
    r.raise_for_status()

    channel_info = r.json()
    with open(CHANNEL_FILE, 'w') as f:
        json.dump(channel_info, f)

    expires_str = time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(expiration / 1000))
    print(f"Watch channel registered: {channel_id}")
    print(f"Webhook: {WEBHOOK_URL}")
    print(f"Expires: {expires_str} UTC")

if __name__ == '__main__':
    token = get_access_token()
    stop_channel(token)
    register_channel(token)
