<?php
declare(strict_types=1);

const HUB_PROTOCOLS = ['ADC', 'ADCS', 'DCHUB', 'NMDC', 'NMDCS'];

function app_config(): array
{
    static $config;
    if ($config === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            http_response_code(503);
            exit('Hublist nie została skonfigurowana. Uruchom setup.php.');
        }
        $config = require $path;
    }
    return $config;
}

function protocol_badge_class(string $protocol): string
{
    return match ($protocol) {
        'ADC' => 'protocol-adc',
        'ADCS' => 'protocol-adcs',
        'DCHUB' => 'protocol-dchub',
        'NMDCS' => 'protocol-nmdcs',
        default => 'protocol-nmdc',
    };
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = app_config();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'], (int) $config['db_port'], $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    upgrade_download_catalog($pdo);
    upgrade_hub_details($pdo);
    upgrade_visitor_tracking($pdo);
    upgrade_navigation_menu($pdo);
    enforce_ip_ban($pdo);
    return $pdo;
}

function upgrade_navigation_menu(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS navigation_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        item_key VARCHAR(40) NULL UNIQUE,
        label VARCHAR(60) NOT NULL,
        url VARCHAR(500) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_builtin TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_navigation_visible (is_active,sort_order,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $items = [
        ['home', 'Hublista', 'index.php', 10],
        ['search', 'Wyszukiwarka', 'search.php', 15],
        ['stats', 'Statystyki', 'stats.php', 20],
        ['add_hub', 'Dodaj hub', 'add_hub.php', 30],
        ['download', 'Pobieralnia', 'download.php', 40],
        ['feed', 'Feed XML', 'feed.php?format=xml', 50],
        ['about', 'O nas', 'about.php', 60],
        ['faq', 'FAQ', 'faq.php', 70],
        ['rules', 'Regulamin', 'rules.php', 80],
        ['admin', 'Administracja', 'admin.php', 90],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO navigation_items (item_key,label,url,sort_order,is_active,is_builtin) VALUES (?,?,?,?,1,1)');
    foreach ($items as [$key, $label, $url, $order]) {
        $insert->execute([$key, $label, $url, $order]);
    }
}

function navigation_items(bool $activeOnly = true): array
{
    $where = $activeOnly ? ' WHERE is_active=1' : '';
    return db()->query('SELECT id,item_key,label,url,sort_order,is_active,is_builtin FROM navigation_items'
        . $where . ' ORDER BY sort_order,id')->fetchAll();
}

function valid_navigation_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F]/', $url)) {
        return false;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return true;
    }
    if (preg_match('/^[A-Za-z0-9_-]+\.php(?:\?[A-Za-z0-9_=&.%+-]*)?$/', $url)) {
        return true;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
}

function upgrade_visitor_tracking(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_sessions (
        session_key CHAR(64) NOT NULL PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        current_path VARCHAR(255) NOT NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        INDEX idx_visitors_active (last_seen),
        INDEX idx_visitors_ip (ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        session_key CHAR(64) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        path VARCHAR(255) NOT NULL,
        visited_at DATETIME NOT NULL,
        INDEX idx_visitor_history_date (visited_at),
        INDEX idx_visitor_history_ip_date (ip_address, visited_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_bans (
        ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
        reason VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        INDEX idx_ip_bans_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function visitor_request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = is_string($path) && str_starts_with($path, '/') ? $path : '/';
    $path = preg_replace('/[\x00-\x20\x7F]/', '', $path) ?? '/';
    $path = preg_replace_callback('/[^\x21-\x7E]/', static fn(array $match): string => rawurlencode($match[0]), $path) ?? '/';
    $parts = [];
    foreach (['id', 'page'] as $key) {
        $value = filter_var(is_string($_GET[$key] ?? null) ? $_GET[$key] : '', FILTER_VALIDATE_INT);
        if ($value !== false && $value > 0) {
            $parts[$key] = (string) $value;
        }
    }
    $category = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
    if (preg_match('/^[a-z0-9_-]{1,24}$/', $category)) {
        $parts['category'] = $category;
    }
    if ($parts !== []) {
        $path .= '?' . http_build_query($parts);
    }
    return substr($path, 0, 255);
}

function normalize_ip_address(string $ip): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $packed = inet_pton($ip);
    if ($packed === false) {
        return null;
    }
    $normalized = inet_ntop($packed);
    return $normalized === false ? null : $normalized;
}

function enforce_ip_ban(PDO $pdo): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['admin.php', 'setup.php'], true) || PHP_SAPI === 'cli') {
        return;
    }
    $ip = normalize_ip_address((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === null) {
        return;
    }
    $ban = $pdo->prepare('SELECT 1 FROM ip_bans WHERE ip_address=? LIMIT 1');
    $ban->execute([$ip]);
    if ($ban->fetchColumn()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>Dostęp zablokowany</title>'
            . '<p>Dostęp do serwisu z tego adresu IP został zablokowany.</p></html>';
        exit;
    }
}

function track_visitor_request(): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    if ($script === 'admin.php' || $script === 'setup.php' || PHP_SAPI === 'cli'
        || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;
    }

    $ip = normalize_ip_address((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === null) {
        return;
    }

    $pdo = db();
    start_app_session();
    $config = app_config();
    $sessionKey = hash_hmac('sha256', session_id(), $config['db_password'] . $config['db_name']);
    $path = visitor_request_path();
    $now = gmdate('Y-m-d H:i:s');
    $session = $pdo->prepare(
        'INSERT INTO visitor_sessions (session_key,ip_address,current_path,first_seen,last_seen)
         VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address),
         current_path=VALUES(current_path),last_seen=VALUES(last_seen)'
    );
    $session->execute([$sessionKey, $ip, $path, $now, $now]);
    $history = $pdo->prepare('INSERT INTO visitor_history (session_key,ip_address,path,visited_at) VALUES (?,?,?,?)');
    $history->execute([$sessionKey, $ip, $path, $now]);

    $cleanup = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='visitor_cleanup_at'")->fetchColumn();
    if (!is_string($cleanup) || strtotime($cleanup . ' UTC') < time() - 86400) {
        $pdo->exec('DELETE FROM visitor_history WHERE visited_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        $pdo->exec('DELETE FROM visitor_sessions WHERE last_seen < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        $saveCleanup = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('visitor_cleanup_at',?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $saveCleanup->execute([$now]);
    }
}

function upgrade_hub_details(PDO $pdo): void
{
    static $checked = false;
    if ($checked || !$pdo->query("SHOW TABLES LIKE 'hubs'")->fetchColumn()) {
        return;
    }
    $checked = true;
    $columns = [
        'icon_url' => "ALTER TABLE hubs ADD icon_url VARCHAR(500) NULL",
        'country_source' => "ALTER TABLE hubs ADD country_source VARCHAR(12) NULL",
        'country_ip' => "ALTER TABLE hubs ADD country_ip VARCHAR(45) NULL",
        'owner_token_hash' => "ALTER TABLE hubs ADD owner_token_hash CHAR(64) NULL",
        'owner_ping_at' => "ALTER TABLE hubs ADD owner_ping_at DATETIME NULL",
    ];
    foreach ($columns as $name => $statement) {
        if (!$pdo->query("SHOW COLUMNS FROM hubs LIKE " . $pdo->quote($name))->fetch()) {
            $pdo->exec($statement);
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS hub_owner_ping_limits (
        ip_hash CHAR(64) NOT NULL PRIMARY KEY,
        last_ping_at DATETIME NOT NULL,
        INDEX idx_owner_ping_limits_time (last_ping_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function download_categories(): array
{
    $categories = [];
    foreach (download_category_details() as $category) {
        $categories[$category['category_key']] = $category['name'];
    }
    return $categories;
}

function download_category_details(): array
{
    return db()->query('SELECT category_key,name,description,sort_order FROM download_categories ORDER BY sort_order,name')->fetchAll();
}

function safe_download_url(string $url): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && ($scheme === 'https' || ($scheme === 'http' && $host === 'www.ptokax.org'));
}

function upgrade_download_catalog(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    if (!$pdo->query("SHOW TABLES LIKE 'app_settings'")->fetchColumn()
        || !$pdo->query("SHOW TABLES LIKE 'downloads'")->fetchColumn()) {
        return;
    }
    $checked = true;

    $column = $pdo->query("SHOW COLUMNS FROM downloads LIKE 'category'")->fetch();
    if (is_array($column) && str_starts_with(strtolower((string) $column['Type']), 'enum(')) {
        $pdo->exec("ALTER TABLE downloads MODIFY category VARCHAR(24) NOT NULL DEFAULT 'client'");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS download_categories (
        category_key VARCHAR(24) NOT NULL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $countColumn = $pdo->query("SHOW COLUMNS FROM downloads LIKE 'download_count'")->fetch();
    if (!is_array($countColumn)) {
        $pdo->exec('ALTER TABLE downloads ADD download_count BIGINT UNSIGNED NOT NULL DEFAULT 0');
    }
    $seedCategories = [
        ['server', 'Serwery hubów', 'Oprogramowanie do uruchamiania serwerów Direct Connect.', 10],
        ['client', 'Klienci Direct Connect', 'Klienty do łączenia się z hubami Direct Connect.', 20],
        ['script', 'Skrypty Lua i dodatki', 'Skrypty, rozszerzenia i dodatki do klientów oraz serwerów.', 30],
        ['other', 'Inne narzędzia', 'Pozostałe narzędzia związane z Direct Connect.', 40],
    ];
    $seedCategory = $pdo->prepare('INSERT IGNORE INTO download_categories (category_key,name,description,sort_order) VALUES (?,?,?,?)');
    foreach ($seedCategories as $seed) {
        $seedCategory->execute($seed);
    }

    $marker = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='catalog_categories_v6'")->fetchColumn();
    if ($marker !== false) {
        return;
    }

    $items = [
        ['client', 'AirDC++', 'https://github.com/airdcpp/airdcpp-windows/releases', 'Oficjalne wydania klienta AirDC++ dla Windows 10 i 11. Klient webowy jest dostępny na stronie projektu.', 'Windows, Linux przez klient webowy'],
        ['client', 'DC++', 'https://dcpp.net/download', 'Klient Direct Connect dla systemu Windows.', 'Windows'],
        ['client', 'EiskaltDC++', 'https://github.com/eiskaltdcpp/eiskaltdcpp/releases', 'Otwartoźródłowy klient Direct Connect z wydaniami i kodem źródłowym.', 'Linux, Windows, macOS'],
        ['client', 'FlylinkDC++ (repozytorium społeczności)', 'https://github.com/pavel-pimenov/flylinkdc-r6xx', 'Społecznościowe repozytorium kodu klienta FlylinkDC++. Sprawdź instrukcje kompilacji i dostępność wydań.', 'Windows'],
        ['client', 'Jucy', 'https://github.com/Quicksilver666/jucy', 'Klient Direct Connect oparty na Javie; repozytorium społecznościowe, sprawdź zgodność i wydania.', 'Java, wiele systemów'],
        ['client', 'ShakesPeer', 'https://github.com/rufuscoder/Shakespeer', 'Wieloplatformowy klient Direct Connect z repozytorium społecznościowym. Sprawdź dostępność wydań i wymagania systemowe.', 'Wiele platform'],
        ['client', 'ncdc', 'https://dev.yorhel.nl/ncdc', 'Lekki klient Direct Connect z interfejsem tekstowym; obsługuje ADC i NMDC. Projekt jest utrzymywany pasywnie.', 'Linux, BSD, macOS, Android'],
        ['client', 'ApexDC++ (wersja archiwalna)', 'https://sourceforge.net/projects/apexdc/', 'Klasyczny klient Direct Connect oparty na DC++ i StrongDC++. Projekt archiwalny; sprawdź zgodność z aktualnym Windows i skanuj pobrane pliki.', 'Windows, archiwalny'],
        ['client', 'TorrentDC++ (archiwalny)', 'https://sourceforge.net/projects/p2ptorrentdc/', 'Historyczny klient Windows obsługujący Direct Connect, ADC i BitTorrent. Przed użyciem sprawdź dostępność oraz bezpieczeństwo plików projektu.', 'Windows, archiwalny'],
        ['client', 'FearDC', 'https://github.com/RoLex/feardc', 'Fork klienta DC++ z obsługą TLS dla NMDC; repozytorium zawiera kod i informacje o wydaniach.', 'Windows'],
        ['client', 'StrongDC++ (projekt historyczny)', 'https://en.wikipedia.org/wiki/DC%2B%2B', 'Historyczny klient i baza dla wielu forków DC++. Link prowadzi do przeglądu klientów; nie wskazuje zweryfikowanego instalatora.', 'Windows, archiwalny'],
        ['client', 'Open Direct Connect (klient historyczny)', 'https://en.wikipedia.org/wiki/Direct_Connect_(protocol)', 'Historyczny klient Direct Connect, niekompletny i nieutrzymywany. Link prowadzi do opisu protokołu; nie znaleziono zweryfikowanego, bezpiecznego wydania do pobrania.', 'Archiwalny'],
        ['server', 'Verlihub', 'https://github.com/Verlihub/verlihub/releases', 'Serwer hubów NMDC dla systemu Linux; obsługuje rozszerzenia Lua i Python.', 'Linux'],
        ['server', 'Verlihub — kod i dokumentacja', 'https://github.com/Verlihub/verlihub', 'Oficjalne repozytorium serwera, dokumentacja, wtyczki i skrypty.', 'Linux'],
        ['server', 'ADCH++', 'https://sourceforge.net/projects/adchpp/files/', 'Serwer hubów ADC; archiwum wydań SourceForge. Sprawdź aktualność, zgodność i system operacyjny przed instalacją.', 'Linux, Windows'],
        ['server', 'µHub (uhub)', 'https://github.com/janvidar/uhub', 'Lekki, wysokowydajny serwer hubów ADC z otwartym kodem źródłowym.', 'Linux, Unix'],
        ['server', 'Luadch', 'https://github.com/luadch/luadch', 'Serwer hubów ADC napisany w Lua; sprawdź wymagania i aktualność projektu przed wdrożeniem.', 'Linux, Unix'],
        ['server', 'Luadch-ng', 'https://github.com/luadch-ng/luadch-ng', 'Nowszy, rozwijany fork serwera Luadch dla ADC/ADCS. Sprawdź dokumentację i wymagania projektu.', 'Windows, Linux'],
        ['server', 'go-dcpp', 'https://github.com/direct-connect/go-dcpp', 'Hybrydowy serwer Direct Connect napisany w Go.', 'Windows, Linux'],
        ['server', 'YnHub (archiwalny)', 'https://portableapps.com/node/25130', 'Serwer hubów DC++ dla Windows. Rozwój zakończono w 2008 r.; strona zawiera historyczny opis, nie zweryfikowany instalator.', 'Windows, archiwalny'],
        ['server', 'ADCHub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: adchub-0.3-beta-release.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Admi Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: admihub-v0.028a.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Black DC (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: bdch-v0.20-alpha1-R0.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'DC Galaxy Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: DC-Galaxy.pr-0.6.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'DCH Pro (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: dchpro_0.0.0.4-alpha.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Dev Direct Connect (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: DDCH_svn292fixed_mod_by_sonickillu.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Digital Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: digitalhub-2.0.7.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Direct Connect Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: dchubsetup-20.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Dot Net Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: Dot-Net-Hub-1.0-beta.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Drakes DcPhantom (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: Phantom.Alpha.4.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'DSHub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: DSHub-Windows-Installer-Theta-RC4.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, Windows'],
        ['server', 'HexHub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: HeXHub_EN_5.01hFirewall1.08.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'LatHack (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: lathack_dc_hub_v0.5a.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'NAAF DC Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: naaf0.7.25.tar.tar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Nitro (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: Nitro_Hub_build_0.8.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'ODCH Console (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: ODCHSetup-0.4.2.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Open DC (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: opendchub-0.7.15.tar.gz. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, Unix/Linux'],
        ['server', 'RDC (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: RDCSetup-2.4.1.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'SBSoft EA Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: sbsoft-ea_hub.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'SDCH (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: SDCH0342.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'TamilHub Server (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: tamilhub_server_v1.0.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Underground DC Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: undergrounddc-hub.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'V-HuB (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: v-hub_1.0.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'X-Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: xHub_v0.2.5.6.tar.gz. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, Unix/Linux'],
        ['server', 'XS Hub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: xshub-r5.5.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Yabba (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: yabba0.9.72.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Yadch (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: yadch0.95.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'YHub (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: yhub388t1_ita_by_PeppezZ.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Zefir HUBsoft++ (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: zhpp.0.4.siberia.rar. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'ZpoC Room Server (archiwalny)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Historyczny hubsoft z listy cstrike.ro; wskazane archiwum: zpoc_room_server1.4.124.zip. Stara wersja, pobieranie i bezpieczeństwo niezweryfikowane.', 'Archiwalny, platforma niezweryfikowana'],
        ['server', 'Aquila DC (nazwa historyczna, niezweryfikowana)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Nazwa podana przez administratora jako historyczny hubsoft. Nie znaleziono potwierdzonego wpisu ani pliku na wskazanej liście.', 'Archiwalny, wydanie niezweryfikowane'],
        ['server', 'Verigio — Virtual Network Hub (nazwa historyczna, niezweryfikowana)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Nazwa podana przez administratora jako historyczny hubsoft. Nie znaleziono potwierdzonego wpisu ani pliku na wskazanej liście.', 'Archiwalny, wydanie niezweryfikowane'],
        ['server', 'Hub-Link (nazwa historyczna, niezweryfikowana)', 'https://www.cstrike.ro/download_dchub_servers.php', 'Nazwa podana przez administratora jako historyczny hubsoft. Nie znaleziono potwierdzonego wpisu ani pliku na wskazanej liście.', 'Archiwalny, wydanie niezweryfikowane'],
        ['other', 'Octopus DC-Linker (łączenie hubów)', 'https://github.com/burek/Octopus-DC-Linker', 'Narzędzie NMDC napisane w PHP do łączenia kilku hubów w jedną sieć; to linker, a nie samodzielny hubsoft.', 'PHP'],
        ['server', 'PtokaX', 'http://www.ptokax.org/', 'Serwer hubów NMDC z obsługą Lua. Oficjalna strona jest dostępna przez HTTP; sprawdź plik i źródło przed instalacją.', 'Windows, Linux'],
        ['script', 'Skrypty Lua do PtokaX — jasmucrai', 'https://github.com/jasmucrai/ptokax-scripts', 'Archiwum społecznościowe z katalogami dla Lua 5.0.2 i 5.1. Stare skrypty mogą nie działać z aktualnym hubsoftem; sprawdź licencję i kod.', 'Lua 5.0/5.1, PtokaX'],
        ['script', 'Skrypty Lua do PtokaX — vy_scripts', 'https://github.com/vyvl/vy_scripts', 'Zestaw skryptów Lua do PtokaX. Sprawdź wymagania wersji, licencję i kod przed instalacją.', 'Lua, PtokaX'],
        ['script', 'Lua do EiskaltDC++', 'https://github.com/eiskaltdcpp/eiskaltdcpp/tree/master/data/luascripts', 'Skrypty dostarczane z klientem EiskaltDC++; zgodność zależy od wersji klienta.', 'Lua, EiskaltDC++'],
        ['script', 'Wtyczka Lua dla Verlihub', 'https://github.com/Verlihub/verlihub/tree/master/plugins/lua', 'Kod wtyczki Lua z oficjalnego repozytorium Verlihub; to komponent serwera, nie samodzielny hubsoft.', 'Lua, Verlihub, Linux'],
        ['other', 'Biblioteka list hubów Direct Connect', 'https://github.com/DCNF/Hublist', 'Otwartoźródłowy projekt narzędzia do pracy z listami hubów. Sprawdź README projektu.', 'Python'],
    ];
    $find = $pdo->prepare('SELECT id FROM downloads WHERE category=? AND name=? AND website=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO downloads (category,name,website,description,platform) VALUES (?,?,?,?,?)');
    foreach ($items as [$category, $name, $website, $description, $platform]) {
        $find->execute([$category, $name, $website]);
        if ($find->fetchColumn() === false) {
            $insert->execute([$category, $name, $website, $description, $platform]);
        }
    }
    $pdo->exec("UPDATE downloads SET website='https://sourceforge.net/projects/adchpp/files/' WHERE category='server' AND name='ADCH++' AND website LIKE 'https://github.com/ADCHpp/%'");
    $pdo->exec("UPDATE downloads SET website='http://www.ptokax.org/' WHERE category='server' AND name='PtokaX' AND website LIKE 'https://github.com/ptokax/%'");
    $pdo->exec("INSERT INTO app_settings (setting_key,setting_value) VALUES ('catalog_categories_v6','1')");
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('hublist_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function build_hublist_xml(array $hubs): string
{
    $columns = [
        'Name' => 'string',
        'Address' => 'string',
        'Description' => 'string',
        'Country' => 'string',
        'Users' => 'int',
        'Shared' => 'bytes',
        'Status' => 'string',
        'Minshare' => 'bytes',
        'Minslots' => 'int',
        'Maxhubs' => 'int',
        'Maxusers' => 'int',
        'Reliability' => 'string',
        'Rating' => 'string',
    ];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<Hublist Name="Hublist">' . "\n  <Hubs>\n    <Columns>\n";
    foreach ($columns as $name => $type) {
        $xml .= '      <Column Name="' . $name . '" Type="' . $type . "\" />\n";
    }
    $xml .= "    </Columns>\n";
    foreach ($hubs as $hub) {
        $attributes = [
            'Name' => (string) $hub['name'],
            'Address' => hub_address($hub),
            'Description' => (string) ($hub['description'] ?? ''),
            'Country' => (string) ($hub['country'] ?? ''),
            'Users' => (string) ($hub['online_users'] ?? 0),
            'Shared' => (string) ($hub['shared_bytes'] ?? ''),
            'Status' => ($hub['pinger_status'] ?? null) === 'online' ? 'Online' : 'Offline',
            'Minshare' => '',
            'Minslots' => '',
            'Maxhubs' => '',
            'Maxusers' => '',
            'Reliability' => '',
            'Rating' => '',
        ];
        $xml .= '    <Hub';
        foreach ($attributes as $name => $value) {
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
            $xml .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $xml .= " />\n";
    }
    return $xml . "  </Hubs>\n</Hublist>\n";
}

function post_string(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_string($value) || is_numeric($value) ? (string) $value : '';
}

function utf8_length(string $value): ?int
{
    if (preg_match_all('/./us', $value, $matches) === false) {
        return null;
    }
    return count($matches[0]);
}

function utf8_truncate(string $value, int $maximum): string
{
    if (preg_match('/^.{0,' . $maximum . '}/us', $value, $matches) !== 1) {
        return '';
    }
    return $matches[0];
}

function csrf_token(): string
{
    start_app_session();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    start_app_session();
    $provided = $_POST['csrf'] ?? '';
    if (!is_string($provided) || !hash_equals($_SESSION['csrf'] ?? '', $provided)) {
        http_response_code(400);
        exit('Sesja formularza wygasła. Wróć do strony i spróbuj ponownie.');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function protocol_default_port(string $protocol): int
{
    return match ($protocol) {
        'ADC' => 1511,
        'ADCS' => 1511,
        'NMDCS' => 1411,
        default => 411,
    };
}

function valid_host(string $host): bool
{
    $host = trim($host);
    if ($host === '' || strlen($host) > 253 || preg_match('/[\s\/@?#]/', $host)) {
        return false;
    }
    $ip = trim($host, '[]');
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    if (strcasecmp($host, 'localhost') === 0 || !str_contains($host, '.')) {
        return false;
    }
    return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host);
}

function normalize_hub_input(array $input): array
{
    $protocol = strtoupper(trim(post_string($input, 'protocol')));
    $host = trim(post_string($input, 'host'));
    $portRaw = trim(post_string($input, 'port'));
    $port = $portRaw === '' ? protocol_default_port($protocol) : filter_var($portRaw, FILTER_VALIDATE_INT);
    $name = trim(post_string($input, 'name'));
    $country = strtoupper(trim(post_string($input, 'country')));
    $description = trim(post_string($input, 'description'));
    $software = trim(post_string($input, 'software'));
    $website = trim(post_string($input, 'website'));
    $iconUrl = trim(post_string($input, 'icon_url'));

    if (!in_array($protocol, HUB_PROTOCOLS, true)) {
        throw new InvalidArgumentException('Wybierz poprawny protokół ADC, ADCS, DCHUB, NMDC lub NMDCS.');
    }
    if (!valid_host($host)) {
        throw new InvalidArgumentException('Podaj poprawną publiczną nazwę hosta lub publiczny adres IP huba.');
    }
    if (!is_int($port) || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Port musi być liczbą od 1 do 65535.');
    }
    $nameLength = utf8_length($name);
    if ($name === '' || $nameLength === null || $nameLength > 150) {
        throw new InvalidArgumentException('Nazwa huba jest wymagana i może mieć maksymalnie 150 znaków.');
    }
    foreach ([
        [$description, 5000, 'Opis huba'],
        [$software, 120, 'Nazwa oprogramowania'],
        [$website, 500, 'Adres strony WWW'],
        [$iconUrl, 500, 'Adres ikony huba'],
    ] as [$value, $maximum, $label]) {
        $length = utf8_length($value);
        if ($length === null || $length > $maximum) {
            throw new InvalidArgumentException($label . ' przekracza dopuszczalną długość.');
        }
    }
    if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
        throw new InvalidArgumentException('Kraj musi być dwuliterowym kodem ISO, np. PL.');
    }
    foreach (['website' => $website] as $label => $url) {
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https')) {
            throw new InvalidArgumentException('Adres strony WWW musi być prawidłowym adresem HTTPS.');
        }
    }
    if ($iconUrl !== '' && (!filter_var($iconUrl, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($iconUrl, PHP_URL_SCHEME)) !== 'https')) {
        throw new InvalidArgumentException('Adres ikony huba musi być prawidłowym adresem HTTPS.');
    }

    return [
        'name' => $name,
        'protocol' => $protocol,
        'host' => strtolower($host),
        'port' => $port,
        'country' => $country !== '' ? $country : null,
        'description' => $description !== '' ? $description : null,
        'software' => $software !== '' ? $software : null,
        'website' => $website !== '' ? $website : null,
        'icon_url' => $iconUrl !== '' ? $iconUrl : null,
    ];
}

function hub_address(array $hub): string
{
    $scheme = match ($hub['protocol']) {
        'ADCS' => 'adcs',
        'ADC' => 'adc',
        'NMDCS' => 'nmdcs',
        'DCHUB' => 'dchub',
        default => 'nmdc',
    };
    return $scheme . '://' . $hub['host'] . ':' . $hub['port'];
}

function flag_emoji(?string $country): string
{
    if (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country)) {
        return '🌐';
    }
    return html_entity_decode(
        '&#' . (127397 + ord($country[0])) . ';&#' . (127397 + ord($country[1])) . ';',
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
}

function pretty_bytes(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return '—';
    }
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, $unit === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
}

function ping_status_text(?string $status): string
{
    return match ($status) {
        'online' => 'Online',
        'offline' => 'Offline',
        'tls_error' => 'Błąd certyfikatu TLS',
        'auth_required' => 'Wymaga autoryzacji',
        'rejected' => 'Hub odrzucił pingera',
        'redirected' => 'Hub przekierowuje',
        'dns_error' => 'Błąd DNS',
        'invalid_address' => 'Niepubliczny adres',
        'protocol_error' => 'Błąd protokołu',
        default => $status ? 'Niezweryfikowany' : 'Brak pomiaru',
    };
}

function utc_datetime(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return 'Brak danych';
    }
    try {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i');
    } catch (Throwable) {
        return 'Brak danych';
    }
}

function admin_logged_in(): bool
{
    start_app_session();
    return isset($_SESSION['admin_id'], $_SESSION['admin_name']);
}

function require_admin(): void
{
    if (!admin_logged_in()) {
        redirect('admin.php');
    }
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data: https:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

function page_start(string $title): void
{
    security_headers();
    track_visitor_request();
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $canonicalPath = $script === 'index.php' ? '/' : '/' . rawurlencode($script);
    $canonicalParams = [];
    if ($script === 'hub.php') {
        $hubId = filter_var(is_string($_GET['id'] ?? null) ? $_GET['id'] : '', FILTER_VALIDATE_INT);
        if ($hubId && $hubId > 0) {
            $canonicalParams['id'] = $hubId;
        }
    } elseif ($script === 'download.php') {
        $category = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
        if ($category !== '' && array_key_exists($category, download_categories())) {
            $canonicalParams['category'] = $category;
        }
    }
    $canonicalUrl = 'https://hublist.pl' . $canonicalPath
        . ($canonicalParams !== [] ? '?' . http_build_query($canonicalParams) : '');
    ?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Polska hublista Direct Connect Hublist.pl — katalog hubów, statusy, feed XML i społeczność DC.">
        <link rel="alternate" type="application/xml" href="feed.php?format=xml">
        <meta name="theme-color" content="#102944">
            <link rel="canonical" href="<?= e($canonicalUrl) ?>">
            <title><?= e($title) ?> — Hublist.pl</title>
        <style>
            :root{color-scheme:light;--ink:#162338;--muted:#65758b;--line:#dce4ee;--paper:#fff;--bg:#f2f5f9;--blue:#174f7b;--green:#147341;--amber:#855700;--red:#b42332;--poland-red:#d4213d}
            *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
            a{color:var(--blue)}.top{background:#102944;color:#fff;border-bottom:3px solid var(--poland-red)}.top-inner,main,footer{width:min(1240px,100% - 32px);margin-inline:auto}
            .top-inner{padding:16px 0;display:flex;justify-content:space-between;align-items:center;gap:20px}.brand{display:inline-flex;align-items:center;gap:10px;font-weight:850;font-size:1.45rem;color:#fff;text-decoration:none;letter-spacing:.01em}.brand-mark{position:relative;display:inline-block;width:30px;height:22px;border-radius:4px;background:linear-gradient(to bottom,#fff 0 50%,var(--poland-red) 50% 100%);box-shadow:0 0 0 1px #ffffff77;transform:skew(-8deg)}.brand-mark:after{content:"";position:absolute;left:8px;right:8px;top:5px;height:12px;border-left:2px solid #173653;border-right:2px solid #173653;opacity:.9}.brand-domain{color:#f1b4bf;font-weight:650}.nav{display:flex;gap:18px;flex-wrap:wrap}.nav a{color:#e6eef8;text-decoration:none}.nav a:hover{color:#fff;text-decoration:underline;text-decoration-color:var(--poland-red);text-decoration-thickness:2px;text-underline-offset:5px}
            main{padding-top:24px;padding-bottom:48px}h1{font-size:clamp(1.7rem,4vw,2.5rem);line-height:1.2;margin:.1rem 0 .45rem}h2{margin:0 0 12px;font-size:1.2rem}
            .hero{padding:22px 0 18px}.hero p{color:var(--muted);margin:0}.home-hero{position:relative;overflow:hidden;margin-top:2px;padding:24px 26px;border:1px solid var(--line);border-radius:13px;background:linear-gradient(110deg,#fff 0%,#fff 76%,#fff3f5 100%)}.home-hero:after{content:"";position:absolute;right:0;top:0;width:9px;height:100%;background:linear-gradient(to bottom,#fff 0 50%,var(--poland-red) 50% 100%)}.hero-kicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:8px;color:#7c2639;font-size:.75rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.hero-kicker:before{content:"";width:22px;height:14px;border-radius:2px;background:linear-gradient(to bottom,#fff 0 50%,var(--poland-red) 50% 100%);box-shadow:0 0 0 1px #d7dce4}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}
            .card,.panel{border:1px solid var(--line);border-radius:12px;background:var(--paper)}.card{padding:14px 16px}.card strong{display:block;font-size:1.65rem}.muted,small{color:var(--muted)}
            .panel{padding:18px;margin:14px 0}.actions,form{display:flex;gap:9px;flex-wrap:wrap;align-items:center}input,select,textarea{font:inherit;border:1px solid #bac5d3;border-radius:7px;background:#fff;padding:9px 11px;min-height:42px;color:var(--ink)}input[type=search]{flex:1 1 240px}textarea{width:100%;min-height:92px}button,.button{display:inline-block;border:1px solid var(--blue);border-radius:7px;background:var(--blue);color:white;padding:9px 14px;font:inherit;text-decoration:none;cursor:pointer}.button.secondary,button.secondary{background:white;color:var(--blue)}
            .table-wrap{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1050px}th,td{padding:10px 9px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{font-size:.8rem;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}td small{display:block}.proto,.pill{display:inline-block;border-radius:20px;background:#eaf0f8;color:#233b5d;padding:2px 8px;font-size:.8rem;font-weight:650;white-space:nowrap}.ok{color:var(--green);font-weight:700}.bad{color:var(--red);font-weight:700}.wait{color:var(--amber);font-weight:700}.address{font-family:ui-monospace,monospace;overflow-wrap:anywhere}.detail{margin-top:2px}.note{padding:11px 14px;background:#fff8e7;border-left:4px solid #d1a23b;border-radius:4px}.error{padding:11px 14px;background:#fff0ef;border-left:4px solid var(--red);border-radius:4px}.success{padding:11px 14px;background:#edf9f1;border-left:4px solid var(--green);border-radius:4px}
            .hub-flag{font-size:1.25rem;vertical-align:middle;margin-right:5px}.hub-name{font-weight:750;text-decoration:none;color:var(--ink)}.hub-name:hover{text-decoration:underline}.status-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin:0 7px 0 1px;vertical-align:middle}.status-dot.is-online{background:#19a35b;box-shadow:0 0 0 3px #e4f5eb}.status-dot.is-offline{background:#d43b3b;box-shadow:0 0 0 3px #fdeaea}.protocol-icon{display:inline-flex;align-items:center;gap:5px;border-radius:6px;padding:4px 7px;font-size:.76rem;font-weight:750;white-space:nowrap}.protocol-adc{background:#e8f3ff;color:#14588d}.protocol-adcs{background:#e9edff;color:#3d4d9e}.protocol-dchub{background:#fff1dc;color:#87540d}.protocol-nmdc{background:#e9f6ec;color:#27633a}.protocol-nmdcs{background:#f2e9ff;color:#653a92}.hub-icon{width:76px;height:76px;border-radius:14px;object-fit:cover;border:1px solid var(--line);background:#eaf0f8}.hub-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.hub-detail{border:1px solid var(--line);border-radius:9px;padding:12px}.hub-detail strong{display:block;font-size:.78rem;color:var(--muted);margin-bottom:4px}.hub-detail span{overflow-wrap:anywhere}.topic{font-size:1.1rem;padding:15px;background:#f5f8fb;border-radius:9px}
            .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field{display:grid;gap:4px}.field.full{grid-column:1/-1}.downloads{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}.download{border:1px solid var(--line);border-radius:10px;padding:15px}.download h3{margin:0}.empty{padding:15px;color:var(--muted)}.pager{display:flex;justify-content:center;gap:16px;padding-top:16px}.pager a{text-decoration:none;font-weight:650}footer{padding:0 0 26px;color:var(--muted);font-size:.9rem}.footer-brand{border-top:1px solid var(--line);padding-top:15px}.footer-rights{font-size:.82rem}.footer-rights strong{color:var(--ink)}
            @media(max-width:760px){.top-inner{align-items:flex-start;flex-direction:column}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.grid,.hub-detail-grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
        </style>
    </head>
    <body><div class="top"><div class="top-inner"><a class="brand" href="index.php" aria-label="Hublist.pl — polska hublista Direct Connect"><span class="brand-mark" aria-hidden="true"></span><span>Hublist<span class="brand-domain">.pl</span></span></a><nav class="nav" aria-label="Menu główne">
    <?php foreach (navigation_items() as $item): ?><a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a><?php endforeach; ?>
    </nav></div></div>
    <?php
}

function page_end(): void
{
    ?>
    <footer><div class="footer-brand"><p><strong>Hublist.pl</strong> — polska hublista Direct Connect. Łączymy społeczność, promujemy otwarte huby i wspieramy polską scenę DC.</p><p><a href="about.php">O serwisie</a> · <a href="faq.php">FAQ</a> · <a href="rules.php">Regulamin</a> · <a href="download.php">Pobieralnia</a> · <a href="admin.php">Administracja</a></p><p class="footer-rights">© <?= date('Y') ?> Hublist.pl. <strong>Wszelkie prawa zastrzeżone</strong> do oryginalnych treści, projektu graficznego, logo i układu serwisu. Kopiowanie lub ponowne publikowanie całości serwisu albo jego istotnych części wymaga zgody administratora. Nazwy, znaki i oprogramowanie podmiotów trzecich należą do ich właścicieli.</p></div></footer>
    <script>
        window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                fetch('visitor_ping.php', {method: 'POST', credentials: 'same-origin', keepalive: true})
                    .catch(error => console.warn('Hublist visitor heartbeat failed.', error));
            }
        }, 60000);
    </script>
    </body></html>
    <?php
}
