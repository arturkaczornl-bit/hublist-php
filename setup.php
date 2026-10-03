<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/config.php')) {
    http_response_code(403);
    exit('Aplikacja jest już skonfigurowana. Usuń setup.php albo skontaktuj się z administratorem.');
}

$error = '';
$values = ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    if (!isset($_SESSION['setup_csrf'])) {
        $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
    }
    $providedToken = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if (!hash_equals($_SESSION['setup_csrf'], $providedToken)) {
        http_response_code(400);
        $error = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        foreach ($values as $key => $_) {
            $values[$key] = trim(post_string($_POST, $key));
        }
        $admin = trim(post_string($_POST, 'admin_user'));
        $password = post_string($_POST, 'admin_password');
        $confirm = post_string($_POST, 'admin_confirm');

        try {
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $admin)) {
                throw new RuntimeException('Login administratora: 3–64 znaki (litery, cyfry, kropka, podkreślenie lub myślnik).');
            }
            if (strlen($password) < 14 || strlen($password) > 72) {
                throw new RuntimeException('Hasło administratora musi mieć od 14 do 72 bajtów.');
            }
            if (!hash_equals($password, $confirm)) {
                throw new RuntimeException('Wpisane hasła nie są takie same.');
            }
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $values['db_name'])
                || !preg_match('/^[a-zA-Z0-9_.-]{1,128}$/', $values['db_user'])
                || !preg_match('/^[a-zA-Z0-9.-]{1,253}$/', $values['db_host'])) {
                throw new RuntimeException('Nieprawidłowa nazwa bazy, użytkownika lub serwera MySQL.');
            }
            $port = filter_var($values['db_port'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            if ($port === false) {
                throw new RuntimeException('Port MySQL musi być liczbą od 1 do 65535.');
            }
            $missingExtensions = [];
            foreach (['pdo_mysql' => 'PDO MySQL', 'curl' => 'cURL', 'dom' => 'DOM', 'openssl' => 'OpenSSL', 'bz2' => 'BZip2'] as $extension => $label) {
                if (!extension_loaded($extension)) {
                    $missingExtensions[] = $label;
                }
            }
            if ($missingExtensions !== []) {
                throw new RuntimeException('Na hostingu brakuje rozszerzeń PHP: ' . implode(', ', $missingExtensions) . '.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Nie udało się zabezpieczyć hasła administratora.');
            }

            $config = [
                'db_host' => $values['db_host'],
                'db_port' => $port,
                'db_name' => $values['db_name'],
                'db_user' => $values['db_user'],
                'db_password' => post_string($_POST, 'db_password'),
            ];
            $configFile = "<?php\nreturn " . var_export($config, true) . ";\n";
            $configPath = __DIR__ . '/config.php';
            $configHandle = @fopen($configPath, 'x');
            if ($configHandle === false) {
                throw new RuntimeException('Nie można bezpiecznie utworzyć config.php. Sprawdź uprawnienia katalogu.');
            }
            $written = fwrite($configHandle, $configFile);
            fclose($configHandle);
            if ($written !== strlen($configFile)) {
                @unlink($configPath);
                throw new RuntimeException('Nie udało się zapisać pełnej konfiguracji aplikacji.');
            }
            @chmod($configPath, 0600);

            try {
                require __DIR__ . '/common.php';
                $pdo = db();
                $statements = [
                    "CREATE TABLE IF NOT EXISTS admins (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        username VARCHAR(64) NOT NULL UNIQUE,
                        password_hash VARCHAR(255) NOT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    "CREATE TABLE IF NOT EXISTS hubs (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(150) NOT NULL,
                        protocol ENUM('ADC','ADCS','DCHUB','NMDC','NMDCS') NOT NULL,
                        host VARCHAR(253) NOT NULL,
                        port SMALLINT UNSIGNED NOT NULL,
                        country CHAR(2) NULL,
                        description TEXT NULL,
                        software VARCHAR(120) NULL,
                        website VARCHAR(500) NULL,
                        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                        pinger_status VARCHAR(32) NULL,
                        pinger_error VARCHAR(255) NULL,
                        ping_ms INT UNSIGNED NULL,
                        last_ping_at DATETIME NULL,
                        tls_cert_valid TINYINT(1) NULL,
                        tls_cert_expires DATETIME NULL,
                        tls_cert_issuer VARCHAR(255) NULL,
                        tls_fingerprint CHAR(64) NULL,
                        hub_name VARCHAR(150) NULL,
                        hub_topic TEXT NULL,
                        online_users INT UNSIGNED NULL,
                        shared_bytes BIGINT UNSIGNED NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        verified_at DATETIME NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_hubs_public (status, name),
                        INDEX idx_hubs_recent (created_at),
                        INDEX idx_hubs_endpoint (host, port, protocol)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    "CREATE TABLE IF NOT EXISTS ping_history (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        hub_id BIGINT UNSIGNED NOT NULL,
                        is_online TINYINT(1) NOT NULL,
                        ping_ms INT UNSIGNED NULL,
                        tls_cert_valid TINYINT(1) NULL,
                        tls_cert_expires DATETIME NULL,
                        online_users INT UNSIGNED NULL,
                        checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_ping_hub_time (hub_id, checked_at),
                        CONSTRAINT fk_ping_hub FOREIGN KEY (hub_id) REFERENCES hubs(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    "CREATE TABLE IF NOT EXISTS hub_sources (
                        hub_id BIGINT UNSIGNED NOT NULL,
                        source_id VARCHAR(64) NOT NULL,
                        source_name VARCHAR(120) NOT NULL,
                        feed_url VARCHAR(500) NOT NULL,
                        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (hub_id, source_id),
                        CONSTRAINT fk_hub_sources_hub FOREIGN KEY (hub_id) REFERENCES hubs(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    "CREATE TABLE IF NOT EXISTS downloads (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        category VARCHAR(24) NOT NULL DEFAULT 'client',
                        name VARCHAR(150) NOT NULL,
                        version VARCHAR(80) NULL,
                        description TEXT NULL,
                        website VARCHAR(500) NOT NULL,
                        platform VARCHAR(150) NULL,
                        sort_order INT NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_downloads_order (category, sort_order, name)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    "CREATE TABLE IF NOT EXISTS submissions (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        ip_hash CHAR(64) NOT NULL,
                        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_submissions_ip_date (ip_hash, submitted_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    "CREATE TABLE IF NOT EXISTS admin_login_attempts (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        ip_hash CHAR(64) NOT NULL,
                        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_login_attempts_ip_date (ip_hash, attempted_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    "CREATE TABLE IF NOT EXISTS app_settings (
                        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                        setting_value VARCHAR(255) NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ];
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                }

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
                    $stmt->execute([$admin, $passwordHash]);
                    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('pinger_nick', ?)");
                    $stmt->execute(['Hublist-Pinger']);

                    $downloads = [
                        ['client', 'AirDC++', 'https://github.com/airdcpp/airdcpp-windows/releases', 'Oficjalne wydania klienta Direct Connect dla Windows.'],
                        ['client', 'DC++', 'https://dcpp.net/download', 'Klient Direct Connect dla Windows.'],
                        ['client', 'EiskaltDC++', 'https://github.com/eiskaltdcpp/eiskaltdcpp/releases', 'Klient Direct Connect dostępny na wielu platformach.'],
                        ['client', 'FlylinkDC++ (repozytorium społeczności)', 'https://github.com/pavel-pimenov/flylinkdc-r6xx', 'Społecznościowe repozytorium kodu klienta FlylinkDC++. Sprawdź instrukcje kompilacji i dostępność wydań.'],
                        ['client', 'Jucy', 'https://github.com/Quicksilver666/jucy', 'Klient Direct Connect oparty na Javie; repozytorium społecznościowe. Sprawdź zgodność i wydania.'],
                        ['client', 'ShakesPeer', 'https://github.com/rufuscoder/Shakespeer', 'Wieloplatformowy klient Direct Connect z repozytorium społecznościowym.'],
                        ['client', 'ncdc', 'https://dev.yorhel.nl/ncdc', 'Lekki klient Direct Connect z interfejsem tekstowym; obsługuje ADC i NMDC. Projekt jest utrzymywany pasywnie.'],
                        ['client', 'ApexDC++ (wersja archiwalna)', 'https://sourceforge.net/projects/apexdc/', 'Klasyczny klient Direct Connect. Projekt archiwalny; sprawdź zgodność i skanuj pobrane pliki.'],
                        ['client', 'Open Direct Connect (klient historyczny)', 'https://en.wikipedia.org/wiki/Direct_Connect_(protocol)', 'Historyczny klient Direct Connect, niekompletny i nieutrzymywany. Link prowadzi do opisu protokołu; brak zweryfikowanego wydania do pobrania.'],
                        ['server', 'Verlihub', 'https://github.com/Verlihub/verlihub/releases', 'Serwer hubów Direct Connect NMDC dla systemu Linux.'],
                        ['server', 'ADCH++', 'https://sourceforge.net/projects/adchpp/files/', 'Archiwum wydań serwera hubów Direct Connect ADC.'],
                        ['server', 'µHub (uhub)', 'https://github.com/janvidar/uhub', 'Lekki serwer hubów ADC z otwartym kodem źródłowym.'],
                        ['server', 'Luadch', 'https://github.com/luadch/luadch', 'Serwer hubów ADC napisany w Lua.'],
                        ['server', 'Luadch-ng', 'https://github.com/luadch-ng/luadch-ng', 'Nowszy, rozwijany fork serwera Luadch dla ADC/ADCS.'],
                        ['server', 'go-dcpp', 'https://github.com/direct-connect/go-dcpp', 'Hybrydowy serwer Direct Connect napisany w Go.'],
                        ['server', 'YnHub (archiwalny)', 'https://portableapps.com/node/25130', 'Serwer hubów DC++ dla Windows; rozwój zakończono w 2008 r. Link do archiwalnego opisu, nie do instalatora.'],
                        ['server', 'PtokaX', 'http://www.ptokax.org/', 'Serwer hubów NMDC z obsługą Lua; oficjalna strona używa HTTP.'],
                    ];
                    $stmt = $pdo->prepare('INSERT INTO downloads (category, name, website, description) VALUES (?, ?, ?, ?)');
                    foreach ($downloads as $download) {
                        $stmt->execute($download);
                    }
                    $pdo->commit();
                } catch (Throwable $seedException) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $seedException;
                }

                $importedCount = 0;
                try {
                    require_once __DIR__ . '/imports.php';
                    $importResult = import_public_hubs($pdo);
                    $importedCount = $importResult['new_hubs'];
                    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('initial_import_at',?)");
                    $stmt->execute([gmdate('Y-m-d H:i:s')]);
                    if ($importResult['errors'] !== []) {
                        error_log('Hublist initial import: ' . implode(' | ', $importResult['errors']));
                    }
                } catch (Throwable $importException) {
                    error_log('Hublist initial import failed: ' . $importException->getMessage());
                }
            } catch (Throwable $exception) {
                @unlink($configPath);
                throw $exception;
            }

            redirect('admin.php?setup=1&imported=' . (int) ($importedCount ?? 0));
        } catch (Throwable $exception) {
            $error = $exception instanceof PDOException
                ? 'Nie udało się połączyć z MySQL albo utworzyć tabel. Sprawdź nazwę bazy, login, hasło i uprawnienia użytkownika.'
                : $exception->getMessage();
        }
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalacja Hublist</title>
<style>
body{margin:0;background:#f2f5f9;color:#162338;font:16px/1.5 system-ui,sans-serif}.box{max-width:650px;margin:36px auto;padding:24px;background:#fff;border:1px solid #dce4ee;border-radius:12px}h1{margin-top:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.wide{grid-column:1/-1}label{display:grid;gap:4px;font-weight:600}input{font:inherit;padding:10px;border:1px solid #aebaca;border-radius:7px}button{margin-top:16px;border:0;border-radius:7px;padding:11px 16px;background:#165dbe;color:#fff;font:inherit;cursor:pointer}.error{padding:10px;background:#fff0ef;border-left:4px solid #a32626}small{color:#65758b}@media(max-width:600px){.box{margin:12px;padding:18px}.grid{grid-template-columns:1fr}.wide{grid-column:auto}}
</style>
</head>
<body><main class="box">
<h1>Konfiguracja Hublist</h1>
<p>Wprowadź dane bazy MySQL z panelu hostingu i utwórz konto administratora. Hasło administratora zostanie zapisane jako bezpieczny hash.</p>
<?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<div class="grid">
<label>Serwer MySQL<input name="db_host" required value="<?= htmlspecialchars($values['db_host'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Port<input name="db_port" type="number" min="1" max="65535" required value="<?= htmlspecialchars($values['db_port'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Nazwa bazy danych<input name="db_name" required value="<?= htmlspecialchars($values['db_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Użytkownik MySQL<input name="db_user" required value="<?= htmlspecialchars($values['db_user'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label class="wide">Hasło MySQL<input name="db_password" type="password" autocomplete="new-password"></label>
<label>Login administratora<input name="admin_user" required minlength="3" maxlength="64" autocomplete="username"></label>
<label>Hasło administratora<input name="admin_password" type="password" required minlength="14" maxlength="72" autocomplete="new-password"><small>Minimum 14 znaków. Zachowaj je w menedżerze haseł.</small></label>
<label class="wide">Powtórz hasło administratora<input name="admin_confirm" type="password" required minlength="14" maxlength="72" autocomplete="new-password"></label>
</div><button type="submit">Zainstaluj Hublist</button></form>
</main></body></html>
