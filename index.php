<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
$pdo = db();
$stats = $pdo->query(
    "SELECT SUM(status='approved') AS total,
        SUM(status='approved' AND pinger_status='online') AS online,
        SUM(status='approved' AND tls_cert_valid=1) AS secure,
        SUM(status='pending') AS pending
     FROM hubs"
)->fetch();
$pending = $pdo->query(
    "SELECT name,protocol,host,port,country,created_at
     FROM hubs WHERE status='pending' ORDER BY created_at DESC LIMIT 5"
)->fetchAll();

page_start('Polska hublista Direct Connect');
?>
<main>
    <section class="hero home-hero">
        <span class="hero-kicker">Polska hublista Direct Connect · Hublist.pl</span>
        <h1>Polska hublista Direct Connect</h1>
        <p>Łączymy polską społeczność Direct Connect. Znajdź hub, sprawdź jego status i dołącz do rozmów, wymiany legalnych plików oraz wspólnych zainteresowań.</p>
        <div class="actions" style="margin-top:14px">
            <a class="button" href="search.php">Przeglądaj i wyszukuj huby</a>
            <a class="button secondary" href="stats.php">Statystyki hubów</a>
            <a class="button secondary" href="add_hub.php">Dodaj hub</a>
        </div>
        <p class="note" style="margin-top:16px">🇵🇱 Polska społeczność DC — otwarte huby, sprawdzony status i lista gotowa dla Twojego klienta.</p>
    </section>

    <section class="cards" aria-label="Statystyki listy">
        <div class="card"><strong><?= number_format((int) ($stats['total'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">zatwierdzonych hubów</span></div>
        <div class="card"><strong class="ok"><?= number_format((int) ($stats['online'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">ostatnio dostępnych</span></div>
        <div class="card"><strong><?= number_format((int) ($stats['secure'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">z poprawnym certyfikatem TLS</span></div>
        <div class="card"><strong><?= number_format((int) ($stats['pending'] ?? 0), 0, ',', ' ') ?></strong><span class="muted">zgłoszeń oczekujących</span></div>
    </section>

    <section class="panel">
        <h2>Feed dla klientów Direct Connect</h2>
        <p>Dodaj adres listy hubów do ustawień swojego klienta DC. Feed zawiera wyłącznie zatwierdzone huby.</p>
        <p><strong>XML:</strong> <a href="hublist.xml"><code>https://hublist.pl/hublist.xml</code></a></p>
        <p><strong>XML BZip2:</strong> <a href="hublist.xml.bz2"><code>https://hublist.pl/hublist.xml.bz2</code></a></p>
        <div class="actions">
            <a class="button" href="feed.php?format=xml">Pobierz XML</a>
            <a class="button secondary" href="feed.php?format=bz2">Pobierz XML BZip2</a>
        </div>
    </section>

    <section class="panel">
        <h2>Ostatnie zgłoszenia hubów</h2>
        <?php if ($pending === []): ?><p class="empty">Brak zgłoszeń oczekujących na sprawdzenie.</p><?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Hub</th><th>Adres</th><th>Protokół</th><th>Dodano</th></tr></thead>
                <tbody><?php foreach ($pending as $item): ?><tr>
                    <td><?= e($item['name']) ?></td>
                    <td class="address"><?= e($item['host']) ?>:<?= (int) $item['port'] ?></td>
                    <td><span class="proto"><?= e($item['protocol']) ?></span></td>
                    <td><?= e(utc_datetime($item['created_at'])) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
        <p><a href="add_hub.php">Zgłoś hub do weryfikacji →</a></p>
    </section>

    <section class="panel">
        <h2>Pobieralnia</h2>
        <p>Klienci Direct Connect, serwery hubów, skrypty i narzędzia.</p>
        <div class="actions">
            <?php foreach (download_categories() as $category => $label): ?>
                <a class="button secondary" href="download.php?category=<?= e($category) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <a class="button" href="download.php">Otwórz pobieralnię</a>
        </div>
    </section>
</main>
<?php page_end(); ?>
