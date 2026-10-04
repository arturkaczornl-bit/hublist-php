<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

$rawId = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$id = filter_var($rawId, FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    page_start('Nie znaleziono huba');
    echo '<main><section class="panel"><h1>Nie znaleziono huba</h1><p>Podany adres huba jest nieprawidłowy.</p><a href="index.php">Wróć do hublisty</a></section></main>';
    page_end();
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT h.*,
        (SELECT GROUP_CONCAT(s.source_name ORDER BY s.source_name SEPARATOR ', ')
         FROM hub_sources s WHERE s.hub_id=h.id) AS imported_sources,
        (SELECT ROUND(100 * AVG(p.is_online), 1) FROM ping_history p
         WHERE p.hub_id=h.id AND p.checked_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS uptime_30d,
        (SELECT COUNT(*) FROM ping_history p
         WHERE p.hub_id=h.id AND p.checked_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS checks_30d
     FROM hubs h WHERE h.id=? AND h.status='approved' LIMIT 1"
);
$stmt->execute([$id]);
$hub = $stmt->fetch();
if (!$hub) {
    http_response_code(404);
    page_start('Nie znaleziono huba');
    echo '<main><section class="panel"><h1>Nie znaleziono huba</h1><p>Hub nie istnieje lub nie został jeszcze opublikowany.</p><a href="index.php">Wróć do hublisty</a></section></main>';
    page_end();
    exit;
}

$online = $hub['pinger_status'] === 'online';
$secure = in_array($hub['protocol'], ['ADCS', 'NMDCS'], true);
$iconUrl = is_string($hub['icon_url']) && filter_var($hub['icon_url'], FILTER_VALIDATE_URL)
    && strtolower((string) parse_url($hub['icon_url'], PHP_URL_SCHEME)) === 'https'
    ? $hub['icon_url']
    : null;
$countryLabel = $hub['country'] ? $hub['country'] . ' ' . flag_emoji($hub['country']) : 'Nieustalony';
$uptime = $hub['uptime_30d'] === null
    ? 'Brak danych'
    : number_format((float) $hub['uptime_30d'], 1, ',', ' ') . '%';

page_start($hub['name'] . ' — szczegóły huba');
?>
<main>
    <p><a href="index.php">← Wróć do hublisty</a></p>
    <section class="panel">
        <div class="actions" style="align-items:center">
            <?php if ($iconUrl !== null): ?>
                <img class="hub-icon" src="<?= e($iconUrl) ?>" alt="Ikona huba <?= e($hub['name']) ?>" width="76" height="76">
            <?php else: ?>
                <span class="hub-icon" aria-hidden="true" style="display:grid;place-items:center;font-size:2rem">🌐</span>
            <?php endif; ?>
            <div style="flex:1;min-width:220px">
                <h1><span class="hub-flag" title="<?= e($countryLabel) ?>"><?= e(flag_emoji($hub['country'])) ?></span><?= e($hub['name']) ?></h1>
                <p class="muted"><?= e($hub['description'] ?: 'Brak opisu huba.') ?></p>
                <p><span class="protocol-icon <?= e(protocol_badge_class($hub['protocol'])) ?>"><span aria-hidden="true"><?= $secure ? '🔒' : '↔' ?></span><?= e($hub['protocol']) ?></span>
                    <span class="status-dot <?= $online ? 'is-online' : 'is-offline' ?>" aria-label="<?= $online ? 'Działa' : 'Nie działa' ?>"></span>
                    <strong class="<?= $online ? 'ok' : 'bad' ?>"><?= $online ? 'Działa' : 'Nie działa' ?></strong>
                    <span class="muted"> · <?= e(ping_status_text($hub['pinger_status'])) ?></span>
                </p>
            </div>
            <button type="button" class="button secondary" id="favorite-toggle" aria-pressed="false">☆ Dodaj do ulubionych</button>
        </div>
        <p id="favorite-message" class="muted" aria-live="polite"></p>
    </section>

    <?php if ($hub['hub_topic']): ?>
        <section class="panel">
            <h2>Temat huba</h2>
            <p class="topic"><?= e($hub['hub_topic']) ?></p>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Informacje o hubie</h2>
        <div class="hub-detail-grid">
            <div class="hub-detail"><strong>Adres huba</strong><span class="address"><a href="<?= e(hub_address($hub)) ?>"><?= e(hub_address($hub)) ?></a></span></div>
            <div class="hub-detail"><strong>Host</strong><span class="address"><?= e($hub['host']) ?></span></div>
            <div class="hub-detail"><strong>Port</strong><span><?= (int) $hub['port'] ?></span></div>
            <div class="hub-detail"><strong>Kraj według adresu IP</strong><span><?= e($countryLabel) ?><?= $hub['country_source'] === 'ip' ? ' · geolokalizacja IP' : '' ?></span></div>
            <div class="hub-detail"><strong>Nazwa z odpowiedzi huba</strong><span><?= e($hub['hub_name'] ?: 'Brak danych') ?></span></div>
            <div class="hub-detail"><strong>Oprogramowanie serwera</strong><span><?= e($hub['software'] ?: 'Niepodane') ?></span></div>
            <div class="hub-detail"><strong>Użytkownicy online</strong><span><?= $hub['online_users'] === null ? 'Brak danych' : number_format((int) $hub['online_users'], 0, ',', ' ') ?></span></div>
            <div class="hub-detail"><strong>Udostępniane pliki (łączny rozmiar)</strong><span><?= e(pretty_bytes($hub['shared_bytes'] === null ? null : (int) $hub['shared_bytes'])) ?></span></div>
            <div class="hub-detail"><strong>Odpowiedź pingera</strong><span><?= $hub['ping_ms'] === null ? 'Brak danych' : (int) $hub['ping_ms'] . ' ms' ?></span></div>
            <div class="hub-detail"><strong>Dostępność z 30 dni</strong><span><?= e($uptime) ?> · <?= number_format((int) $hub['checks_30d'], 0, ',', ' ') ?> pomiarów</span></div>
            <div class="hub-detail"><strong>Ostatnie sprawdzenie pingera</strong><span><?= $hub['last_ping_at'] === null ? 'Jeszcze nie sprawdzono' : e(utc_datetime($hub['last_ping_at'])) . ' (' . e(date_default_timezone_get()) . ')' ?></span></div>
            <div class="hub-detail"><strong>Źródło wpisu</strong><span><?= e($hub['imported_sources'] ?: 'Lista własna') ?></span></div>
            <div class="hub-detail"><strong>Połączenie TLS</strong><span><?php if (!$secure): ?>Protokół bez TLS
                <?php elseif ($hub['tls_cert_valid'] === null): ?>Nie sprawdzono
                <?php elseif ((int) $hub['tls_cert_valid'] === 1): ?>Certyfikat poprawny do <?= e(utc_datetime($hub['tls_cert_expires'])) ?>
                <?php else: ?>Certyfikat nieprawidłowy<?php endif; ?></span></div>
            <?php if ($secure && $hub['tls_cert_issuer']): ?><div class="hub-detail"><strong>Wystawca certyfikatu</strong><span><?= e($hub['tls_cert_issuer']) ?></span></div><?php endif; ?>
            <?php if ($hub['website']): ?><div class="hub-detail"><strong>Strona huba</strong><span><a href="<?= e($hub['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($hub['website']) ?></a></span></div><?php endif; ?>
        </div>
        <?php if ($hub['pinger_error']): ?><p class="note" style="margin-top:14px">Ostatni błąd pingera: <?= e($hub['pinger_error']) ?></p><?php endif; ?>
    </section>
    <section class="panel">
        <h2>Właściciel huba?</h2>
        <p>Użyj prywatnego kodu otrzymanego po zgłoszeniu, aby ręcznie uruchomić pomiar swojego huba. Możliwy jest jeden pomiar na hub co 5 minut.</p>
        <p><a class="button secondary" href="hub_owner.php?id=<?= (int) $hub['id'] ?>">Otwórz panel właściciela i pinguj</a></p>
    </section>
</main>
<script>
(() => {
    const button = document.getElementById('favorite-toggle');
    const message = document.getElementById('favorite-message');
    const key = 'hublist:favorites';
    const id = <?= (int) $hub['id'] ?>;
    let favorites;
    try {
        favorites = JSON.parse(localStorage.getItem(key) || '[]');
        if (!Array.isArray(favorites)) {
            throw new Error('Nieprawidłowy zapis ulubionych.');
        }
    } catch (error) {
        button.disabled = true;
        message.textContent = 'Nie można odczytać ulubionych w tej przeglądarce.';
        return;
    }
    const updateButton = () => {
        const saved = favorites.includes(id);
        button.textContent = saved ? '★ Usuń z ulubionych' : '☆ Dodaj do ulubionych';
        button.setAttribute('aria-pressed', saved ? 'true' : 'false');
    };
    updateButton();
    button.addEventListener('click', () => {
        favorites = favorites.includes(id)
            ? favorites.filter((favoriteId) => favoriteId !== id)
            : [...favorites, id];
        try {
            localStorage.setItem(key, JSON.stringify(favorites));
            updateButton();
            message.textContent = favorites.includes(id)
                ? 'Hub zapisany w ulubionych tej przeglądarki.'
                : 'Hub usunięty z ulubionych tej przeglądarki.';
        } catch (error) {
            message.textContent = 'Nie udało się zapisać ulubionych w tej przeglądarce.';
        }
    });
})();
</script>
<?php page_end(); ?>
