#!/bin/bash
set -e

# Validate required env vars
if [ -z "$GDRIVE_FOLDER_ID" ] || [ -z "$GDRIVE_CLIENT_ID" ] || [ -z "$GDRIVE_CLIENT_SECRET" ] || [ -z "$GDRIVE_REFRESH_TOKEN" ]; then
  echo "ERROR: GDRIVE_FOLDER_ID, GDRIVE_CLIENT_ID, GDRIVE_CLIENT_SECRET and GDRIVE_REFRESH_TOKEN must be set."
  exit 1
fi

# Create wrapper scripts so cron and the worker can access env vars
cat > /usr/local/bin/run-sync.sh << ENVEOF
#!/bin/bash
export GDRIVE_FOLDER_ID="${GDRIVE_FOLDER_ID}"
export GDRIVE_CLIENT_ID="${GDRIVE_CLIENT_ID}"
export GDRIVE_CLIENT_SECRET="${GDRIVE_CLIENT_SECRET}"
export GDRIVE_REFRESH_TOKEN="${GDRIVE_REFRESH_TOKEN}"
/usr/local/bin/sync.py
ENVEOF
chmod +x /usr/local/bin/run-sync.sh

cat > /usr/local/bin/run-watch.sh << ENVEOF
#!/bin/bash
export GDRIVE_CLIENT_ID="${GDRIVE_CLIENT_ID}"
export GDRIVE_CLIENT_SECRET="${GDRIVE_CLIENT_SECRET}"
export GDRIVE_REFRESH_TOKEN="${GDRIVE_REFRESH_TOKEN}"
export WEBHOOK_URL="${WEBHOOK_URL}"
export WEBHOOK_TOKEN="${WEBHOOK_TOKEN}"
/usr/local/bin/watch.py
ENVEOF
chmod +x /usr/local/bin/run-watch.sh

# Preflight: verify API key and folder are accessible before syncing
echo "[entrypoint] Checking Google Drive API access..."
ACCESS_TOKEN=$(curl -s -X POST https://oauth2.googleapis.com/token \
  -d "client_id=${GDRIVE_CLIENT_ID}&client_secret=${GDRIVE_CLIENT_SECRET}&refresh_token=${GDRIVE_REFRESH_TOKEN}&grant_type=refresh_token" \
  | python3 -c "import sys,json; print(json.load(sys.stdin).get('access_token',''))")

if [ -z "$ACCESS_TOKEN" ]; then
  echo "[entrypoint] ERROR: Failed to obtain access token. Check your OAuth credentials."
  exit 1
fi

HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  "https://www.googleapis.com/drive/v3/files?q='${GDRIVE_FOLDER_ID}'+in+parents+and+trashed=false&fields=files(id)&pageSize=1")

if [ "$HTTP_STATUS" = "200" ]; then
  echo "[entrypoint] OAuth OK. Folder ${GDRIVE_FOLDER_ID} is accessible."
else
  echo "[entrypoint] ERROR: Drive API returned HTTP ${HTTP_STATUS}. Check folder ID and permissions."
  exit 1
fi

# Set up cron: fallback sync every 5 minutes + channel renewal every 12 hours
echo "*/5 * * * * root /usr/local/bin/run-sync.sh > /proc/1/fd/1 2>&1" > /etc/cron.d/gdrive-sync
echo "0 */12 * * * root /usr/local/bin/run-watch.sh > /proc/1/fd/1 2>&1" >> /etc/cron.d/gdrive-sync
chmod 0644 /etc/cron.d/gdrive-sync

# PHP can enqueue requests; the root worker owns sync execution and files.
export SYNC_QUEUE_DIR=/run/gdrive-sync
install -d -o root -g www-data -m 0770 "$SYNC_QUEUE_DIR"

# Start cron in background
cron

# Run initial sync in background so Apache starts immediately
echo "[entrypoint] Running initial sync in background..."
/usr/local/bin/run-sync.sh > /proc/1/fd/1 2>&1 &

# Set up webhook if configured
if [ -n "$WEBHOOK_URL" ] && [ -n "$WEBHOOK_TOKEN" ]; then
  echo "${WEBHOOK_TOKEN}" > /tmp/webhook-token
  chown root:www-data /tmp/webhook-token
  chmod 0640 /tmp/webhook-token
  python3 -u /usr/local/bin/sync-worker.py &
  echo "[entrypoint] Registering Drive webhook..."
  /usr/local/bin/run-watch.sh > /proc/1/fd/1 2>&1 &
  echo "[entrypoint] Webhook configured: ${WEBHOOK_URL}"
else
  echo "[entrypoint] WEBHOOK_URL/WEBHOOK_TOKEN not set, running in poll-only mode."
fi

echo "[entrypoint] Cron started. Fallback sync runs every 5 minutes."

# Start Apache in foreground
exec apache2-foreground
