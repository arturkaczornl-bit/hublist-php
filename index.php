<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
start_app_session();
$pdo = db();

$query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$query = substr($query, 0, 100);
$protocolFilter = strtoupper(trim(is_string($_GET['protocol'] ?? null) ? $_GET['protocol'] : ''));
if (!in_array($protocolFilter, HUB_PROTOCOLS, true)) {
    $protocolFilter = '';
}
$countryFilter = strtoupper(trim(is_string($_GET['country'] ?? null) ? $_GET['country'] : ''));
if (!preg_match('/^[A-Z]{2}$/', $countryFilter)) {
    $countryFilter = '';
}
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($statusFilter, ['online', 'offline', 'unchecked'], true)) {
    $statusFilter = '';
}
$serverFilter = trim(is_string($_GET['server'] ?? null) ? $_GET['server'] : '');
$serverFilter = substr($serverFilter, 0, 100);
$portFilter = filter_var(is_string($_GET['port'] ?? null) ? $_GET['port'] : '', FILTER_VALIDATE_INT);
if ($portFilter === false || $portFilter < 1 || $portFilter > 65535) {
    $portFilter = null;
}
$minUsers = filter_var(is_string($_GET['users_min'] ?? null) ? $_GET['users_min'] : '', FILTER_VALIDATE_INT);
$maxUsers = filter_var(is_string($_GET['users_max'] ?? null) ? $_GET['users_max'] : '', FILTER_VALIDATE_INT);
if ($minUsers === false || $minUsers < 0) {
    $minUsers = null;
}
if ($maxUsers === false || $maxUsers < 0) {
    $maxUsers = null;
}
$parseShareGb = static function (mixed $value): ?float {
    if (!is_string($value) || $value === '') {
        return null;
    }
    $number = filter_var($value, FILTER_VALIDATE_FLOAT);
    return $number !== false && is_finite((float) $number) && $number >= 0 && $number <= 1000000000
        ? (float) $number : null;
};
$minShareGb = $parseShareGb($_GET['share_min'] ?? null);
$maxShareGb = $parseShareGb($_GET['share_max'] ?? null);
$filterError = '';
if ($minUsers !== null && $maxUsers !== null && $minUsers > $maxUsers) {
    $filterError = 'Minimalna liczba użytkowników nie może być większa od maksymalnej.';
    $minUsers = $maxUsers = null;
}
if ($minShareGb !== null && $maxShareGb !== null && $minShareGb > $maxShareGb) {
    $filterError = 'Minimalny share nie może być większy od maksymalnego.';
    $minShareGb = $maxShareGb = null;
}
$tlsOnly = (is_string($_GET['tls'] ?? null) && $_GET['tls'] === '1');
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'relevance';
if (!in_array($sort, ['relevance', 'users', 'share', 'uptime', 'ping', 'last_ping', 'name'], true)) {
    $sort = 'relevance';
}
$rawPage = $_GET['page'] ?? '1';
$pageNumber = filter_var(is_scalar($rawPage) ? (string) $rawPage : '1', FILTER_VALIDATE_INT);
$pageNumber = $pageNumber === false ? 1 : max(1, $pageNumber);
$pageSize = 30;
$where = ["h.status = 'approved'"];
$parameters = [];
if ($protocolFilter !== '') {
    $where[] = 'h.protocol = ?';
    $parameters[] = $protocolFilter;
}
if ($query !== '') {
    $where[] = '(h.name LIKE ? OR h.host LIKE ? OR CONCAT(h.host, \':\', h.port) LIKE ? OR CAST(h.port AS CHAR) LIKE ? OR h.description LIKE ? OR h.software LIKE ? OR h.hub_name LIKE ? OR h.hub_topic LIKE ?)';
    $needle = '%' . $query . '%';
    array_push($parameters, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
}
if ($countryFilter !== '') {
    $where[] = 'h.country = ?';
    $parameters[] = $countryFilter;
}
if ($statusFilter === 'online') {
    $where[] = "h.pinger_status = 'online'";
} elseif ($statusFilter === 'offline') {
    $where[] = "(h.pinger_status IS NOT NULL AND h.pinger_status <> 'online')";
} elseif ($statusFilter === 'unchecked') {
    $where[] = 'h.pinger_status IS NULL';
}
if ($serverFilter !== '') {
    $where[] = '(h.software LIKE ? OR h.hub_name LIKE ?)';
    $needle = '%' . $serverFilter . '%';
    array_push($parameters, $needle, $needle);
}
if ($portFilter !== null) {
    $where[] = 'h.port = ?';
    $parameters[] = $portFilter;
}
if ($minUsers !== null) {
    $where[] = 'h.online_users >= ?';
    $parameters[] = $minUsers;
}
if ($maxUsers !== null) {
    $where[] = 'h.online_users <= ?';
    $parameters[] = $maxUsers;
}
if ($minShareGb !== null) {
    $where[] = 'h.shared_bytes >= ?';
    $parameters[] = (int) round($minShareGb * 1073741824);
}
if ($maxShareGb !== null) {
    $where[] = 'h.shared_bytes <= ?';
    $parameters[] = (int) round($maxShareGb * 1073741824);
}
if ($tlsOnly) {
    $where[] = 'h.tls_cert_valid = 1';
}
$whereSql = implode(' AND ', $where);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM hubs h WHERE $whereSql");
$countStmt->execute($parameters);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
$pageNumber = min($pageNumber, $pages);
$offset = ($pageNumber - 1) * $pageSize;
$orderSql = match ($sort) {
    'users' => 'h.online_users DESC, h.name ASC',
    'share' => 'h.shared_bytes DESC, h.name ASC',
    'uptime' => 'uptime_30d DESC, h.name ASC',
    'ping' => '(h.ping_ms IS NULL) ASC, h.ping_ms ASC, h.name ASC',
    'last_ping' => 'h.last_ping_at DESC, h.name ASC',
    'name' => 'h.name ASC',
    default => "(h.pinger_status = 'online') DESC, h.online_users DESC, h.name ASC",
};

$hubsStmt = $pdo->prepare(
    "SELECT h.*,
        (SELECT GROUP_CONCAT(s.source_name ORDER BY s.source_name SEPARATOR ', ')
         FROM hub_sources s WHERE s.hub_id=h.id) AS imported_sources,
        (SELECT ROUND(100 * AVG(p.is_online), 1) FROM ping_history p
         WHERE p.hub_id = h.id AND p.checked_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS uptime_30d
     FROM hubs h WHERE $whereSql
     ORDER BY $orderSql
     LIMIT $pageSize OFFSET $offset"
);
$hubsStmt->execute($parameters);
$hubs = $hubsStmt->fetchAll();
$stats = $pdo->query(
    "SELECT SUM(status='approved') AS total,
        SUM(status='approved' AND pinger_status = 'online') AS online,
        SUM(status='approved' AND tls_cert_valid = 1) AS secure,
        SUM(status = 'pending') AS pending
     FROM hubs"
)->fetch();
$pendingStmt = $pdo->query(
    "SELECT name, protocol, host, port, country, created_at
     FROM hubs WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5"
);
$pending = $pendingStmt->fetchAll();
page_start('Publiczna lista hubów Direct Connect');
?>
<main>
    <section class="hero home-hero">
        <span class="hero-kicker">Polska hublista Direct Connect · Hublist.pl</span>
        <h1>Polska hublista Direct Connect</h1>
        <p>Łączymy polską społeczność Direct Connect. Znajdź hub, sprawdź jego status i dołącz do rozmów, wymiany legalnych plików oraz wspólnych zainteresowań.</p>
        <div class="actions" style="margin-top:14px">
            <a class="button" href="hublist.xml" download>Pobierz listę XML</a>
            <a class="button secondary" href="hublist.xml.bz2" download>Pobierz XML BZip2 (.bz2)</a>
            <a class="button secondary" href="add_hub.php">Dodaj hub</a>
        </div>
        <p class="note" style="margin-top:16px">Polska społeczność DC — otwarte huby, sprawdzony status i lista gotowa dla Twojego klienta.</p>
    </section>
    <section class="panel" id="feed">
        <h2>Dodaj tę hublistę do klienta Direct Connect</h2>
        <p>W ustawieniach listy hubów w kliencie dodaj poniższy adres. Feed zawiera wyłącznie zatwierdzone huby i jest aktualizowany na bieżąco.</p>
        <p><strong>XML:</strong> <a href="hublist.xml"><code>hublist.xml</code></a> (bezpośredni endpoint: <a href="feed.php?format=xml"><code>feed.php?format=xml</code></a>).</p>
        <p><strong>XML skompresowany BZip2:</strong> <a href="hublist.xml.bz2"><code>hublist.xml.bz2</code></a> (bezpośredni endpoint: <a href="feed.php?format=bz2"><code>feed.php?format=bz2</code></a>).</p>
        <p class="note">Wklej pełny adres linku XML w ustawieniach hublisty swojego klienta. Feed zawiera tylko zatwierdzone wpisy.</p>
    </section>
    <section class="cards" aria-label="Statystyki listy">
        <div class="card"><strong><?= number_format((int) ($stats['total'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">zatwierdzonych hubów</span></div>
        <div class="card"><strong class="ok"><?= number_format((int) ($stats['online'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">ostatnio dostępnych</span></div>
        <div class="card"><strong><?= number_format((int) ($stats['secure'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">z poprawnym certyfikatem TLS</span></div>
        <div class="card"><strong><?= number_format(count($pending), 0, ',', ' ') ?></strong><span class="muted">najnowszych zgłoszeń w kolejce</span></div>
    </section>

    <section class="panel">
        <h2>Profesjonalna wyszukiwarka hubów</h2>
        <p class="muted">Szukaj po nazwie, adresie/IP, porcie, opisie, temacie lub oprogramowaniu huba. Łącz filtry protokołu, kraju, statusu, liczby użytkowników, share i dostępności TLS.</p>
        <?php if ($filterError !== ''): ?><p class="error"><?= e($filterError) ?></p><?php endif; ?>
        <form method="get" class="grid">
            <label class="field full">Szukana fraza<input type="search" name="q" value="<?= e($query) ?>" maxlength="100" placeholder="Nazwa huba, domena, adres IP, port, temat lub opis"></label>
            <label class="field">Protokół<select name="protocol">
                <option value="">Wszystkie protokoły</option>
                <?php foreach (HUB_PROTOCOLS as $protocol): ?>
                    <option value="<?= e($protocol) ?>" <?= $protocolFilter === $protocol ? 'selected' : '' ?>><?= e($protocol) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label class="field">Status<select name="status">
                <option value="">Dowolny status</option>
                <option value="online" <?= $statusFilter === 'online' ? 'selected' : '' ?>>Działa</option>
                <option value="offline" <?= $statusFilter === 'offline' ? 'selected' : '' ?>>Nie działa</option>
                <option value="unchecked" <?= $statusFilter === 'unchecked' ? 'selected' : '' ?>>Nie sprawdzono</option>
            </select></label>
            <label class="field">Kraj (kod ISO)<input name="country" maxlength="2" pattern="[A-Za-z]{2}" value="<?= e($countryFilter) ?>" placeholder="PL"></label>
            <label class="field">Port huba<input name="port" type="number" min="1" max="65535" value="<?= e($portFilter === null ? '' : (string) $portFilter) ?>" placeholder="np. 411"></label>
            <label class="field">Serwer / hubsoft<input name="server" maxlength="100" value="<?= e($serverFilter) ?>" placeholder="np. Verlihub, PtokaX"></label>
            <label class="field">Użytkownicy — minimum<input name="users_min" type="number" min="0" value="<?= e($minUsers === null ? '' : (string) $minUsers) ?>"></label>
            <label class="field">Użytkownicy — maksimum<input name="users_max" type="number" min="0" value="<?= e($maxUsers === null ? '' : (string) $maxUsers) ?>"></label>
            <label class="field">Share — minimum (GiB)<input name="share_min" type="number" min="0" step="0.1" value="<?= e($minShareGb === null ? '' : (string) $minShareGb) ?>"></label>
            <label class="field">Share — maksimum (GiB)<input name="share_max" type="number" min="0" step="0.1" value="<?= e($maxShareGb === null ? '' : (string) $maxShareGb) ?>"></label>
            <label class="field">Sortowanie<select name="sort">
                <option value="relevance" <?= $sort === 'relevance' ? 'selected' : '' ?>>Domyślne</option>
                <option value="users" <?= $sort === 'users' ? 'selected' : '' ?>>Najwięcej użytkowników</option>
                <option value="share" <?= $sort === 'share' ? 'selected' : '' ?>>Największy share</option>
                <option value="uptime" <?= $sort === 'uptime' ? 'selected' : '' ?>>Najwyższa dostępność (30 dni)</option>
                <option value="ping" <?= $sort === 'ping' ? 'selected' : '' ?>>Najniższy ping</option>
                <option value="last_ping" <?= $sort === 'last_ping' ? 'selected' : '' ?>>Ostatnio sprawdzane</option>
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Nazwa A–Z</option>
            </select></label>
            <label class="field"><span><input type="checkbox" name="tls" value="1" <?= $tlsOnly ? 'checked' : '' ?>> Tylko poprawny certyfikat TLS</span></label>
            <div class="field"><button type="submit">Szukaj hubów</button> <a href="index.php">Wyczyść filtry</a></div>
        </form>
        <p class="muted"><?= number_format($total, 0, ',', ' ') ?> wyników; lista pokazuje do 30 hubów na stronę.</p>
        <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Kraj / hub</th><th>Stan</th><th>Protokół</th><th>Adres i port</th><th>Ping</th>
                <th>Użytkownicy / share</th><th>Dostępność 30 dni</th><th>Certyfikat</th><th>Serwer</th><th>Źródła</th>
            </tr></thead>
            <tbody>
            <?php foreach ($hubs as $hub):
                $online = $hub['pinger_status'] === 'online';
                $secure = in_array($hub['protocol'], ['ADCS', 'NMDCS'], true);
                ?>
                <tr>
                    <td>
                        <span class="hub-flag" title="<?= e($hub['country'] ?: 'Kraj nieustalony') ?>"><?= e(flag_emoji($hub['country'])) ?></span>
                        <a class="hub-name" href="hub.php?id=<?= (int) $hub['id'] ?>"><?= e($hub['name']) ?></a>
                        <small><?= e($hub['description']) ?></small>
                    </td>
                    <td><span class="status-dot <?= $online ? 'is-online' : 'is-offline' ?>" aria-label="<?= $online ? 'Działa' : 'Nie działa' ?>" title="<?= e(ping_status_text($hub['pinger_status'])) ?>"></span><?= $online ? '<span class="ok">Działa</span>' : '<span class="bad">Nie działa</span>' ?>
                        <small><?= e(ping_status_text($hub['pinger_status'])) ?></small></td>
                    <td><span class="protocol-icon <?= e(protocol_badge_class($hub['protocol'])) ?>"><span aria-hidden="true"><?= in_array($hub['protocol'], ['ADCS', 'NMDCS'], true) ? '🔒' : '↔' ?></span><?= e($hub['protocol']) ?></span></td>
                    <td><a class="address" href="<?= e(hub_address($hub)) ?>"><?= e($hub['host']) ?></a><small>Port <?= (int) $hub['port'] ?></small></td>
                    <td><?= $hub['ping_ms'] === null ? '—' : (int) $hub['ping_ms'] . ' ms' ?>
                        <small><?= e(utc_datetime($hub['last_ping_at'])) ?></small>
                        <?php if ($hub['pinger_error']): ?><small title="<?= e($hub['pinger_error']) ?>"><?= e(substr((string) $hub['pinger_error'], 0, 90)) ?></small><?php endif; ?>
                    </td>
                    <td><?= $hub['online_users'] === null ? '—' : number_format((int) $hub['online_users'], 0, ',', ' ') ?>
                        <small><?= e(pretty_bytes($hub['shared_bytes'] === null ? null : (int) $hub['shared_bytes'])) ?></small>
                    </td>
                    <td><?= $hub['uptime_30d'] === null ? '—' : number_format((float) $hub['uptime_30d'], 1, ',', ' ') . '%' ?></td>
                    <td><?php if (!$secure): ?>Nie dotyczy
                        <?php elseif ($hub['tls_cert_valid'] === null): ?>Nie sprawdzono
                        <?php elseif ((int) $hub['tls_cert_valid'] === 1): ?><span class="ok">Poprawny</span><small>do <?= e(utc_datetime($hub['tls_cert_expires'])) ?></small>
                        <?php else: ?><span class="bad">Nieprawidłowy</span><?php endif; ?>
                    </td>
                    <td><?= e($hub['software'] ?: '—') ?>
                        <?php if ($hub['hub_name']): ?><small><?= e($hub['hub_name']) ?></small><?php endif; ?>
                    </td>
                    <td><?= e($hub['imported_sources'] ?: 'Lista własna') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($hubs === []): ?><tr><td colspan="10" class="empty">Brak zatwierdzonych hubów spełniających filtr. Zgłoś pierwszy hub poniżej lub zatwierdź wpisy oczekujące.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Strony listy">
                <?php if ($pageNumber > 1): ?><a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $pageNumber - 1]))) ?>">← Poprzednie 30</a><?php endif; ?>
                <span class="muted">Strona <?= $pageNumber ?> z <?= $pages ?> · <?= number_format($total, 0, ',', ' ') ?> hubów</span>
                <?php if ($pageNumber < $pages): ?><a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $pageNumber + 1]))) ?>">Następne 30 →</a><?php endif; ?>
            </nav>
        <?php else: ?><p class="muted"><?= number_format($total, 0, ',', ' ') ?> hubów. Strona wyświetla do 30 wyników; puste wpisy nie są sztucznie dodawane.</p><?php endif; ?>
    </section>

    <section class="panel" id="oczekujace">
        <h2>5 najnowszych zgłoszeń oczekujących na weryfikację</h2>
        <?php if ($pending === []): ?><p class="empty">Brak zgłoszeń oczekujących na sprawdzenie.</p><?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Hub</th><th>Adres</th><th>Protokół</th><th>Dodano</th></tr></thead>
                <tbody><?php foreach ($pending as $item): ?><tr>
                    <td><?= e($item['name']) ?></td><td class="address"><?= e($item['host']) ?>:<?= (int) $item['port'] ?></td>
                    <td><span class="proto"><?= e($item['protocol']) ?></span></td><td><?= e(utc_datetime($item['created_at'])) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel" id="download">
        <h2>Pobieralnia — klienci, huby, skrypty i narzędzia</h2>
        <p>Przejdź do katalogu pobierania: serwery hubów, klienci Direct Connect, skrypty Lua oraz inne narzędzia.</p>
        <div class="actions">
            <?php foreach (download_categories() as $category => $label): ?>
                <a class="button secondary" href="download.php?category=<?= e($category) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <a class="button" href="download.php">Otwórz pobieralnię</a>
        </div>
    </section>
</main>
<?php page_end(); ?>
