<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/common.php';

const PING_CONNECT_TIMEOUT = 7;
const PING_SESSION_TIMEOUT = 9;
const PING_HISTORY_DAYS = 90;
const PING_RUN_TIME_LIMIT = 240;

final class HubPingStatus extends Exception
{
    public string $status;

    public function __construct(string $status, string $message = '')
    {
        $this->status = $status;
        parent::__construct($message !== '' ? $message : $status);
    }
}

function send_all($stream, string $message): void
{
    $remaining = $message;
    while ($remaining !== '') {
        $written = @fwrite($stream, $remaining);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Nie udało się wysłać komunikatu protokołu.');
        }
        $remaining = substr($remaining, $written);
    }
}

function read_frame($stream, string $delimiter, float $deadline, int $maximum = 65536): ?string
{
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
        return null;
    }
    stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
    $message = @stream_get_line($stream, $maximum, $delimiter);
    if ($message === false || $message === '') {
        $meta = stream_get_meta_data($stream);
        if (!empty($meta['timed_out']) || feof($stream)) {
            return null;
        }
        return $message === '' ? '' : null;
    }
    return rtrim($message, "\r");
}

function connect_hub(array $hub)
{
    $secure = in_array($hub['protocol'], ['ADCS', 'NMDCS'], true);
    $transport = $secure ? 'tls' : 'tcp';
    $host = (string) $hub['host'];
    $connectIp = public_hub_ip($host);
    $target = str_contains($connectIp, ':') ? '[' . trim($connectIp, '[]') . ']' : $connectIp;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim($host, '[]'),
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $start = microtime(true);
    $stream = @stream_socket_client(
        $transport . '://' . $target . ':' . (int) $hub['port'],
        $errno,
        $errstr,
        PING_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($stream === false) {
        $lower = strtolower($errstr);
        $status = $secure && (str_contains($lower, 'certificate') || str_contains($lower, 'ssl') || str_contains($lower, 'crypto'))
            ? 'tls_error'
            : 'offline';
        throw new HubPingStatus($status, $errstr !== '' ? $errstr : 'Połączenie odrzucone.');
    }

    stream_set_blocking($stream, true);
    stream_set_timeout($stream, PING_SESSION_TIMEOUT, 0);
    $cert = null;
    if ($secure && function_exists('openssl_x509_parse')) {
        $options = stream_context_get_options($stream);
        $peerCert = $options['ssl']['peer_certificate'] ?? null;
        if ($peerCert !== null) {
            $cert = openssl_x509_parse($peerCert);
        }
    }
    return [$stream, $start, $cert];
}

function public_hub_ip(string $host): string
{
    $host = trim($host, '[]');
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (filter_var($host, FILTER_VALIDATE_IP, $flags) === false) {
            throw new HubPingStatus('invalid_address', 'Adres IP nie jest publiczny.');
        }
        return $host;
    }

    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records)) {
        throw new HubPingStatus('dns_error', 'Nie udało się rozwiązać nazwy hosta.');
    }
    foreach ($records as $record) {
        $address = $record['ip'] ?? $record['ipv6'] ?? null;
        if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, $flags) !== false) {
            return $address;
        }
    }
    throw new HubPingStatus('invalid_address', 'Host nie wskazuje publicznego adresu IP.');
}

function country_for_public_ip(string $ip): string
{
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
        throw new InvalidArgumentException('Geolokalizacja wymaga publicznego adresu IP.');
    }
    $url = 'https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country_code';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Nie udało się rozpocząć zapytania geolokalizacyjnego.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Hublist-IP-country-lookup/1.0',
    ]);
    $body = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($body) || $statusCode !== 200) {
        throw new RuntimeException('Usługa geolokalizacji IP nie odpowiedziała poprawnie'
            . ($curlError !== '' ? ': ' . $curlError : ' (HTTP ' . $statusCode . ').'));
    }
    $data = json_decode($body, true);
    $country = is_array($data) ? strtoupper((string) ($data['country_code'] ?? '')) : '';
    if (!is_array($data) || ($data['success'] ?? false) !== true || !preg_match('/^[A-Z]{2}$/', $country)) {
        throw new RuntimeException('Usługa geolokalizacji IP nie zwróciła prawidłowego kraju.');
    }
    return $country;
}

function current_certificate($stream): array
{
    $options = stream_context_get_options($stream);
    $peerCert = $options['ssl']['peer_certificate'] ?? null;
    $cert = $peerCert !== null ? openssl_x509_parse($peerCert) : null;
    $fingerprint = $peerCert !== null && function_exists('openssl_x509_fingerprint')
        ? (openssl_x509_fingerprint($peerCert, 'sha256') ?: null)
        : null;
    return [$cert, $fingerprint];
}

function escape_nmdc_key(string $lock): string
{
    $bytes = array_values(unpack('C*', $lock));
    $length = count($bytes);
    if ($length < 3) {
        throw new RuntimeException('Hub NMDC zwrócił nieprawidłowy Lock.');
    }
    $key = [];
    $key[0] = $bytes[0] ^ $bytes[$length - 1] ^ $bytes[$length - 2] ^ 5;
    for ($index = 1; $index < $length; $index++) {
        $key[$index] = $bytes[$index] ^ $bytes[$index - 1];
    }
    foreach ($key as &$byte) {
        $byte = ($byte ^ (($byte << 4) & 0xff) ^ (($byte >> 4) & 0xff)) & 0xff;
    }
    unset($byte);

    $result = '';
    foreach ($key as $byte) {
        if (in_array($byte, [0, 5, 36, 96, 124, 126], true)) {
            $result .= sprintf('/%%DCN%03d%%/', $byte);
        } else {
            $result .= chr($byte);
        }
    }
    return $result;
}

function sanitize_nmdc_profile_field(string $value): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F|$]/u', ' ', $value) ?? '');
}

function escape_adc_profile_field(string $value): string
{
    return str_replace(["\\", " "], ["\\\\", "\\s"], $value);
}

function ping_nmdc($stream, string $nick, int $started, array $profile): array
{
    $deadline = microtime(true) + PING_SESSION_TIMEOUT;
    $hubName = '';
    $hubTopic = '';
    $users = [];
    $ownNickSeen = false;
    $sentInfo = false;

    while (microtime(true) < $deadline) {
        $line = read_frame($stream, '|', $deadline);
        if ($line === null) {
            break;
        }
        if (stripos($line, '$Lock ') === 0) {
            $lock = preg_split('/\s+/', substr($line, 6), 2)[0] ?? '';
            if ($lock === '') {
                throw new RuntimeException('Pusta odpowiedź Lock.');
            }
            send_all($stream, '$Supports UserCommand UserIP2 TTHSearch|$Key ' . escape_nmdc_key($lock) . '|$ValidateNick ' . $nick . '|$Version ' . $profile['version'] . '|');
            continue;
        }
        if (stripos($line, '$GetPass') === 0) {
            throw new HubPingStatus('auth_required');
        }
        if (stripos($line, '$Hello ') === 0) {
            $helloNick = trim(substr($line, 7));
            if (strcasecmp($helloNick, $nick) === 0) {
                $ownNickSeen = true;
                if (!$sentInfo) {
                    send_all($stream, '$MyINFO $ALL ' . $nick . ' ' . $profile['description'] . '$ $'
                        . $profile['connection'] . '$' . $profile['email'] . '$0$|$GetNickList|');
                    $sentInfo = true;
                }
            } else {
                $users[strtolower($helloNick)] ??= 0;
            }
            continue;
        }
        if (stripos($line, '$MyINFO $ALL ') === 0
            && preg_match('/^\$MyINFO \$ALL ([^ ]+) .*?\$[^$]*\$[^$]*\$[^$]*\$([0-9]+)\$$/s', $line, $info)) {
            $myInfoNick = strtolower($info[1]);
            if (strcasecmp($info[1], $nick) !== 0) {
                $users[$myInfoNick] = (int) $info[2];
            }
            continue;
        }
        if (stripos($line, '$HubName ') === 0) {
            $hubName = trim(substr($line, 9));
            continue;
        }
        if (stripos($line, '$HubTopic ') === 0) {
            $hubTopic = trim(substr($line, 10));
            continue;
        }
        if (stripos($line, '$NickList ') === 0) {
            foreach (explode('$$', substr($line, 10)) as $listedNick) {
                $listedNick = trim($listedNick);
                if ($listedNick !== '' && strcasecmp($listedNick, $nick) !== 0) {
                    $users[strtolower($listedNick)] ??= 0;
                }
            }
            break;
        }
        if (stripos($line, '$ValidateDenide') === 0 || stripos($line, '$BadPass') === 0) {
            throw new HubPingStatus('rejected');
        }
        if (stripos($line, '$ForceMove ') === 0) {
            throw new HubPingStatus('redirected');
        }
    }

    if (!$ownNickSeen) {
        throw new RuntimeException('Hub nie zakończył logowania pingera.');
    }
    if ($hubName === '' && $users === []) {
        throw new RuntimeException('Brak odpowiedzi z informacjami huba.');
    }
    send_all($stream, '$Quit ' . $nick . '|');
    return [
        'ping_ms' => (int) round((microtime(true) - $started) * 1000),
        'hub_name' => $hubName ?: null,
        'hub_topic' => $hubTopic ?: null,
        'online_users' => count($users),
        'shared_bytes' => array_sum($users) > 0 ? array_sum($users) : null,
    ];
}

function base32_encode_bytes(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $result = '';
    for ($index = 0, $length = strlen($bytes); $index < $length; $index++) {
        $buffer = ($buffer << 8) | ord($bytes[$index]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $result .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $result .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $result;
}

function decode_adc_text(string $value): string
{
    return str_replace(['\\\\', '\\s', '\\n'], ["\\", ' ', "\n"], $value);
}

function ping_adc($stream, string $nick, int $started, array $profile): array
{
    if (!in_array('tiger192,3', hash_algos(), true)) {
        throw new RuntimeException('Serwer PHP nie obsługuje Tiger/192-3 potrzebnego do ADC.');
    }

    send_all($stream, "HSUP ADBASE ADTIGR\n");
    $deadline = microtime(true) + PING_SESSION_TIMEOUT;
    $hubSid = '';
    $supported = false;
    $ownSid = substr(base32_encode_bytes(random_bytes(3)), 0, 4);
    $pid = hash('tiger192,3', random_bytes(24), true);
    $cid = base32_encode_bytes(hash('tiger192,3', $pid, true));
    $sentInfo = false;
    $hubName = '';
    $hubTopic = '';
    $users = [];
    $sharedBySid = [];
    $hubShared = null;
    $hubInfoSeen = false;
    $ownInfoSeen = false;

    while (microtime(true) < $deadline) {
        $line = read_frame($stream, "\n", $deadline);
        if ($line === null) {
            break;
        }
        if (str_starts_with($line, 'ISUP ')) {
            $supported = true;
            continue;
        }
        if (str_starts_with($line, 'ISID ')) {
            $hubSid = substr($line, 5, 4);
            if (!preg_match('/^[A-Z2-7]{4}$/', $hubSid)) {
                throw new RuntimeException('Hub ADC zwrócił nieprawidłowy identyfikator sesji.');
            }
            if (!$sentInfo) {
                send_all($stream, 'BINF ' . $ownSid . ' ID' . $cid
                    . ' NI' . $nick . ' DE' . escape_adc_profile_field($profile['description'])
                    . ' VE' . escape_adc_profile_field($profile['version'])
                    . ($profile['email'] !== '' ? ' EM' . escape_adc_profile_field($profile['email']) : '')
                    . ' APHublist\\sPHP SS0 SF0 SL1 HN1' . "\n");
                $sentInfo = true;
            }
            continue;
        }
        if (str_starts_with($line, 'ISTA ')) {
            $parts = explode(' ', $line, 4);
            $code = (int) ($parts[1] ?? 0);
            if ($code >= 400) {
                throw new HubPingStatus('rejected');
            }
            if ($code === 200 && stripos($line, 'password') !== false) {
                throw new HubPingStatus('auth_required');
            }
            continue;
        }
        if (str_starts_with($line, 'HPAS ')) {
            throw new HubPingStatus('auth_required');
        }
        if (str_starts_with($line, 'IINF ')) {
            $parts = explode(' ', $line);
            if (($parts[1] ?? '') !== $hubSid) {
                continue;
            }
            $hubInfoSeen = true;
            foreach (array_slice($parts, 2) as $field) {
                if (strlen($field) < 2) {
                    continue;
                }
                $key = substr($field, 0, 2);
                $value = decode_adc_text(substr($field, 2));
                if ($key === 'NI') {
                    $hubName = $value;
                } elseif ($key === 'DE') {
                    $hubTopic = $value;
                } elseif ($key === 'SS' && ctype_digit($value)) {
                    $hubShared = (int) $value;
                }
            }
            continue;
        }
        if (str_starts_with($line, 'BINF ')) {
            $parts = explode(' ', $line);
            $sid = substr($parts[1] ?? '', 0, 4);
            if ($sid !== '' && $sid !== $ownSid) {
                $users[$sid] = true;
                foreach (array_slice($parts, 2) as $field) {
                    if (str_starts_with($field, 'SS') && ctype_digit(substr($field, 2))) {
                        $sharedBySid[$sid] = (int) substr($field, 2);
                    }
                }
            } elseif ($sid === $ownSid) {
                $ownInfoSeen = true;
            }
            continue;
        }
        if (str_starts_with($line, 'ISTA 2') || str_starts_with($line, 'IQUI ')) {
            throw new HubPingStatus('rejected');
        }
    }

    if (!$supported || !$sentInfo) {
        throw new RuntimeException('Hub nie zakończył uzgadniania ADC.');
    }
    if (!$hubInfoSeen && !$ownInfoSeen && $users === []) {
        throw new RuntimeException('Hub ADC nie zwrócił informacji ani listy użytkowników.');
    }
    return [
        'ping_ms' => (int) round((microtime(true) - $started) * 1000),
        'hub_name' => $hubName ?: null,
        'hub_topic' => $hubTopic ?: null,
        'online_users' => count($users),
        'shared_bytes' => $hubShared ?? (array_sum($sharedBySid) > 0 ? array_sum($sharedBySid) : null),
    ];
}

function ping_one_hub(array $hub, string $nick, array $profile): array
{
    $stream = null;
    try {
        [$stream, $started, $cert] = connect_hub($hub);
        if (in_array($hub['protocol'], ['ADC', 'ADCS'], true)) {
            $stats = ping_adc($stream, $nick, $started, $profile);
        } else {
            $stats = ping_nmdc($stream, $nick, $started, $profile);
        }
        [$cert, $fingerprint] = current_certificate($stream);
        return $stats + certificate_metadata($cert, $fingerprint) + [
            'status' => 'online',
            'error' => null,
        ];
    } catch (HubPingStatus $exception) {
        $secure = in_array($hub['protocol'], ['ADCS', 'NMDCS'], true);
        $cert = is_resource($stream) && $secure ? current_certificate($stream) : [null, null];
        return [
            'status' => $exception->status,
            'error' => $exception->getMessage(),
            'ping_ms' => null,
            'hub_name' => null,
            'hub_topic' => null,
            'online_users' => null,
            'shared_bytes' => null,
            'tls_cert_valid' => $secure && $exception->status === 'tls_error' ? 0 : ($cert[0] !== null ? 1 : null),
            'tls_cert_expires' => isset($cert[0]['validTo_time_t']) ? gmdate('Y-m-d H:i:s', (int) $cert[0]['validTo_time_t']) : null,
            'tls_cert_issuer' => isset($cert[0]['issuer']['CN']) ? substr((string) $cert[0]['issuer']['CN'], 0, 255) : null,
            'tls_fingerprint' => $cert[1],
        ];
    } catch (Throwable $exception) {
        $secure = in_array($hub['protocol'], ['ADCS', 'NMDCS'], true);
        $cert = is_resource($stream) && $secure ? current_certificate($stream) : [null, null];
        return [
            'status' => $secure && str_contains(strtolower($exception->getMessage()), 'ssl') ? 'tls_error' : 'protocol_error',
            'error' => substr($exception->getMessage(), 0, 255),
            'ping_ms' => null,
            'hub_name' => null,
            'hub_topic' => null,
            'online_users' => null,
            'shared_bytes' => null,
            'tls_cert_valid' => $secure && str_contains(strtolower($exception->getMessage()), 'ssl') ? 0 : ($cert[0] !== null ? 1 : null),
            'tls_cert_expires' => isset($cert[0]['validTo_time_t']) ? gmdate('Y-m-d H:i:s', (int) $cert[0]['validTo_time_t']) : null,
            'tls_cert_issuer' => isset($cert[0]['issuer']['CN']) ? substr((string) $cert[0]['issuer']['CN'], 0, 255) : null,
            'tls_fingerprint' => $cert[1],
        ];
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

function run_pinger(): void
{
    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'hublist-pinger-' . hash('sha256', __DIR__) . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Inny pinger jest już uruchomiony.');
    }

    $pdo = db();
    $storedSettings = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN
        ('pinger_nick','pinger_description','pinger_version','pinger_email','pinger_connection','pinger_interval')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $nick = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($storedSettings['pinger_nick'] ?? 'Hublist-Pinger'));
    if (strlen($nick) < 3) {
        $nick = 'Hublist-Pinger';
    }
    $interval = filter_var($storedSettings['pinger_interval'] ?? '48', FILTER_VALIDATE_INT);
    if ($interval === false || $interval < 5 || $interval > 10080) {
        throw new RuntimeException('Nieprawidłowy odstęp pingowania w ustawieniach.');
    }
    $profile = [
        'description' => sanitize_nmdc_profile_field((string) ($storedSettings['pinger_description'] ?? 'Hublist pinger')),
        'version' => sanitize_nmdc_profile_field((string) ($storedSettings['pinger_version'] ?? '1,0091')),
        'email' => sanitize_nmdc_profile_field((string) ($storedSettings['pinger_email'] ?? '')),
        'connection' => sanitize_nmdc_profile_field((string) ($storedSettings['pinger_connection'] ?? 'DSL')),
    ];
    if ($profile['version'] === '') {
        $profile['version'] = '1,0091';
    }
    $limit = 100;
    global $argv;
    foreach ($argv as $argument) {
        if (preg_match('/^--limit=([0-9]+)$/', $argument, $match)) {
            $limit = min(500, max(1, (int) $match[1]));
        }
    }

    $hubs = $pdo->query(
        "SELECT * FROM hubs WHERE status='approved'
         AND (last_ping_at IS NULL OR last_ping_at <= UTC_TIMESTAMP() - INTERVAL $interval MINUTE)
         ORDER BY last_ping_at IS NULL DESC, last_ping_at ASC LIMIT $limit"
    )->fetchAll();
    $update = $pdo->prepare(
        'UPDATE hubs SET pinger_status=?, pinger_error=?, ping_ms=?, last_ping_at=UTC_TIMESTAMP(),
            tls_cert_valid=?, tls_cert_expires=?, tls_cert_issuer=?, tls_fingerprint=?,
            hub_name=COALESCE(?,hub_name), hub_topic=COALESCE(?,hub_topic),
            online_users=COALESCE(?,online_users), shared_bytes=COALESCE(?,shared_bytes)
         WHERE id=?'
    );
    $updateCountry = $pdo->prepare(
        "UPDATE hubs SET country=?,country_source='ip',country_ip=?
         WHERE id=? AND (country_source IS NULL OR country_source <> 'manual')"
    );
    $history = $pdo->prepare(
        'INSERT INTO ping_history (hub_id,is_online,ping_ms,tls_cert_valid,tls_cert_expires,online_users)
         VALUES (?,?,?,?,?,?)'
    );
    $deadline = microtime(true) + PING_RUN_TIME_LIMIT;
    $checked = 0;
    foreach ($hubs as $hub) {
        if (microtime(true) >= $deadline) {
            fwrite(STDOUT, "Osiągnięto limit czasu jednego przebiegu; pozostałe huby sprawdzi następny cron.\n");
            break;
        }
        $result = ping_one_hub($hub, $nick, $profile);
        if (($hub['country_source'] ?? null) !== 'manual') {
            try {
                $ip = public_hub_ip((string) $hub['host']);
                if (($hub['country_ip'] ?? null) !== $ip) {
                    $country = country_for_public_ip($ip);
                    $updateCountry->execute([$country, $ip, $hub['id']]);
                }
            } catch (Throwable $exception) {
                error_log('Hublist country lookup failed for hub ' . (int) $hub['id'] . ': ' . $exception->getMessage());
            }
        }
        $checked++;
        $update->execute([
            $result['status'], $result['error'], $result['ping_ms'], $result['tls_cert_valid'],
            $result['tls_cert_expires'], $result['tls_cert_issuer'], $result['tls_fingerprint'],
            $result['hub_name'], $result['hub_topic'], $result['online_users'], $result['shared_bytes'],
            $hub['id'],
        ]);
        $history->execute([
            $hub['id'], $result['status'] === 'online' ? 1 : 0, $result['ping_ms'],
            $result['tls_cert_valid'], $result['tls_cert_expires'], $result['online_users'],
        ]);
        printf("%s %s://%s:%d %s%s\n", gmdate('c'), strtolower($hub['protocol']), $hub['host'],
            (int) $hub['port'], $result['status'], $result['error'] ? ' (' . $result['error'] . ')' : '');
    }
    $pdo->exec('DELETE FROM ping_history WHERE checked_at < UTC_TIMESTAMP() - INTERVAL ' . PING_HISTORY_DAYS . ' DAY');
    printf("Sprawdzone huby: %d z %d (limit czasu: %d s)\n", $checked, count($hubs), PING_RUN_TIME_LIMIT);
    flock($lock, LOCK_UN);
    fclose($lock);
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        run_pinger();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Hublist pinger error: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
