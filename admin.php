<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
start_app_session();
$pdo = db();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'login' && !admin_logged_in()) {
        $config = app_config();
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ipHash = hash_hmac('sha256', $remoteIp, $config['db_password'] . $config['db_name']);
        $attempts = $pdo->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip_hash = ? AND attempted_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE');
        $attempts->execute([$ipHash]);
        if ((int) $attempts->fetchColumn() >= 8) {
            $error = 'Za dużo prób logowania. Odczekaj 15 minut.';
        } else {
            $login = trim(post_string($_POST, 'username'));
            $password = post_string($_POST, 'password');
            $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
            $stmt->execute([$login]);
            $admin = $stmt->fetch();
            $valid = $admin && password_verify($password, $admin['password_hash']);
            $pdo->prepare('INSERT INTO admin_login_attempts (ip_hash) VALUES (?)')->execute([$ipHash]);
            if ($valid) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_name'] = $admin['username'];
                $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = ?')->execute([$ipHash]);
                redirect('admin.php');
            }
            $error = 'Nieprawidłowy login lub hasło.';
        }
    } elseif ($action === 'logout') {
        require_admin();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
        session_destroy();
        redirect('admin.php');
    } else {
        require_admin();
        try {
            if ($action === 'approve_hub') {
                $id = filter_var(post_string($_POST, 'id'), FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator huba.');
                }
                $stmt = $pdo->prepare("UPDATE hubs SET status='approved', verified_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
                $stmt->execute([$id]);
                $notice = $stmt->rowCount() ? 'Hub zatwierdzony.' : 'Nie znaleziono zgłoszenia oczekującego.';
            } elseif ($action === 'reject_hub') {
                $id = filter_var(post_string($_POST, 'id'), FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator zgłoszenia.');
                }
                $stmt = $pdo->prepare("UPDATE hubs SET status='rejected' WHERE id=? AND status='pending'");
                $stmt->execute([$id]);
                $notice = 'Zgłoszenie odrzucone.';
            } elseif ($action === 'delete_hub') {
                $id = filter_var(post_string($_POST, 'id'), FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator huba.');
                }
                $stmt = $pdo->prepare("DELETE FROM hubs WHERE id=?");
                $stmt->execute([$id]);
                $notice = 'Hub usunięty.';
            } elseif ($action === 'save_hub') {
                $hub = normalize_hub_input($_POST);
                $id = filter_var(post_string($_POST, 'id') ?: '0', FILTER_VALIDATE_INT);
                if ($id === false) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator huba.');
                }
                $status = post_string($_POST, 'status') === 'approved' ? 'approved' : 'pending';
                if ($id) {
                    $existingStmt = $pdo->prepare('SELECT protocol,host,port FROM hubs WHERE id=?');
                    $existingStmt->execute([$id]);
                    $existing = $existingStmt->fetch();
                    if (!$existing) {
                        throw new InvalidArgumentException('Nie znaleziono huba do edycji.');
                    }
                    $endpointChanged = $existing['protocol'] !== $hub['protocol']
                        || $existing['host'] !== $hub['host']
                        || (int) $existing['port'] !== $hub['port'];
                    $resetPing = $endpointChanged
                        ? ',pinger_status=NULL,pinger_error=NULL,ping_ms=NULL,last_ping_at=NULL,tls_cert_valid=NULL,tls_cert_expires=NULL,tls_cert_issuer=NULL,tls_fingerprint=NULL,hub_name=NULL,hub_topic=NULL,online_users=NULL,shared_bytes=NULL'
                        : '';
                    $stmt = $pdo->prepare('UPDATE hubs SET name=?,protocol=?,host=?,port=?,country=?,country_source=IF(? IS NULL,NULL,"manual"),country_ip=NULL,description=?,software=?,website=?,icon_url=?,status=?,verified_at=IF(?="approved",COALESCE(verified_at,UTC_TIMESTAMP()),NULL)' . $resetPing . ' WHERE id=?');
                    $stmt->execute([
                        $hub['name'], $hub['protocol'], $hub['host'], $hub['port'], $hub['country'], $hub['country'],
                        $hub['description'], $hub['software'], $hub['website'], $hub['icon_url'], $status, $status, $id,
                    ]);
                    $notice = 'Dane huba zaktualizowane.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO hubs (name,protocol,host,port,country,country_source,description,software,website,icon_url,status,verified_at) VALUES (?,?,?,?,?,IF(? IS NULL,NULL,"manual"),?,?,?,?,IF(?="approved",UTC_TIMESTAMP(),NULL))');
                    $stmt->execute([
                        $hub['name'], $hub['protocol'], $hub['host'], $hub['port'], $hub['country'], $hub['country'],
                        $hub['description'], $hub['software'], $hub['website'], $hub['icon_url'], $status, $status,
                    ]);
                    $notice = 'Hub dodany.';
                }
            } elseif ($action === 'save_download') {
                $id = filter_var(post_string($_POST, 'id') ?: '0', FILTER_VALIDATE_INT);
                $category = post_string($_POST, 'category');
                $name = trim(post_string($_POST, 'name'));
                $version = trim(post_string($_POST, 'version'));
                $description = trim(post_string($_POST, 'description'));
                $website = trim(post_string($_POST, 'website'));
                $platform = trim(post_string($_POST, 'platform'));
                $order = filter_var(post_string($_POST, 'sort_order') ?: '0', FILTER_VALIDATE_INT);
                $nameLength = utf8_length($name);
                $versionLength = utf8_length($version);
                $descriptionLength = utf8_length($description);
                $websiteLength = utf8_length($website);
                $platformLength = utf8_length($platform);
                $safeWebsite = safe_download_url($website);
                if ($id === false) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator katalogu.');
                }
                if (!array_key_exists($category, download_categories()) || $name === '' || $nameLength === null || $nameLength > 150
                    || $versionLength === null || $versionLength > 80
                    || $descriptionLength === null || $descriptionLength > 5000
                    || $websiteLength === null || $websiteLength > 500
                    || $platformLength === null || $platformLength > 150
                    || !$safeWebsite
                    || $order === false) {
                    throw new InvalidArgumentException('Sprawdź nazwę, kategorię, kolejność i adres HTTPS (wyjątek: oficjalna strona PtokaX).');
                }
                if ($id) {
                    $stmt = $pdo->prepare('UPDATE downloads SET category=?,name=?,version=?,description=?,website=?,platform=?,sort_order=? WHERE id=?');
                    $stmt->execute([$category, $name, $version ?: null, $description ?: null, $website, $platform ?: null, $order, $id]);
                    $notice = 'Wpis katalogu został zaktualizowany.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO downloads (category,name,version,description,website,platform,sort_order) VALUES (?,?,?,?,?,?,?)');
                    $stmt->execute([$category, $name, $version ?: null, $description ?: null, $website, $platform ?: null, $order]);
                    $notice = 'Wpis katalogu został dodany.';
                }
            } elseif ($action === 'save_download_category') {
                $key = trim(post_string($_POST, 'category_key'));
                $name = trim(post_string($_POST, 'category_name'));
                $description = trim(post_string($_POST, 'category_description'));
                $order = filter_var(post_string($_POST, 'category_order') ?: '0', FILTER_VALIDATE_INT);
                $nameLength = utf8_length($name);
                $descriptionLength = utf8_length($description);
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/', $key)
                    || $name === '' || $nameLength === null || $nameLength > 100
                    || $descriptionLength === null || $descriptionLength > 2000
                    || $order === false) {
                    throw new InvalidArgumentException('Sprawdź identyfikator, nazwę, opis i kolejność kategorii.');
                }
                $stmt = $pdo->prepare('INSERT INTO download_categories (category_key,name,description,sort_order) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),sort_order=VALUES(sort_order)');
                $stmt->execute([$key, $name, $description ?: null, $order]);
                $notice = 'Kategoria została zapisana.';
            } elseif ($action === 'delete_download_category') {
                $key = trim(post_string($_POST, 'category_key'));
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/', $key)) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator kategorii.');
                }
                $count = $pdo->prepare('SELECT COUNT(*) FROM downloads WHERE category=?');
                $count->execute([$key]);
                if ((int) $count->fetchColumn() !== 0) {
                    throw new InvalidArgumentException('Nie można usunąć kategorii, która zawiera wpisy. Najpierw przenieś lub usuń jej wpisy.');
                }
                $stmt = $pdo->prepare('DELETE FROM download_categories WHERE category_key=?');
                $stmt->execute([$key]);
                $notice = $stmt->rowCount() ? 'Kategoria została usunięta.' : 'Nie znaleziono kategorii.';
            } elseif ($action === 'delete_download') {
                $id = filter_var(post_string($_POST, 'id'), FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator wpisu.');
                }
                $pdo->prepare('DELETE FROM downloads WHERE id=?')->execute([$id]);
                $notice = 'Wpis katalogu został usunięty.';
            } elseif ($action === 'ban_ip') {
                $ip = normalize_ip_address(trim(post_string($_POST, 'ip_address')));
                if ($ip === null) {
                    throw new InvalidArgumentException('Podaj prawidłowy adres IPv4 lub IPv6.');
                }
                $reason = trim(post_string($_POST, 'reason'));
                if (utf8_length($reason) === null || utf8_length($reason) > 255) {
                    throw new InvalidArgumentException('Powód blokady może mieć maksymalnie 255 znaków.');
                }
                $stmt = $pdo->prepare('INSERT INTO ip_bans (ip_address,reason,created_at) VALUES (?,?,UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE reason=VALUES(reason),created_at=VALUES(created_at)');
                $stmt->execute([$ip, $reason]);
                $pdo->prepare('DELETE FROM visitor_sessions WHERE ip_address=?')->execute([$ip]);
                $notice = 'Adres IP został zablokowany.';
            } elseif ($action === 'unban_ip') {
                $ip = normalize_ip_address(trim(post_string($_POST, 'ip_address')));
                if ($ip === null) {
                    throw new InvalidArgumentException('Nieprawidłowy adres IP.');
                }
                $stmt = $pdo->prepare('DELETE FROM ip_bans WHERE ip_address=?');
                $stmt->execute([$ip]);
                $notice = $stmt->rowCount() ? 'Blokada adresu IP została zdjęta.' : 'Nie znaleziono takiej blokady.';
            } elseif ($action === 'save_settings') {
                $nick = trim(post_string($_POST, 'pinger_nick'));
                if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $nick)) {
                    throw new InvalidArgumentException('Nick pingera może zawierać 3–32 litery, cyfry, kropki, myślniki i podkreślenia.');
                }
                $description = trim(post_string($_POST, 'pinger_description'));
                $version = trim(post_string($_POST, 'pinger_version'));
                $email = trim(post_string($_POST, 'pinger_email'));
                $connection = trim(post_string($_POST, 'pinger_connection'));
                $interval = filter_var(post_string($_POST, 'pinger_interval'), FILTER_VALIDATE_INT);
                $safeProfileField = static fn(string $value): bool =>
                    !preg_match('/[\x00-\x1F\x7F|$]/', $value);
                if (utf8_length($description) === null || utf8_length($description) > 80 || !$safeProfileField($description)) {
                    throw new InvalidArgumentException('Opis pingera może mieć maksymalnie 80 znaków i nie może zawierać znaków sterujących ani separatorów protokołu.');
                }
                if (!preg_match('/^[A-Za-z0-9 ,._+()\-]{1,40}$/', $version)) {
                    throw new InvalidArgumentException('Wersja klienta może mieć 1–40 znaków: litery, cyfry, spacje oraz , . _ + ( ) -.');
                }
                if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254 || !$safeProfileField($email))) {
                    throw new InvalidArgumentException('Podaj prawidłowy adres e-mail albo pozostaw pole puste.');
                }
                if (utf8_length($connection) === null || utf8_length($connection) > 32
                    || !$safeProfileField($connection) || !preg_match('/^[A-Za-z0-9 ._+\/()-]*$/', $connection)) {
                    throw new InvalidArgumentException('Typ łącza może mieć maksymalnie 32 znaki i zawierać litery, cyfry, spacje oraz . _ + / ( ) -.');
                }
                if ($interval === false || $interval < 5 || $interval > 10080) {
                    throw new InvalidArgumentException('Częstotliwość pingowania musi wynosić od 5 do 10080 minut.');
                }
                $settings = [
                    'pinger_nick' => $nick,
                    'pinger_description' => $description,
                    'pinger_version' => $version,
                    'pinger_email' => $email,
                    'pinger_connection' => $connection,
                    'pinger_interval' => (string) $interval,
                ];
                $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                foreach ($settings as $key => $value) {
                    $stmt->execute([$key, $value]);
                }
                $notice = 'Ustawienia pingera zapisane.';
            } elseif ($action === 'change_password') {
                $currentPassword = post_string($_POST, 'current_password');
                $newPassword = post_string($_POST, 'new_password');
                $confirmPassword = post_string($_POST, 'confirm_password');
                if (strlen($newPassword) < 14 || strlen($newPassword) > 72) {
                    throw new InvalidArgumentException('Nowe hasło musi mieć od 14 do 72 bajtów.');
                }
                if (!hash_equals($newPassword, $confirmPassword)) {
                    throw new InvalidArgumentException('Powtórzone hasło jest inne.');
                }
                $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id=?');
                $stmt->execute([$_SESSION['admin_id']]);
                $currentHash = $stmt->fetchColumn();
                if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
                    throw new InvalidArgumentException('Bieżące hasło jest nieprawidłowe.');
                }
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                if (!is_string($newHash)) {
                    throw new RuntimeException('Nie udało się zabezpieczyć nowego hasła.');
                }
                $stmt = $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?');
                $stmt->execute([$newHash, $_SESSION['admin_id']]);
                session_regenerate_id(true);
                $notice = 'Hasło administratora zostało zmienione.';
            } elseif ($action === 'import_public_hubs') {
                require_once __DIR__ . '/imports.php';
                $result = import_public_hubs($pdo);
                $notice = 'Import zakończony: ' . $result['new_hubs'] . ' nowych zgłoszeń oczekuje na weryfikację; źródeł: ' . $result['feeds'] . '.';
                if ($result['errors'] !== []) {
                    $notice .= ' Niedostępne źródła: ' . implode('; ', $result['errors']);
                }
            } else {
                throw new InvalidArgumentException('Nieznana operacja.');
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Hublist admin operation failed: ' . $exception->getMessage());
            $error = 'Nie udało się wykonać operacji. Sprawdź dane i spróbuj ponownie.';
        }
    }
}

$tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'pending';
if (!in_array($tab, ['pending', 'hubs', 'downloads', 'visitors', 'settings'], true)) {
    $tab = 'pending';
}

if (!admin_logged_in()) {
    page_start('Logowanie administratora');
    ?>
    <main><section class="panel" style="max-width:520px;margin:48px auto">
        <h1>Panel administratora</h1>
        <p class="muted">Zaloguj się, aby zatwierdzać zgłoszenia, zarządzać hubami i edytować Pobieralnię.</p>
        <?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
        <form method="post" class="grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login">
            <label class="field full">Login<input name="username" autocomplete="username" required></label>
            <label class="field full">Hasło<input name="password" type="password" autocomplete="current-password" required></label>
            <div class="field full"><button type="submit">Zaloguj</button></div>
        </form>
    </section></main>
    <?php page_end(); exit;
}

$pending = $pdo->query("SELECT * FROM hubs WHERE status='pending' ORDER BY created_at ASC")->fetchAll();
$hubRows = $pdo->query("SELECT * FROM hubs WHERE status <> 'rejected' ORDER BY status, name")->fetchAll();
$downloadRows = $pdo->query('SELECT * FROM downloads ORDER BY category, sort_order, name')->fetchAll();
$downloadCategories = download_categories();
$downloadCategoryRows = download_category_details();
$activeVisitors = [];
$visitorHistory = [];
$bannedIps = [];
$activeVisitorCount = 0;
$visitorHistoryCount = 0;
$visitorHistoryPages = 1;
$visitorHistoryPage = 1;
$visitorHistoryIpRaw = substr(trim(is_string($_GET['history_ip'] ?? null) ? $_GET['history_ip'] : ''), 0, 45);
$visitorHistoryIp = normalize_ip_address($visitorHistoryIpRaw);
$visitorHistoryIpFilter = $visitorHistoryIpRaw !== '';
$visitorHistoryIpQuery = $visitorHistoryIp ?? $visitorHistoryIpRaw;
if ($tab === 'visitors') {
    $activeVisitors = $pdo->query(
        'SELECT ip_address,current_path,first_seen,last_seen FROM visitor_sessions
         WHERE last_seen >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE ORDER BY last_seen DESC LIMIT 100'
    )->fetchAll();
    $activeVisitorCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM visitor_sessions WHERE last_seen >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE'
    )->fetchColumn();
    $rawHistoryPage = filter_var(is_string($_GET['history_page'] ?? null) ? $_GET['history_page'] : '1', FILTER_VALIDATE_INT);
    $visitorHistoryPage = $rawHistoryPage === false ? 1 : max(1, $rawHistoryPage);
    if ($visitorHistoryIpFilter) {
        $historyCount = $pdo->prepare('SELECT COUNT(*) FROM visitor_history WHERE ip_address=?');
        $historyCount->execute([$visitorHistoryIpQuery]);
        $visitorHistoryCount = (int) $historyCount->fetchColumn();
    } else {
        $visitorHistoryCount = (int) $pdo->query('SELECT COUNT(*) FROM visitor_history')->fetchColumn();
    }
    $visitorHistoryPages = max(1, (int) ceil($visitorHistoryCount / 100));
    $visitorHistoryPage = min($visitorHistoryPage, $visitorHistoryPages);
    $historyOffset = ($visitorHistoryPage - 1) * 100;
    if ($visitorHistoryIpFilter) {
        $historyStmt = $pdo->prepare("SELECT ip_address,path,visited_at FROM visitor_history
            WHERE ip_address=? ORDER BY visited_at DESC LIMIT 100 OFFSET $historyOffset");
        $historyStmt->execute([$visitorHistoryIpQuery]);
        $visitorHistory = $historyStmt->fetchAll();
    } else {
        $visitorHistory = $pdo->query("SELECT ip_address,path,visited_at FROM visitor_history
            ORDER BY visited_at DESC LIMIT 100 OFFSET $historyOffset")->fetchAll();
    }
    $bannedIps = $pdo->query('SELECT ip_address,reason,created_at FROM ip_bans ORDER BY created_at DESC LIMIT 200')->fetchAll();
}
$pingerSettings = [
    'pinger_nick' => 'Hublist-Pinger',
    'pinger_description' => 'Hublist pinger',
    'pinger_version' => '1,0091',
    'pinger_email' => '',
    'pinger_connection' => 'DSL',
    'pinger_interval' => '48',
];
$pingerSettingsStmt = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN
    ('pinger_nick','pinger_description','pinger_version','pinger_email','pinger_connection','pinger_interval')");
foreach ($pingerSettingsStmt->fetchAll() as $setting) {
    if (array_key_exists($setting['setting_key'], $pingerSettings)) {
        $pingerSettings[$setting['setting_key']] = (string) $setting['setting_value'];
    }
}
$editHubId = filter_var(is_string($_GET['edit_hub'] ?? null) ? $_GET['edit_hub'] : '', FILTER_VALIDATE_INT);
$editHub = null;
if ($editHubId) {
    $stmt = $pdo->prepare('SELECT * FROM hubs WHERE id=?');
    $stmt->execute([$editHubId]);
    $editHub = $stmt->fetch() ?: null;
}
$editDownloadId = filter_var(is_string($_GET['edit_download'] ?? null) ? $_GET['edit_download'] : '', FILTER_VALIDATE_INT);
$editDownload = null;
if ($editDownloadId) {
    $stmt = $pdo->prepare('SELECT * FROM downloads WHERE id=?');
    $stmt->execute([$editDownloadId]);
    $editDownload = $stmt->fetch() ?: null;
}

page_start('Panel administracyjny');
?>
<main>
    <section class="hero"><h1>Panel administracyjny</h1><p>Zalogowano jako <?= e((string) $_SESSION['admin_name']) ?>.</p></section>
    <?php if (isset($_GET['setup'])): ?><p class="success">Instalacja zakończona. Dodano <?= (int) ($_GET['imported'] ?? 0) ?> hubów do kolejki weryfikacji. Zapisz adres strony i skonfiguruj pinger co 48 minut.</p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="success"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <section class="panel">
        <nav class="nav">
            <a href="?tab=pending">Oczekujące (<?= count($pending) ?>)</a>
            <a href="?tab=hubs">Huby</a>
            <a href="?tab=downloads">Pobieralnia</a>
            <a href="?tab=visitors">Odwiedzający</a>
            <a href="?tab=settings">Ustawienia</a>
        </nav>
        <form method="post" style="justify-content:flex-end">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout">
            <button class="secondary" type="submit">Wyloguj</button>
        </form>
    </section>

    <?php if ($tab === 'pending'): ?>
        <section class="panel"><h2>Nowe zgłoszenia oczekujące na weryfikację</h2>
        <?php if ($pending === []): ?><p class="empty">Kolejka jest pusta.</p><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Hub</th><th>Adres / protokół</th><th>Dodano</th><th>Decyzja</th></tr></thead><tbody>
        <?php foreach ($pending as $hub): ?><tr>
            <td><strong><?= e($hub['name']) ?></strong><small><?= e($hub['description']) ?></small></td>
            <td><?= e($hub['host']) ?>:<?= (int) $hub['port'] ?> <span class="proto"><?= e($hub['protocol']) ?></span></td>
            <td><?= e(utc_datetime($hub['created_at'])) ?></td>
            <td><div class="actions">
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $hub['id'] ?>"><button name="action" value="approve_hub">Zatwierdź</button></form>
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $hub['id'] ?>"><button class="secondary" name="action" value="reject_hub">Odrzuć</button></form>
            </div></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></section>
    <?php elseif ($tab === 'hubs'): ?>
        <?php if ($editHub !== null): ?>
        <section class="panel"><h2>Edytuj hub: <?= e($editHub['name']) ?></h2>
        <form method="post" class="grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_hub">
            <input type="hidden" name="id" value="<?= (int) $editHub['id'] ?>">
            <?php render_hub_fields($editHub); ?>
            <label class="field">Widoczność<select name="status">
                <option value="approved" <?= $editHub['status'] === 'approved' ? 'selected' : '' ?>>Opublikowany</option>
                <option value="pending" <?= $editHub['status'] === 'pending' ? 'selected' : '' ?>>Oczekuje</option>
            </select></label>
            <div class="field full"><button type="submit">Zapisz zmiany</button> <a class="button secondary" href="?tab=hubs">Anuluj</a></div>
        </form></section>
        <?php endif; ?>
        <section class="panel"><h2>Dodaj hub ręcznie</h2>
        <form method="post" class="grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_hub">
            <?php render_hub_fields(); ?>
            <label class="field">Widoczność<select name="status"><option value="approved">Opublikowany</option><option value="pending">Oczekuje</option></select></label>
            <div class="field full"><button type="submit">Dodaj hub</button></div>
        </form></section>
        <section class="panel"><h2>Zarządzaj hubami</h2><div class="table-wrap"><table>
        <thead><tr><th>Hub</th><th>Adres</th><th>Status</th><th>Ostatni ping</th><th>Akcje</th></tr></thead><tbody>
        <?php foreach ($hubRows as $hub): ?><tr>
            <td><strong><?= e($hub['name']) ?></strong><small><?= e($hub['description']) ?></small></td>
            <td><?= e($hub['protocol']) ?>://<?= e($hub['host']) ?>:<?= (int) $hub['port'] ?></td>
            <td><?= e($hub['status']) ?></td><td><?= e($hub['pinger_status'] ?: 'Brak pomiaru') ?><small><?= e(utc_datetime($hub['last_ping_at'])) ?></small></td>
            <td><div class="actions"><a class="button secondary" href="?tab=hubs&amp;edit_hub=<?= (int) $hub['id'] ?>">Edytuj</a><form method="post" onsubmit="return confirm('Usunąć hub i historię jego pingów?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $hub['id'] ?>">
                <button class="secondary" name="action" value="delete_hub">Usuń</button>
            </form></div></td>
        </tr><?php endforeach; ?>
        </tbody></table></div></section>
    <?php elseif ($tab === 'downloads'): ?>
        <?php if ($editDownload !== null): ?>
        <section class="panel"><h2>Edytuj: <?= e($editDownload['name']) ?></h2>
        <form method="post" class="grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_download">
            <input type="hidden" name="id" value="<?= (int) $editDownload['id'] ?>">
            <?php render_download_fields($editDownload); ?>
            <div class="field full"><button type="submit">Zapisz zmiany</button> <a class="button secondary" href="?tab=downloads">Anuluj</a></div>
        </form></section>
        <?php endif; ?>
        <section class="panel"><h2>Dodaj wpis do katalogu</h2>
        <form method="post" class="grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_download">
            <?php render_download_fields(); ?>
            <div class="field full"><button type="submit">Dodaj do katalogu</button></div>
        </form></section>
        <section class="panel"><h2>Katalog pobieralni</h2><div class="table-wrap"><table>
        <thead><tr><th>Kategoria</th><th>Nazwa i wersja</th><th>Oficjalny link</th><th>Kliknięcia pobrania</th><th>Akcje</th></tr></thead><tbody>
        <?php foreach ($downloadRows as $item): ?><tr>
            <td><?= e($downloadCategories[$item['category']] ?? $item['category']) ?></td>
            <td><?= e($item['name']) ?><small><?= e($item['version']) ?> · <?= e($item['platform']) ?></small></td>
            <td><a href="<?= e($item['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['website']) ?></a></td>
            <td><?= number_format((int) $item['download_count'], 0, ',', ' ') ?></td>
            <td><div class="actions"><a class="button secondary" href="?tab=downloads&amp;edit_download=<?= (int) $item['id'] ?>">Edytuj</a><form method="post" onsubmit="return confirm('Usunąć wpis z katalogu?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <button class="secondary" name="action" value="delete_download">Usuń</button>
            </form></div></td>
        </tr><?php endforeach; ?>
        </tbody></table></div></section>
        <section class="panel"><h2>Zarządzaj kategoriami</h2>
            <p class="muted">Kategorie zawierające wpisy można usunąć dopiero po przeniesieniu lub usunięciu tych wpisów. Licznik oznacza kliknięcia linku, a nie potwierdzone zakończenie pobierania.</p>
            <div class="grid">
                <?php foreach ($downloadCategoryRows as $categoryRow): ?>
                    <form method="post" class="grid" style="border:1px solid #dce5ee;border-radius:10px;padding:16px">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_download_category">
                        <input type="hidden" name="category_key" value="<?= e($categoryRow['category_key']) ?>">
                        <label class="field">Identyfikator<input value="<?= e($categoryRow['category_key']) ?>" disabled></label>
                        <label class="field">Nazwa<input name="category_name" maxlength="100" required value="<?= e($categoryRow['name']) ?>"></label>
                        <label class="field">Kolejność<input name="category_order" type="number" value="<?= (int) $categoryRow['sort_order'] ?>"></label>
                        <label class="field full">Opis kategorii<textarea name="category_description" maxlength="2000"><?= e($categoryRow['description'] ?? '') ?></textarea></label>
                        <div class="field full"><button type="submit">Zapisz kategorię</button></div>
                    </form>
                <?php endforeach; ?>
            </div>
            <h3>Dodaj kategorię</h3>
            <form method="post" class="grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_download_category">
                <label class="field">Identyfikator<input name="category_key" maxlength="24" pattern="[a-z0-9][a-z0-9_-]{0,23}" placeholder="np. tools" required></label>
                <label class="field">Nazwa<input name="category_name" maxlength="100" required></label>
                <label class="field">Kolejność<input name="category_order" type="number" value="50"></label>
                <label class="field full">Opis kategorii<textarea name="category_description" maxlength="2000"></textarea></label>
                <div class="field full"><button type="submit">Dodaj kategorię</button></div>
            </form>
            <?php foreach ($downloadCategoryRows as $categoryRow):
                $categoryCount = 0;
                foreach ($downloadRows as $downloadRow) {
                    if ($downloadRow['category'] === $categoryRow['category_key']) {
                        $categoryCount++;
                    }
                }
                if ($categoryCount === 0): ?>
                    <form method="post" onsubmit="return confirm('Usunąć pustą kategorię?')" style="display:inline-block;margin:6px 6px 0 0">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="category_key" value="<?= e($categoryRow['category_key']) ?>">
                        <button class="secondary" name="action" value="delete_download_category">Usuń pustą kategorię: <?= e($categoryRow['name']) ?></button>
                    </form>
                <?php endif;
            endforeach; ?>
        </section>
    <?php elseif ($tab === 'visitors'): ?>
        <section class="panel">
            <h2>Odwiedzający online: <?= $activeVisitorCount ?></h2>
            <p class="note">Aktywność oznacza odsłonę lub heartbeat w ciągu ostatnich 5 minut. Wyświetlamy do 100 aktywnych sesji; pełny licznik jest powyżej. Adresy IP i historia są widoczne wyłącznie w panelu administratora, a historia jest automatycznie usuwana po 30 dniach. Liczba online oznacza sesje przeglądarki, nie zweryfikowane osoby. Blokada dokładnego IP może objąć też inne osoby korzystające z tego samego łącza.</p>
            <?php if ($activeVisitors === []): ?><p class="empty">Brak aktywnych odwiedzających.</p><?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Adres IP</th><th>Aktualnie przegląda</th><th>Pierwsza wizyta</th><th>Ostatnia aktywność</th><th>Blokada</th></tr></thead><tbody>
            <?php foreach ($activeVisitors as $visitor): ?><tr>
                <td class="address"><?= e($visitor['ip_address']) ?></td>
                <td><code><?= e($visitor['current_path']) ?></code></td>
                <td><?= e(utc_datetime($visitor['first_seen'])) ?></td>
                <td><?= e(utc_datetime($visitor['last_seen'])) ?></td>
                <td><form method="post" onsubmit="return confirm('Zablokować ten adres IP?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="ban_ip">
                    <input type="hidden" name="ip_address" value="<?= e($visitor['ip_address']) ?>"><button class="secondary" type="submit">Zablokuj IP</button>
                </form></td>
            </tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <section class="panel"><h2>Zablokuj adres IP</h2>
            <form method="post" class="grid" onsubmit="return confirm('Zablokować dostęp z podanego adresu IP?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="ban_ip">
                <label class="field">Adres IPv4 lub IPv6<input name="ip_address" maxlength="45" required placeholder="203.0.113.10"></label>
                <label class="field">Powód (opcjonalnie)<input name="reason" maxlength="255"></label>
                <div class="field"><button type="submit">Zablokuj IP</button></div>
            </form>
        </section>
        <section class="panel"><h2>Zablokowane adresy IP</h2>
            <?php if ($bannedIps === []): ?><p class="empty">Brak zablokowanych adresów.</p><?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Adres IP</th><th>Powód</th><th>Dodano</th><th>Akcja</th></tr></thead><tbody>
            <?php foreach ($bannedIps as $ban): ?><tr>
                <td class="address"><?= e($ban['ip_address']) ?></td><td><?= e($ban['reason'] !== '' ? $ban['reason'] : '—') ?></td>
                <td><?= e(utc_datetime($ban['created_at'])) ?></td><td><form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="unban_ip">
                    <input type="hidden" name="ip_address" value="<?= e($ban['ip_address']) ?>"><button class="secondary" type="submit">Odblokuj</button>
                </form></td>
            </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <section class="panel"><h2>Historia odwiedzin (<?= number_format($visitorHistoryCount, 0, ',', ' ') ?> odsłon)</h2>
            <form method="get" class="actions">
                <input type="hidden" name="tab" value="visitors">
                <label>Filtruj historię po IP <input name="history_ip" maxlength="45" value="<?= e($visitorHistoryIpRaw) ?>" placeholder="IPv4 lub IPv6"></label>
                <button type="submit">Filtruj</button><a href="?tab=visitors">Wyczyść</a>
            </form>
            <?php if ($visitorHistoryIpRaw !== '' && $visitorHistoryIp === null): ?><p class="error">Podany filtr nie jest prawidłowym adresem IPv4 ani IPv6.</p><?php endif; ?>
            <?php if ($visitorHistory === []): ?><p class="empty">Brak zapisanej historii.</p><?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Data i czas</th><th>Adres IP</th><th>Odwiedzona strona</th></tr></thead><tbody>
            <?php foreach ($visitorHistory as $visit): ?><tr>
                <td><?= e(utc_datetime($visit['visited_at'])) ?></td><td class="address"><?= e($visit['ip_address']) ?></td><td><code><?= e($visit['path']) ?></code></td>
            </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            <?php if ($visitorHistoryPages > 1): ?><nav class="pager" aria-label="Strony historii">
                <?php if ($visitorHistoryPage > 1): ?><a href="?<?= e(http_build_query(['tab' => 'visitors', 'history_ip' => $visitorHistoryIpRaw, 'history_page' => $visitorHistoryPage - 1])) ?>">← Nowsze</a><?php endif; ?>
                <span class="muted">Strona <?= $visitorHistoryPage ?> z <?= $visitorHistoryPages ?></span>
                <?php if ($visitorHistoryPage < $visitorHistoryPages): ?><a href="?<?= e(http_build_query(['tab' => 'visitors', 'history_ip' => $visitorHistoryIpRaw, 'history_page' => $visitorHistoryPage + 1])) ?>">Starsze →</a><?php endif; ?>
            </nav><?php endif; ?>
        </section>
    <?php else: ?>
        <section class="panel"><h2>Import znanych list publicznych</h2>
            <p>Pobiera aktualne wpisy z pięciu publicznych źródeł, łączy duplikaty i dodaje nowe huby jako oczekujące na Twoją weryfikację.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_public_hubs"><button type="submit">Importuj publiczne huby</button></form>
        </section>
        <section class="panel"><h2>Ustawienia pingera</h2>
            <p>Pinger łączy się jawnie jako widoczny użytkownik. Huby wymagające hasła są oznaczane jako wymagające autoryzacji; pinger nie próbuje ich obchodzić. Ustawione hasła nie są wysyłane.</p>
            <form method="post" class="grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings">
                <label class="field">Nick pingera<input name="pinger_nick" maxlength="32" minlength="3" required value="<?= e($pingerSettings['pinger_nick']) ?>"></label>
                <label class="field">Opis pingera<input name="pinger_description" maxlength="80" value="<?= e($pingerSettings['pinger_description']) ?>"></label>
                <label class="field">Wersja klienta DC<input name="pinger_version" maxlength="40" required value="<?= e($pingerSettings['pinger_version']) ?>"></label>
                <label class="field">E-mail pingera<input name="pinger_email" type="email" maxlength="254" value="<?= e($pingerSettings['pinger_email']) ?>"></label>
                <label class="field">Typ łącza<input name="pinger_connection" maxlength="32" value="<?= e($pingerSettings['pinger_connection']) ?>"></label>
                <label class="field">Odstęp między pingami tego samego huba (minuty)<input name="pinger_interval" type="number" min="5" max="10080" required value="<?= e($pingerSettings['pinger_interval']) ?>"></label>
                <div class="field"><button type="submit">Zapisz ustawienia</button></div>
            </form>
            <p class="note">Typ łącza jest używany w opisie NMDC; ADC nie ma standardowego pola na ten parametr. Aby harmonogram z panelu działał dokładnie, skonfiguruj cron zgodnie z README tak, by uruchamiał pingera co minutę. Panel admina nie może samodzielnie zmienić crona hostingu.</p>
        </section>
        <section class="panel"><h2>Zmień hasło administratora</h2>
            <form method="post" class="grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_password">
                <label class="field full">Bieżące hasło<input name="current_password" type="password" autocomplete="current-password" required></label>
                <label class="field">Nowe hasło<input name="new_password" type="password" minlength="14" maxlength="72" autocomplete="new-password" required></label>
                <label class="field">Powtórz nowe hasło<input name="confirm_password" type="password" minlength="14" maxlength="72" autocomplete="new-password" required></label>
                <div class="field full"><button type="submit">Zmień hasło</button></div>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php page_end(); ?>

<?php
function render_hub_fields(?array $hub = null): void
{
    $hub ??= [];
    ?>
    <label class="field">Nazwa huba<input name="name" maxlength="150" required value="<?= e($hub['name'] ?? '') ?>"></label>
    <label class="field">Protokół<select name="protocol"><?php foreach (HUB_PROTOCOLS as $protocol): ?><option value="<?= e($protocol) ?>" <?= ($hub['protocol'] ?? 'ADC') === $protocol ? 'selected' : '' ?>><?= e($protocol) ?></option><?php endforeach; ?></select></label>
    <label class="field">Adres IP / host<input name="host" maxlength="253" required value="<?= e($hub['host'] ?? '') ?>"></label>
    <label class="field">Port<input name="port" type="number" min="1" max="65535" value="<?= e(isset($hub['port']) ? (string) $hub['port'] : '') ?>"></label>
    <label class="field">Kraj ISO<input name="country" maxlength="2" pattern="[A-Za-z]{2}" value="<?= e($hub['country'] ?? '') ?>"></label>
    <label class="field">Oprogramowanie serwera<input name="software" maxlength="120" value="<?= e($hub['software'] ?? '') ?>"></label>
    <label class="field full">Opis<textarea name="description" maxlength="5000"><?= e($hub['description'] ?? '') ?></textarea></label>
    <label class="field full">Strona HTTPS<input name="website" type="url" maxlength="500" value="<?= e($hub['website'] ?? '') ?>"></label>
    <label class="field full">Ikona huba HTTPS<input name="icon_url" type="url" maxlength="500" placeholder="https://example.org/hub-icon.png" value="<?= e($hub['icon_url'] ?? '') ?>"><small>Przeglądarka odwiedzającego pobierze ikonę z tego adresu HTTPS.</small></label>
    <?php
}

function render_download_fields(?array $download = null): void
{
    $download ??= [];
    ?>
    <label class="field">Kategoria<select name="category"><?php foreach (download_categories() as $value => $label): ?><option value="<?= e($value) ?>" <?= ($download['category'] ?? 'client') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="field">Nazwa<input name="name" maxlength="150" required value="<?= e($download['name'] ?? '') ?>"></label>
    <label class="field">Wersja<input name="version" maxlength="80" value="<?= e($download['version'] ?? '') ?>"></label>
    <label class="field">System / platforma<input name="platform" maxlength="150" placeholder="Windows, Linux, macOS…" value="<?= e($download['platform'] ?? '') ?>"></label>
    <label class="field">Kolejność<input name="sort_order" type="number" value="<?= e(isset($download['sort_order']) ? (string) $download['sort_order'] : '0') ?>"></label>
    <label class="field full">Oficjalna strona / pobieranie (HTTPS)<input name="website" type="url" maxlength="500" required value="<?= e($download['website'] ?? '') ?>"></label>
    <label class="field full">Opis<textarea name="description" maxlength="5000"><?= e($download['description'] ?? '') ?></textarea></label>
    <?php
}
?>
