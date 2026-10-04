<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once __DIR__ . '/pinger.php';
start_app_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metoda niedozwolona.');
}
require_csrf();

$id = filter_var(post_string($_POST, 'hub_id'), FILTER_VALIDATE_INT);
$ownerCode = post_string($_POST, 'owner_code');
if (!$id || $id < 1 || !preg_match('/^[a-fA-F0-9]{64}$/', $ownerCode)) {
    http_response_code(403);
    exit('Nieprawidłowy kod właściciela lub identyfikator huba.');
}

$pdo = db();
$hubQuery = $pdo->prepare("SELECT * FROM hubs WHERE id=? AND status IN ('pending','approved') LIMIT 1");
$hubQuery->execute([$id]);
$hub = $hubQuery->fetch();
if (!$hub || !is_string($hub['owner_token_hash']) || !hash_equals($hub['owner_token_hash'], hash('sha256', strtolower($ownerCode)))) {
    http_response_code(403);
    exit('Nieprawidłowy kod właściciela lub identyfikator huba.');
}

$config = app_config();
$remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ipHash = hash_hmac('sha256', $remoteIp, $config['db_password'] . $config['db_name']);
$pdo->beginTransaction();
try {
    $reserveIp = $pdo->prepare("INSERT IGNORE INTO hub_owner_ping_limits (ip_hash,last_ping_at)
        VALUES (?,'1970-01-01 00:00:00')");
    $reserveIp->execute([$ipHash]);
    $ipLimit = $pdo->prepare('SELECT last_ping_at FROM hub_owner_ping_limits WHERE ip_hash=? FOR UPDATE');
    $ipLimit->execute([$ipHash]);
    $lastIpPing = $ipLimit->fetchColumn();
    if (is_string($lastIpPing) && strtotime($lastIpPing . ' UTC') > time() - 60) {
        throw new InvalidArgumentException('Z tego adresu IP można uruchomić jeden pomiar na minutę.');
    }

    $claimHub = $pdo->prepare("UPDATE hubs SET owner_ping_at=UTC_TIMESTAMP()
        WHERE id=? AND owner_token_hash=? AND status IN ('pending','approved')
        AND (owner_ping_at IS NULL OR owner_ping_at <= UTC_TIMESTAMP() - INTERVAL 5 MINUTE)");
    $claimHub->execute([$id, hash('sha256', strtolower($ownerCode))]);
    if ($claimHub->rowCount() !== 1) {
        throw new InvalidArgumentException('Ten hub można pingować raz na 5 minut. Odczekaj i spróbuj ponownie.');
    }
    $saveIpPing = $pdo->prepare('UPDATE hub_owner_ping_limits SET last_ping_at=UTC_TIMESTAMP() WHERE ip_hash=?');
    $saveIpPing->execute([$ipHash]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof InvalidArgumentException) {
        http_response_code(429);
        page_start('Pingowanie huba');
        echo '<main><section class="panel"><h1>Nie można teraz uruchomić pomiaru</h1><p class="error">'
            . e($exception->getMessage()) . '</p><p><a href="hub_owner.php?id=' . (int) $id . '">Wróć do panelu właściciela</a></p></section></main>';
        page_end();
        exit;
    }
    error_log('Hublist owner ping reservation failed for hub ' . (int) $id . ': ' . $exception->getMessage());
    http_response_code(500);
    exit('Nie udało się zarezerwować pomiaru. Spróbuj ponownie później.');
}

$pdo->exec("DELETE FROM hub_owner_ping_limits WHERE last_ping_at < UTC_TIMESTAMP() - INTERVAL 30 DAY");

if (function_exists('set_time_limit')) {
    set_time_limit(25);
}
$settings = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN
    ('pinger_nick','pinger_description','pinger_version','pinger_email','pinger_connection')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$nick = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($settings['pinger_nick'] ?? 'Hublist-Pinger'));
if (!is_string($nick) || strlen($nick) < 3) {
    $nick = 'Hublist-Pinger';
}
$profile = [
    'description' => sanitize_nmdc_profile_field((string) ($settings['pinger_description'] ?? 'Hublist pinger')),
    'version' => sanitize_nmdc_profile_field((string) ($settings['pinger_version'] ?? '1,0091')),
    'email' => sanitize_nmdc_profile_field((string) ($settings['pinger_email'] ?? '')),
    'connection' => sanitize_nmdc_profile_field((string) ($settings['pinger_connection'] ?? 'DSL')),
];
if ($profile['version'] === '') {
    $profile['version'] = '1,0091';
}

$result = ping_one_hub($hub, $nick, $profile);
try {
    $update = $pdo->prepare('UPDATE hubs SET pinger_status=?,pinger_error=?,ping_ms=?,last_ping_at=UTC_TIMESTAMP(),
        tls_cert_valid=?,tls_cert_expires=?,tls_cert_issuer=?,tls_fingerprint=?,
        hub_name=COALESCE(?,hub_name),hub_topic=COALESCE(?,hub_topic),
        online_users=COALESCE(?,online_users),shared_bytes=COALESCE(?,shared_bytes)
        WHERE id=? AND owner_token_hash=?');
    $update->execute([
        $result['status'], $result['error'], $result['ping_ms'], $result['tls_cert_valid'],
        $result['tls_cert_expires'], $result['tls_cert_issuer'], $result['tls_fingerprint'],
        $result['hub_name'], $result['hub_topic'], $result['online_users'], $result['shared_bytes'],
        $id, hash('sha256', strtolower($ownerCode)),
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Wynik pingowania nie został zapisany dla tego huba.');
    }
    $history = $pdo->prepare('INSERT INTO ping_history (hub_id,is_online,ping_ms,tls_cert_valid,tls_cert_expires,online_users)
        VALUES (?,?,?,?,?,?)');
    $history->execute([
        $id, $result['status'] === 'online' ? 1 : 0, $result['ping_ms'],
        $result['tls_cert_valid'], $result['tls_cert_expires'], $result['online_users'],
    ]);
    $_SESSION['owner_hub_tokens'][$id] = strtolower($ownerCode);
} catch (Throwable $exception) {
    error_log('Hublist owner-triggered ping save failed for hub ' . (int) $id . ': ' . $exception->getMessage());
    http_response_code(500);
    exit('Pomiar został wykonany, ale nie udało się zapisać wyniku. Skontaktuj się z administratorem.');
}

redirect('hub_owner.php?id=' . (int) $id . '&result=' . rawurlencode((string) $result['status']));
