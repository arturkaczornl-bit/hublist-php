<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
$pdo = db();

$summary = $pdo->query(
    "SELECT COUNT(*) AS checks,
        COALESCE(SUM(is_online),0) AS online_checks,
        COUNT(DISTINCT hub_id) AS hubs_checked
     FROM ping_history WHERE checked_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY"
)->fetch();
$topHubs = $pdo->query(
    "SELECT h.id,h.name,h.protocol,h.host,h.port,h.online_users,h.shared_bytes,
            h.last_ping_at,h.pinger_status,s.uptime_30d,s.checks
     FROM hubs h
     JOIN (
        SELECT hub_id,ROUND(100 * AVG(is_online),1) AS uptime_30d,COUNT(*) AS checks
        FROM ping_history
        WHERE checked_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
        GROUP BY hub_id
        HAVING COUNT(*) >= 3
     ) s ON s.hub_id=h.id
     WHERE h.status='approved'
     ORDER BY s.uptime_30d DESC,s.checks DESC,h.name ASC
     LIMIT 50"
)->fetchAll();

page_start('Statystyki hubów Direct Connect');
?>
<main>
    <section class="hero">
        <span class="hero-kicker">Dane z monitoringu Hublist.pl</span>
        <h1>Statystyki hubów</h1>
        <p>Porównaj stabilność zatwierdzonych hubów na podstawie wyników pingera z ostatnich 30 dni.</p>
    </section>
    <section class="cards" aria-label="Podsumowanie monitoringu">
        <div class="card"><strong><?= number_format((int) ($summary['hubs_checked'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">hubów sprawdzonych w 30 dni</span></div>
        <div class="card"><strong><?= number_format((int) ($summary['checks'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">wykonanych pomiarów</span></div>
        <div class="card"><strong><?= number_format((int) ($summary['online_checks'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">udanych odpowiedzi</span></div>
        <div class="card"><strong><?= (int) ($summary['checks'] ?? 0) > 0
            ? number_format(100 * (int) $summary['online_checks'] / (int) $summary['checks'], 1, ',', ' ') . '%'
            : '—' ?></strong><span class="muted">odpowiedzi online ogółem</span></div>
    </section>
    <section class="panel">
        <h2>Najwyższa dostępność w ostatnich 30 dniach</h2>
        <p class="note">Ranking liczy udział udanych odpowiedzi pingera w zapisanych pomiarach. Pokazujemy huby z co najmniej 3 pomiarami; odsetek nie jest gwarancją przyszłej dostępności. „Ostatnie sprawdzenie” oznacza czas ostatniego wejścia pingera, także gdy hub był offline.</p>
        <?php if ($topHubs === []): ?>
            <p class="empty">Brak wystarczających danych. Ranking pojawi się po zebraniu co najmniej 3 pomiarów dla huba.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>#</th><th>Hub</th><th>Adres</th><th>Protokół</th><th>Dostępność 30 dni</th><th>Pomiary</th><th>Użytkownicy</th><th>Share</th><th>Ostatnie sprawdzenie pingera</th></tr></thead>
            <tbody><?php foreach ($topHubs as $position => $hub): ?>
                <tr>
                    <td><?= $position + 1 ?></td>
                    <td><a class="hub-name" href="hub.php?id=<?= (int) $hub['id'] ?>"><?= e($hub['name']) ?></a>
                        <small><?= $hub['pinger_status'] === 'online' ? '<span class="ok">Działa</span>' : '<span class="bad">Offline / brak połączenia</span>' ?></small></td>
                    <td class="address"><?= e($hub['host']) ?>:<?= (int) $hub['port'] ?></td>
                    <td><span class="protocol-icon <?= e(protocol_badge_class($hub['protocol'])) ?>"><?= e($hub['protocol']) ?></span></td>
                    <td><strong><?= number_format((float) $hub['uptime_30d'], 1, ',', ' ') ?>%</strong></td>
                    <td><?= number_format((int) $hub['checks'], 0, ',', ' ') ?></td>
                    <td><?= $hub['online_users'] === null ? '—' : number_format((int) $hub['online_users'], 0, ',', ' ') ?></td>
                    <td><?= e(pretty_bytes($hub['shared_bytes'] === null ? null : (int) $hub['shared_bytes'])) ?></td>
                    <td><?= e(utc_datetime($hub['last_ping_at'])) ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </section>
</main>
<?php page_end(); ?>
