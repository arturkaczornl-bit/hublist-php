<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

start_app_session();
$ip = normalize_ip_address((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
if ($ip === null) {
    http_response_code(400);
    exit;
}

$config = app_config();
$sessionKey = hash_hmac('sha256', session_id(), $config['db_password'] . $config['db_name']);
$stmt = db()->prepare(
    'UPDATE visitor_sessions SET last_seen=UTC_TIMESTAMP()
     WHERE session_key=? AND ip_address=?'
);
$stmt->execute([$sessionKey, $ip]);
http_response_code(204);
