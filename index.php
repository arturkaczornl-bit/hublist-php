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
    $where[] = '(h.name LIKE ? OR h.host LIKE ? OR h.description LIKE ? OR h.software LIKE ?)';
    $needle = '%' . $query . '%';
    array_push($parameters, $needle, $needle, $needle, $needle);
}
$whereSql = implode(' AND ', $where);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM hubs h WHERE $whereSql");
$countStmt->execute($parameters);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
$pageNumber = min($pageNumber, $pages);
$offset = ($pageNumber - 1) * $pageSize;

$hubsStmt = $pdo->prepare(
    "SELECT h.*,
        (SELECT GROUP_CONCAT(s.source_name ORDER BY s.source_name SEPARATOR ', ')
         FROM hub_sources s WHERE s.hub_id=h.id) AS imported_sources,
        (SELECT ROUND(100 * AVG(p.is_online), 1) FROM ping_history p
         WHERE p.hub_id = h.id AND p.checked_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS uptime_30d
     FROM hubs h WHERE $whereSql
     ORDER BY (h.pinger_status = 'online') DESC, h.online_users DESC, h.name ASC
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
        <h2>Znajdź hub</h2>
        <form method="get">
            <input type="search" name="q" value="<?= e($query) ?>" maxlength="100" placeholder="Nazwa, adres IP, opis lub oprogramowanie" aria-label="Szukaj huba">
            <select name="protocol" aria-label="Wybierz protokół">
                <option value="">Wszystkie protokoły</option>
                <?php foreach (HUB_PROTOCOLS as $protocol): ?>
                    <option value="<?= e($protocol) ?>" <?= $protocolFilter === $protocol ? 'selected' : '' ?>><?= e($protocol) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Szukaj</button>
        </form>
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
                <?php if ($pageNumber > 1): ?><a href="?<?= e(http_build_query(['q' => $query, 'protocol' => $protocolFilter, 'page' => $pageNumber - 1])) ?>">← Poprzednie 30</a><?php endif; ?>
                <span class="muted">Strona <?= $pageNumber ?> z <?= $pages ?> · <?= number_format($total, 0, ',', ' ') ?> hubów</span>
                <?php if ($pageNumber < $pages): ?><a href="?<?= e(http_build_query(['q' => $query, 'protocol' => $protocolFilter, 'page' => $pageNumber + 1])) ?>">Następne 30 →</a><?php endif; ?>
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
