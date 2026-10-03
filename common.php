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
    return $pdo;
}

function download_categories(): array
{
    return [
        'server' => 'Serwery hubów',
        'client' => 'Klienci Direct Connect',
        'script' => 'Skrypty Lua i dodatki',
        'other' => 'Inne narzędzia',
    ];
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

    return [
        'name' => $name,
        'protocol' => $protocol,
        'host' => strtolower($host),
        'port' => $port,
        'country' => $country !== '' ? $country : null,
        'description' => $description !== '' ? $description : null,
        'software' => $software !== '' ? $software : null,
        'website' => $website !== '' ? $website : null,
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
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

function page_start(string $title): void
{
    security_headers();
    ?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Niezależna lista hubów Direct Connect ADC i NMDC z własnym pingerem i pełnymi statystykami.">
        <link rel="alternate" type="application/xml" href="feed.php?format=xml">
        <title><?= e($title) ?> — Hublist</title>
        <style>
            :root{color-scheme:light;--ink:#162338;--muted:#65758b;--line:#dce4ee;--paper:#fff;--bg:#f2f5f9;--blue:#165dbe;--green:#147341;--amber:#855700;--red:#a32626}
            *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
            a{color:var(--blue)}.top{background:#102944;color:#fff}.top-inner,main,footer{width:min(1240px,100% - 32px);margin-inline:auto}
            .top-inner{padding:20px 0;display:flex;justify-content:space-between;align-items:center;gap:20px}.brand{font-weight:800;font-size:1.45rem;color:#fff;text-decoration:none;letter-spacing:.02em}.nav{display:flex;gap:18px;flex-wrap:wrap}.nav a{color:#e6eef8;text-decoration:none}
            main{padding-top:24px;padding-bottom:48px}h1{font-size:clamp(1.7rem,4vw,2.5rem);line-height:1.2;margin:.1rem 0 .45rem}h2{margin:0 0 12px;font-size:1.2rem}
            .hero{padding:22px 0 18px}.hero p{color:var(--muted);margin:0}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}
            .card,.panel{border:1px solid var(--line);border-radius:12px;background:var(--paper)}.card{padding:14px 16px}.card strong{display:block;font-size:1.65rem}.muted,small{color:var(--muted)}
            .panel{padding:18px;margin:14px 0}.actions,form{display:flex;gap:9px;flex-wrap:wrap;align-items:center}input,select,textarea{font:inherit;border:1px solid #bac5d3;border-radius:7px;background:#fff;padding:9px 11px;min-height:42px;color:var(--ink)}input[type=search]{flex:1 1 240px}textarea{width:100%;min-height:92px}button,.button{display:inline-block;border:1px solid var(--blue);border-radius:7px;background:var(--blue);color:white;padding:9px 14px;font:inherit;text-decoration:none;cursor:pointer}.button.secondary,button.secondary{background:white;color:var(--blue)}
            .table-wrap{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1050px}th,td{padding:10px 9px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{font-size:.8rem;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}td small{display:block}.proto,.pill{display:inline-block;border-radius:20px;background:#eaf0f8;color:#233b5d;padding:2px 8px;font-size:.8rem;font-weight:650;white-space:nowrap}.ok{color:var(--green);font-weight:700}.bad{color:var(--red);font-weight:700}.wait{color:var(--amber);font-weight:700}.address{font-family:ui-monospace,monospace;overflow-wrap:anywhere}.detail{margin-top:2px}.note{padding:11px 14px;background:#fff8e7;border-left:4px solid #d1a23b;border-radius:4px}.error{padding:11px 14px;background:#fff0ef;border-left:4px solid var(--red);border-radius:4px}.success{padding:11px 14px;background:#edf9f1;border-left:4px solid var(--green);border-radius:4px}
            .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field{display:grid;gap:4px}.field.full{grid-column:1/-1}.downloads{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}.download{border:1px solid var(--line);border-radius:10px;padding:15px}.download h3{margin:0}.empty{padding:15px;color:var(--muted)}.pager{display:flex;justify-content:center;gap:16px;padding-top:16px}.pager a{text-decoration:none;font-weight:650}footer{padding:0 0 26px;color:var(--muted);font-size:.9rem}
            @media(max-width:760px){.top-inner{align-items:flex-start;flex-direction:column}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
        </style>
    </head>
    <body><div class="top"><div class="top-inner"><a class="brand" href="index.php">Hublist</a><nav class="nav" aria-label="Menu główne"><a href="index.php">Hublista</a><a href="index.php#zglos-hub">Dodaj hub</a><a href="download.php">Download</a><a href="feed.php?format=xml">Feed XML</a><a href="about.php">O nas</a><a href="faq.php">FAQ</a><a href="rules.php">Regulamin</a><a href="admin.php">Administracja</a></nav></div></div>
    <?php
}

function page_end(): void
{
    ?>
    <footer><p>Hublist pomaga znaleźć publiczne huby Direct Connect i udostępnia ich feed klientom DC. Status oraz linki do zewnętrznych programów mogą się zmieniać.</p><p><a href="about.php">O serwisie</a> · <a href="faq.php">FAQ</a> · <a href="rules.php">Regulamin</a> · <a href="download.php">Download</a> · <a href="admin.php">Administracja</a></p></footer>
    </body></html>
    <?php
}
