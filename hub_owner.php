<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
start_app_session();

$id = filter_var(is_string($_GET['id'] ?? null) ? $_GET['id'] : '', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    page_start('Panel właściciela huba');
    echo '<main><section class="panel"><h1>Nie znaleziono huba</h1><p>Nieprawidłowy identyfikator.</p></section></main>';
    page_end();
    exit;
}

$ownerCode = is_string($_SESSION['owner_hub_tokens'][$id] ?? null)
    ? strtolower($_SESSION['owner_hub_tokens'][$id])
    : '';
$stmt = db()->prepare("SELECT id,name,protocol,host,port,status,owner_token_hash,pinger_status,pinger_error,last_ping_at
    FROM hubs WHERE id=? AND status IN ('pending','approved') LIMIT 1");
$stmt->execute([$id]);
$hub = $stmt->fetch();
if (!$hub || $ownerCode === '' || !hash_equals((string) $hub['owner_token_hash'], hash('sha256', $ownerCode))) {
    unset($_SESSION['owner_hub_tokens'][$id]);
    page_start('Panel właściciela huba');
    ?>
    <main>
        <section class="hero home-hero">
            <span class="hero-kicker">Prywatne narzędzie dla zgłaszającego</span>
            <h1>Pinguj własny hub</h1>
            <p>Wpisz prywatny kod właściciela, który otrzymałeś po zgłoszeniu, aby wykonać pomiar.</p>
        </section>
        <section class="panel">
            <form method="post" action="hub_ping.php" class="grid">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="hub_id" value="<?= (int) $id ?>">
                <label class="field full">Prywatny kod właściciela
                    <input name="owner_code" type="password" inputmode="text" autocomplete="off" pattern="[a-fA-F0-9]{64}" minlength="64" maxlength="64" required>
                    <small>Kod nie jest wysyłany w adresie strony. Nie udostępniaj go innym osobom.</small>
                </label>
                <div class="field full"><button type="submit">Sprawdź hub teraz</button></div>
            </form>
        </section>
    </main>
    <?php
    page_end();
    exit;
}

$result = is_string($_GET['result'] ?? null) ? $_GET['result'] : '';
$message = match ($result) {
    'online' => 'Pomiar zakończony: hub odpowiedział i jest dostępny.',
    'offline', 'tls_error', 'dns_error', 'invalid_address', 'protocol_error', 'auth_required', 'rejected', 'redirected' =>
        'Pomiar zakończony. Wynik: ' . ping_status_text($result) . '.',
    default => '',
};

page_start('Panel właściciela huba');
?>
<main>
    <section class="hero home-hero">
        <span class="hero-kicker">Prywatne narzędzie dla zgłaszającego</span>
        <h1>Pinguj własny hub</h1>
        <p><?= e($hub['name']) ?> · <?= e($hub['protocol']) ?>://<?= e($hub['host']) ?>:<?= (int) $hub['port'] ?></p>
    </section>
    <section class="panel">
        <?php if ($message !== ''): ?><p class="<?= $result === 'online' ? 'success' : 'note' ?>"><?= e($message) ?></p><?php endif; ?>
        <p><strong>Ostatnie sprawdzenie pingera:</strong>
            <?= $hub['last_ping_at'] === null ? 'Jeszcze nie sprawdzono' : e(utc_datetime($hub['last_ping_at'])) . ' (' . e(date_default_timezone_get()) . ')' ?>
        </p>
        <?php if ($hub['pinger_error']): ?><p class="error">Odpowiedź: <?= e($hub['pinger_error']) ?></p><?php endif; ?>
        <?php if ($hub['status'] === 'pending'): ?><p class="note">Zgłoszenie nadal oczekuje na zatwierdzenie. Wynik pingowania nie publikuje automatycznie huba.</p><?php endif; ?>
        <form method="post" action="hub_ping.php" class="grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="hub_id" value="<?= (int) $hub['id'] ?>">
            <input type="hidden" name="owner_code" value="<?= e($ownerCode) ?>">
            <div class="field full"><button type="submit">Sprawdź hub teraz</button></div>
        </form>
        <p class="muted">Limit: jeden pomiar dla tego huba co 5 minut oraz jeden pomiar z tego adresu IP na minutę.</p>
    </section>
    <p><a href="hub.php?id=<?= (int) $hub['id'] ?>">Wróć do szczegółów huba</a></p>
</main>
<?php page_end(); ?>
