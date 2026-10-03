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
                    $stmt = $pdo->prepare('UPDATE hubs SET name=?,protocol=?,host=?,port=?,country=?,description=?,software=?,website=?,status=?,verified_at=IF(?="approved",COALESCE(verified_at,UTC_TIMESTAMP()),NULL)' . $resetPing . ' WHERE id=?');
                    $stmt->execute([
                        $hub['name'], $hub['protocol'], $hub['host'], $hub['port'], $hub['country'],
                        $hub['description'], $hub['software'], $hub['website'], $status, $status, $id,
                    ]);
                    $notice = 'Dane huba zaktualizowane.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO hubs (name,protocol,host,port,country,description,software,website,status,verified_at) VALUES (?,?,?,?,?,?,?,?,?,IF(?="approved",UTC_TIMESTAMP(),NULL))');
                    $stmt->execute([
                        $hub['name'], $hub['protocol'], $hub['host'], $hub['port'], $hub['country'],
                        $hub['description'], $hub['software'], $hub['website'], $status, $status,
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
                $websiteScheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
                $websiteHost = strtolower((string) parse_url($website, PHP_URL_HOST));
                $safeWebsite = filter_var($website, FILTER_VALIDATE_URL)
                    && ($websiteScheme === 'https' || ($websiteScheme === 'http' && $websiteHost === 'www.ptokax.org'));
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
            } elseif ($action === 'delete_download') {
                $id = filter_var(post_string($_POST, 'id'), FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('Nieprawidłowy identyfikator wpisu.');
                }
                $pdo->prepare('DELETE FROM downloads WHERE id=?')->execute([$id]);
                $notice = 'Wpis katalogu został usunięty.';
            } elseif ($action === 'save_settings') {
                $nick = trim(post_string($_POST, 'pinger_nick'));
                if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $nick)) {
                    throw new InvalidArgumentException('Nick pingera może zawierać 3–32 litery, cyfry, kropki, myślniki i podkreślenia.');
                }
                $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('pinger_nick',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
                $stmt->execute([$nick]);
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
if (!in_array($tab, ['pending', 'hubs', 'downloads', 'settings'], true)) {
    $tab = 'pending';
}

if (!admin_logged_in()) {
    page_start('Logowanie administratora');
    ?>
    <main><section class="panel" style="max-width:520px;margin:48px auto">
        <h1>Panel administratora</h1>
        <p class="muted">Zaloguj się, aby zatwierdzać zgłoszenia, zarządzać hubami i edytować katalog download.</p>
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
$pingerStmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='pinger_nick'");
$pingerNick = (string) ($pingerStmt->fetchColumn() ?: 'Hublist-Pinger');
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
            <a href="?tab=downloads">Download</a>
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
        <section class="panel"><h2>Katalog download</h2><div class="table-wrap"><table>
        <thead><tr><th>Kategoria</th><th>Nazwa i wersja</th><th>Oficjalny link</th><th>Akcje</th></tr></thead><tbody>
        <?php foreach ($downloadRows as $item): ?><tr>
            <td><?= e(download_categories()[$item['category']] ?? $item['category']) ?></td>
            <td><?= e($item['name']) ?><small><?= e($item['version']) ?> · <?= e($item['platform']) ?></small></td>
            <td><a href="<?= e($item['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['website']) ?></a></td>
            <td><div class="actions"><a class="button secondary" href="?tab=downloads&amp;edit_download=<?= (int) $item['id'] ?>">Edytuj</a><form method="post" onsubmit="return confirm('Usunąć wpis z katalogu?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <button class="secondary" name="action" value="delete_download">Usuń</button>
            </form></div></td>
        </tr><?php endforeach; ?>
        </tbody></table></div></section>
    <?php else: ?>
        <section class="panel"><h2>Import znanych list publicznych</h2>
            <p>Pobiera aktualne wpisy z pięciu publicznych źródeł, łączy duplikaty i dodaje nowe huby jako oczekujące na Twoją weryfikację.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_public_hubs"><button type="submit">Importuj publiczne huby</button></form>
        </section>
        <section class="panel"><h2>Ustawienia pingera</h2>
            <p>Uruchamiaj `php pinger.php --limit=500` w zadaniu cron co 48 minut. Pinger loguje się do hubów jako widoczny użytkownik; huby wymagające hasła oznacza jako wymagające autoryzacji.</p>
            <form method="post" class="grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings">
                <label class="field">Nick pingera<input name="pinger_nick" maxlength="32" minlength="3" required value="<?= e($pingerNick) ?>"></label>
                <div class="field"><button type="submit">Zapisz ustawienia</button></div>
            </form>
            <p class="note">Panel admina nie może samodzielnie ustawić crona. W panelu hostingu dodaj polecenie wskazane w README. Weryfikuj nowe zgłoszenia przed publikacją.</p>
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
