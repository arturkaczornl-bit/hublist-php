<?php
declare(strict_types=1);

const CACHE_TTL = 900;
const STALE_TTL = 86400;
const MAX_FEED_BYTES = 4194304;

$services = [
    [
        'id' => 'te-home',
        'name' => 'Team Elite',
        'url' => 'https://www.te-home.net/?do=hublist&get=hublist.xml',
        'format' => 'xml',
    ],
    [
        'id' => 'dchublist-org',
        'name' => 'dchublist.org',
        'url' => 'https://dchublist.org/hublist.xml',
        'format' => 'xml',
    ],
    [
        'id' => 'pwiam',
        'name' => 'Public DC Hublist (PWiAM)',
        'url' => 'https://hublist.pwiam.com/hublist.json',
        'format' => 'json',
    ],
    [
        'id' => 'dchublist-biz',
        'name' => 'dchublist.biz',
        'url' => 'https://dchublist.biz/?do=hublist&get=hublist.xml',
        'format' => 'xml',
    ],
    [
        'id' => 'dchublists-com',
        'name' => 'dchublists.com',
        'url' => 'https://dchublists.com/?do=hublist&get=hublist.xml',
        'format' => 'xml',
    ],
    [
        'id' => 'dchublist-ru',
        'name' => 'dchublist.ru',
        'url' => 'https://dchublist.ru/hublist.xml.bz2',
        'format' => 'bz2xml',
    ],
];

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cache_path(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'hublist-php-' . hash('sha256', __DIR__) . '.json';
}

function read_cache(): array
{
    $path = cache_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $cache = json_decode($contents, true);
    return is_array($cache) && isset($cache['providers']) && is_array($cache['providers'])
        ? $cache
        : [];
}

function write_cache(array $cache): bool
{
    $path = cache_path();
    $temporaryPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $contents = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($contents === false || file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
        error_log('hublist-php: could not write the feed cache.');
        return false;
    }

    if (!rename($temporaryPath, $path)) {
        unlink($temporaryPath);
        error_log('hublist-php: could not replace the feed cache.');
        return false;
    }

    return true;
}

function is_valid_address(string $address): bool
{
    if ($address === '' || stripos($address, 'do not connect') !== false) {
        return false;
    }

    if (preg_match('/^(dchub|nmdc|nmdcs|adc|adcs):\/\/[^\s<>"\']+$/i', $address)) {
        return true;
    }

    return (bool) preg_match(
        '/^(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]{1,5})?$/i',
        $address
    );
}

function field_value(array $fields, array $aliases): string
{
    foreach ($aliases as $alias) {
        $key = strtolower($alias);
        if (isset($fields[$key]) && is_scalar($fields[$key])) {
            return trim((string) $fields[$key]);
        }
    }

    return '';
}

function normalise_hub(array $fields, string $serviceId, string $serviceName): ?array
{
    $address = field_value($fields, ['address', 'hubaddress', 'url']);
    if (!is_valid_address($address)) {
        return null;
    }

    $name = field_value($fields, ['name', 'hubname']);
    if ($name === '') {
        $name = $address;
    }

    $usersText = field_value($fields, ['users', 'usercount', 'user_count']);
    $usersDigits = preg_replace('/[^0-9]/', '', $usersText);
    $users = $usersDigits !== '' ? (int) $usersDigits : null;

    $status = field_value($fields, ['status', 'state']);
    if ($status === '') {
        $status = 'Brak danych';
    }

    $sharedText = field_value($fields, ['shared', 'share', 'sharedbytes']);
    $sharedDigits = preg_replace('/[^0-9]/', '', $sharedText);
    $sharedBytes = $sharedDigits !== '' ? (int) $sharedDigits : null;

    return [
        'name' => $name,
        'address' => $address,
        'description' => field_value($fields, ['description', 'desc']),
        'country' => strtoupper(field_value($fields, ['country', 'countrycode'])),
        'users' => $users,
        'shared' => $sharedBytes,
        'status' => $status,
        'software' => field_value($fields, ['software', 'hubsoftware']),
        'service_id' => $serviceId,
        'service_name' => $serviceName,
    ];
}

function parse_xml_hubs(string $xml, array $service): array
{
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('Serwer wymaga rozszerzenia PHP DOM.');
    }

    $document = new DOMDocument();
    $previousSetting = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previousSetting);

    if (!$loaded) {
        throw new RuntimeException('Nieprawidłowy dokument XML.');
    }

    $hubs = [];
    foreach ($document->getElementsByTagName('Hub') as $element) {
        $fields = [];
        foreach ($element->attributes as $attribute) {
            $fields[strtolower($attribute->nodeName)] = $attribute->nodeValue;
        }
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $fields[strtolower($child->tagName)] = $child->textContent;
            }
        }

        $hub = normalise_hub($fields, $service['id'], $service['name']);
        if ($hub !== null) {
            $hubs[] = $hub;
        }
    }

    return $hubs;
}

function parse_feed(string $body, array $service): array
{
    if ($service['format'] === 'bz2xml') {
        if (!function_exists('bzdecompress')) {
            throw new RuntimeException('Źródło wymaga rozszerzenia PHP bz2.');
        }
        $decompressed = bzdecompress($body);
        if (!is_string($decompressed)) {
            throw new RuntimeException('Nie udało się rozpakować listy BZip2.');
        }
        $body = $decompressed;
    }

    if (strlen($body) > MAX_FEED_BYTES) {
        throw new RuntimeException('Lista przekracza dozwolony rozmiar.');
    }

    if ($service['format'] === 'json') {
        $document = json_decode($body, true);
        if (!is_array($document) || !isset($document['hublist']) || !is_array($document['hublist'])) {
            throw new RuntimeException('Nieprawidłowy dokument JSON.');
        }

        $hubs = [];
        foreach ($document['hublist'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hub = normalise_hub($item, $service['id'], $service['name']);
            if ($hub !== null) {
                $hubs[] = $hub;
            }
        }
        return $hubs;
    }

    return parse_xml_hubs($body, $service);
}

function fetch_feeds(array $services): array
{
    if (!function_exists('curl_multi_init')) {
        throw new RuntimeException('Serwer wymaga rozszerzenia PHP cURL.');
    }

    $multiHandle = curl_multi_init();
    $requests = [];
    $bodies = [];

    foreach ($services as $service) {
        if ($service['format'] === 'bz2xml' && !function_exists('bzdecompress')) {
            $requests[$service['id']] = [
                'service' => $service,
                'error' => 'Wymaga rozszerzenia PHP bz2.',
            ];
            continue;
        }

        $handle = curl_init($service['url']);
        if ($handle === false) {
            $requests[$service['id']] = [
                'service' => $service,
                'error' => 'Nie udało się rozpocząć pobierania źródła.',
            ];
            continue;
        }
        $bodies[$service['id']] = '';
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Hublist-PHP/1.0 (+public DC hub aggregator)',
            CURLOPT_HTTPHEADER => ['Accept: application/xml, application/json, application/octet-stream'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bodies, $service): int {
                $bodies[$service['id']] .= $chunk;
                return strlen($bodies[$service['id']]) <= MAX_FEED_BYTES ? strlen($chunk) : 0;
            },
        ]);
        curl_multi_add_handle($multiHandle, $handle);
        $requests[$service['id']] = [
            'service' => $service,
            'handle' => $handle,
        ];
    }

    do {
        do {
            $multiStatus = curl_multi_exec($multiHandle, $running);
        } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);
        if ($running > 0) {
            $selected = curl_multi_select($multiHandle, 1.0);
            if ($selected === -1) {
                usleep(100000);
            }
        }
    } while ($running > 0 && $multiStatus === CURLM_OK);

    foreach ($requests as $id => &$request) {
        if (!isset($request['handle'])) {
            continue;
        }

        $handle = $request['handle'];
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        $body = $bodies[$id];
        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);
        unset($request['handle']);

        if ($multiStatus !== CURLM_OK) {
            $request['error'] = 'Błąd pobierania listy.';
        } elseif ($curlError !== '') {
            $request['error'] = 'Błąd połączenia: ' . $curlError;
        } elseif ($statusCode < 200 || $statusCode >= 300) {
            $request['error'] = 'Serwis zwrócił HTTP ' . $statusCode . '.';
        } else {
            try {
                $request['hubs'] = parse_feed($body, $request['service']);
                $request['fetched_at'] = time();
            } catch (RuntimeException $exception) {
                $request['error'] = $exception->getMessage();
            }
        }
    }
    unset($request);

    curl_multi_close($multiHandle);
    return $requests;
}

function load_provider_data(array $services): array
{
    $cache = read_cache();
    if (isset($cache['fetched_at']) && time() - (int) $cache['fetched_at'] < CACHE_TTL) {
        return $cache;
    }

    $fresh = fetch_feeds($services);
    $providers = [];
    foreach ($services as $service) {
        $id = $service['id'];
        $result = $fresh[$id] ?? ['error' => 'Nie udało się pobrać źródła.'];

        if (isset($result['hubs'])) {
            $providers[$id] = [
                'status' => 'ok',
                'fetched_at' => $result['fetched_at'],
                'hubs' => $result['hubs'],
            ];
            continue;
        }

        $previous = $cache['providers'][$id] ?? null;
        if (is_array($previous)
            && isset($previous['fetched_at'], $previous['hubs'])
            && time() - (int) $previous['fetched_at'] <= STALE_TTL) {
            $previous['status'] = 'stale';
            $previous['error'] = $result['error'] ?? 'Nie udało się odświeżyć źródła.';
            $providers[$id] = $previous;
        } else {
            $providers[$id] = [
                'status' => 'error',
                'error' => $result['error'] ?? 'Źródło chwilowo niedostępne.',
                'hubs' => [],
            ];
        }
    }

    $updated = ['fetched_at' => time(), 'providers' => $providers];
    write_cache($updated);
    return $updated;
}

function merge_hubs(array $services, array $providers): array
{
    $hubs = [];
    foreach ($services as $service) {
        $provider = $providers[$service['id']] ?? [];
        foreach ($provider['hubs'] ?? [] as $hub) {
            $key = strtolower($hub['address']);
            if (!isset($hubs[$key])) {
                $hub['sources'] = [$service['name']];
                $hubs[$key] = $hub;
                continue;
            }

            if (!in_array($service['name'], $hubs[$key]['sources'], true)) {
                $hubs[$key]['sources'][] = $service['name'];
            }
            if ($hubs[$key]['users'] === null && $hub['users'] !== null) {
                $hubs[$key]['users'] = $hub['users'];
            }
            if ($hubs[$key]['status'] !== 'Online' && $hub['status'] === 'Online') {
                $hubs[$key]['status'] = 'Online';
            }
            if ($hubs[$key]['description'] === '' && $hub['description'] !== '') {
                $hubs[$key]['description'] = $hub['description'];
            }
        }
    }

    return array_values($hubs);
}

function format_bytes(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return '—';
    }
    if ($bytes >= 1024 ** 4) {
        return number_format($bytes / (1024 ** 4), 1, ',', ' ') . ' TiB';
    }
    if ($bytes >= 1024 ** 3) {
        return number_format($bytes / (1024 ** 3), 1, ',', ' ') . ' GiB';
    }
    if ($bytes >= 1024 ** 2) {
        return number_format($bytes / (1024 ** 2), 1, ',', ' ') . ' MiB';
    }
    return number_format($bytes / 1024, 0, ',', ' ') . ' KiB';
}

function format_time(?int $timestamp): string
{
    return $timestamp ? date('Y-m-d H:i', $timestamp) : '—';
}

function page_url(int $page, string $query, string $source): string
{
    $parameters = ['page' => $page];
    if ($query !== '') {
        $parameters['q'] = $query;
    }
    if ($source !== '') {
        $parameters['source'] = $source;
    }
    return '?' . http_build_query($parameters);
}

$cacheNotice = '';
try {
    $cache = load_provider_data($services);
} catch (RuntimeException $exception) {
    $cache = read_cache();
    if ($cache === []) {
        $cache = ['fetched_at' => null, 'providers' => []];
    }
    $cacheNotice = $exception->getMessage();
}

$providers = $cache['providers'] ?? [];
$hubs = merge_hubs($services, $providers);
$rawQuery = $_GET['q'] ?? '';
$query = trim(is_string($rawQuery) ? $rawQuery : '');
$query = substr($query, 0, 100);
$rawSource = $_GET['source'] ?? '';
$selectedSource = is_string($rawSource) ? $rawSource : '';
$knownSources = array_column($services, 'id');
if (!in_array($selectedSource, $knownSources, true)) {
    $selectedSource = '';
}

$sourceNames = [];
foreach ($services as $service) {
    $sourceNames[$service['id']] = $service['name'];
}
$selectedServiceName = $sourceNames[$selectedSource] ?? '';
$filteredHubs = array_values(array_filter($hubs, static function (array $hub) use ($query, $selectedServiceName): bool {
    if ($selectedServiceName !== '' && !in_array($selectedServiceName, $hub['sources'], true)) {
        return false;
    }
    if ($query === '') {
        return true;
    }

    $haystack = implode(' ', [
        $hub['name'],
        $hub['address'],
        $hub['description'],
        $hub['country'],
        $hub['software'],
        implode(' ', $hub['sources']),
    ]);
    return stripos($haystack, $query) !== false;
}));
usort($filteredHubs, static function (array $left, array $right): int {
    return ($right['users'] ?? -1) <=> ($left['users'] ?? -1);
});

$pageSize = 25;
$totalHubs = count($filteredHubs);
$pageCount = max(1, (int) ceil($totalHubs / $pageSize));
$rawPage = $_GET['page'] ?? 1;
$requestedPage = is_scalar($rawPage) ? (int) $rawPage : 1;
$page = min(max(1, $requestedPage), $pageCount);
$pageHubs = array_slice($filteredHubs, ($page - 1) * $pageSize, $pageSize);
$onlineCount = count(array_filter($hubs, static fn (array $hub): bool => strcasecmp($hub['status'], 'Online') === 0));
$availableServices = count(array_filter($providers, static fn (array $provider): bool => in_array($provider['status'] ?? '', ['ok', 'stale'], true)));

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Zbiorcza lista publicznych hubów Direct Connect z kilku serwisów hublist.">
    <title>Hublist — publiczne huby Direct Connect</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #182230;
            --muted: #596779;
            --line: #dce3ec;
            --surface: #fff;
            --background: #f3f6fa;
            --accent: #145cc5;
            --good: #197446;
            --warn: #875800;
            --bad: #a32929;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--background); color: var(--ink); font: 16px/1.5 system-ui, sans-serif; }
        header { background: #11253d; color: #fff; padding: 2.5rem max(1rem, calc((100% - 1180px) / 2)); }
        header h1 { margin: 0 0 .35rem; font-size: clamp(2rem, 5vw, 3rem); }
        header p { max-width: 720px; margin: 0; color: #d7e2f0; }
        main { max-width: 1180px; margin: 1.5rem auto; padding: 0 1rem 3rem; }
        .stats, .providers, .panel { margin-bottom: 1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); overflow: hidden; }
        .stat { padding: 1rem 1.25rem; }
        .stat + .stat { border-left: 1px solid var(--line); }
        .stat strong { display: block; font-size: 1.6rem; }
        .muted, small { color: var(--muted); }
        .providers { padding: 1rem 1.25rem; }
        .providers h2, .panel h2 { margin: 0 0 .65rem; font-size: 1.1rem; }
        .source-list { display: flex; flex-wrap: wrap; gap: .5rem; }
        .source { display: inline-block; border: 1px solid var(--line); border-radius: 999px; padding: .25rem .7rem; color: var(--ink); text-decoration: none; font-size: .9rem; }
        .source.ok { border-color: #b5dec8; color: var(--good); }
        .source.stale { border-color: #e7d1a5; color: var(--warn); }
        .source.error { border-color: #e4bbbb; color: var(--bad); }
        .source-note { margin: .8rem 0 0; font-size: .88rem; }
        .notice { margin-bottom: 1rem; padding: .8rem 1rem; border-left: 4px solid var(--warn); background: #fff8e8; }
        .panel { padding: 1rem 1.25rem; }
        form { display: flex; flex-wrap: wrap; gap: .6rem; margin-bottom: 1rem; }
        input, select, button { min-height: 42px; border: 1px solid #aebaca; border-radius: 7px; padding: .55rem .75rem; font: inherit; }
        input[type="search"] { flex: 1 1 260px; }
        button { cursor: pointer; background: var(--accent); color: #fff; border-color: var(--accent); }
        button.copy { min-height: 32px; padding: .2rem .55rem; color: var(--accent); background: #fff; border-color: var(--line); font-size: .85rem; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 820px; }
        th, td { border-bottom: 1px solid var(--line); padding: .7rem .55rem; text-align: left; vertical-align: top; }
        th { color: var(--muted); font-size: .83rem; text-transform: uppercase; letter-spacing: .03em; }
        td small { display: block; max-width: 440px; margin-top: .2rem; }
        .address { overflow-wrap: anywhere; font-family: ui-monospace, monospace; font-size: .9rem; }
        .status-online { color: var(--good); font-weight: 650; }
        .status-other { color: var(--muted); }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 1rem; }
        .pagination a { color: var(--accent); text-decoration: none; }
        footer { max-width: 1180px; margin: 0 auto; padding: 0 1rem 2rem; color: var(--muted); font-size: .9rem; }
        @media (max-width: 600px) {
            header { padding-top: 1.7rem; padding-bottom: 1.7rem; }
            .stats { grid-template-columns: 1fr; }
            .stat + .stat { border-left: 0; border-top: 1px solid var(--line); }
            .providers, .panel { padding: .85rem; }
        }
    </style>
</head>
<body>
<header>
    <h1>Hublist</h1>
    <p>Publiczne huby Direct Connect z kilku niezależnych serwisów w jednej, przeszukiwalnej liście. Duplikaty są łączone według adresu huba.</p>
</header>
<main>
    <?php if ($cacheNotice !== ''): ?>
        <div class="notice"><?= escape_html($cacheNotice) ?><?php if ($hubs !== []): ?> Wyświetlam dostępną kopię danych.<?php endif; ?></div>
    <?php endif; ?>
    <section class="stats" aria-label="Podsumowanie">
        <div class="stat"><strong><?= number_format($totalHubs, 0, ',', ' ') ?></strong><span class="muted">hubów spełnia filtr</span></div>
        <div class="stat"><strong><?= number_format($onlineCount, 0, ',', ' ') ?></strong><span class="muted">oznaczonych jako online przez źródła</span></div>
        <div class="stat"><strong><?= $availableServices ?> / <?= count($services) ?></strong><span class="muted">dostępnych źródeł</span></div>
    </section>

    <section class="providers" aria-labelledby="sources-heading">
        <h2 id="sources-heading">Źródła list</h2>
        <div class="source-list">
            <?php foreach ($services as $service):
                $provider = $providers[$service['id']] ?? [];
                $status = $provider['status'] ?? 'error';
                $statusText = $status === 'ok' ? 'aktualne'
                    : ($status === 'stale' ? 'kopia zapasowa' : 'niedostępne');
                ?>
                <a class="source <?= escape_html($status) ?>" href="<?= escape_html($service['url']) ?>" target="_blank" rel="noopener noreferrer">
                    <?= escape_html($service['name']) ?> — <?= escape_html($statusText) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="source-note muted">Dane są pobierane równolegle i buforowane przez 15 minut. Przy awarii źródła może być pokazana jego kopia z ostatniej doby. Status online pochodzi od dostawcy listy i nie jest niezależnym testem połączenia.</p>
        <?php foreach ($services as $service):
            $provider = $providers[$service['id']] ?? [];
            if (!isset($provider['error'])) {
                continue;
            }
            ?>
            <p class="source-note"><?= escape_html($service['name']) ?>: <?= escape_html($provider['error']) ?></p>
        <?php endforeach; ?>
    </section>

    <section class="panel" aria-labelledby="hubs-heading">
        <h2 id="hubs-heading">Lista hubów</h2>
        <form method="get">
            <input type="search" name="q" value="<?= escape_html($query) ?>" placeholder="Szukaj nazwy, adresu, kraju, opisu…" aria-label="Szukaj hubów">
            <select name="source" aria-label="Filtruj według źródła">
                <option value="">Wszystkie źródła</option>
                <?php foreach ($services as $service): ?>
                    <option value="<?= escape_html($service['id']) ?>" <?= $selectedSource === $service['id'] ? 'selected' : '' ?>>
                        <?= escape_html($service['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Szukaj</button>
        </form>

        <?php if ($pageHubs === []): ?>
            <p class="muted">Brak hubów pasujących do wyszukiwania. Jeśli źródła nie są dostępne, spróbuj ponownie później.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Hub</th><th>Adres</th><th>Kraj</th><th>Użytkownicy</th><th>Udostępnione</th><th>Status</th><th>Źródła</th></tr></thead>
                    <tbody>
                    <?php foreach ($pageHubs as $hub):
                        $addressLink = preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $hub['address'])
                            ? $hub['address']
                            : 'dchub://' . $hub['address'];
                        ?>
                        <tr>
                            <td><strong><?= escape_html($hub['name']) ?></strong><?php if ($hub['description'] !== ''): ?><small><?= escape_html($hub['description']) ?></small><?php endif; ?></td>
                            <td><a class="address" href="<?= escape_html($addressLink) ?>"><?= escape_html($hub['address']) ?></a><br><button class="copy" type="button" data-address="<?= escape_html($hub['address']) ?>">Kopiuj</button></td>
                            <td><?= escape_html($hub['country'] !== '' ? $hub['country'] : '—') ?></td>
                            <td><?= $hub['users'] === null ? '—' : number_format($hub['users'], 0, ',', ' ') ?></td>
                            <td><?= escape_html(format_bytes($hub['shared'])) ?></td>
                            <td class="<?= strcasecmp($hub['status'], 'Online') === 0 ? 'status-online' : 'status-other' ?>"><?= escape_html($hub['status']) ?></td>
                            <td><?= escape_html(implode(', ', $hub['sources'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <nav class="pagination" aria-label="Stronicowanie">
                <?php if ($page > 1): ?><a href="<?= escape_html(page_url($page - 1, $query, $selectedSource)) ?>">← Poprzednia</a><?php endif; ?>
                <span class="muted">Strona <?= $page ?> z <?= $pageCount ?> (<?= number_format($totalHubs, 0, ',', ' ') ?> hubów)</span>
                <?php if ($page < $pageCount): ?><a href="<?= escape_html(page_url($page + 1, $query, $selectedSource)) ?>">Następna →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<footer>
    Ostatnia próba aktualizacji: <?= escape_html(format_time(isset($cache['fetched_at']) ? (int) $cache['fetched_at'] : null)) ?>.
    Hublist łączy publiczne dane z zewnętrznych serwisów; szczegóły i aktualność wpisów zależą od ich dostawców.
</footer>
<script>
document.querySelectorAll('.copy').forEach(function (button) {
    button.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(button.dataset.address);
            button.textContent = 'Skopiowano';
        } catch (error) {
            button.textContent = 'Nie udało się skopiować';
        }
    });
});
</script>
</body>
</html>
