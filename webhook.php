<?php
$tokenFile = '/tmp/webhook-token';
if (!file_exists($tokenFile)) {
    http_response_code(500);
    exit;
}

$expectedToken = trim(file_get_contents($tokenFile));
$receivedToken = $_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    http_response_code(403);
    exit;
}

// Google sends "sync" when the channel is first created — just acknowledge
$state = $_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? '';
if ($state === 'sync') {
    http_response_code(200);
    exit;
}

// Coalesce notifications into one pending request. Only the worker runs sync.
$queueDir = getenv('SYNC_QUEUE_DIR') ?: '/run/gdrive-sync';
$request = @fopen($queueDir . '/pending', 'c');
if ($request === false) {
    error_log('Unable to queue Google Drive sync');
    http_response_code(503);
    exit;
}
fclose($request);
http_response_code(200);
