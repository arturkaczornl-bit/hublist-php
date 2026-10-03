<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
start_app_session();
$pdo = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_string($_POST, 'action') === 'submit_hub') {
    require_csrf();
    try {
        if (trim(post_string($_POST, 'website_check')) !== '') {
            throw new InvalidArgumentException('Nie udało się przyjąć zgłoszenia.');
        }
        $hub = normalize_hub_input($_POST);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $config = app_config();
        $ipHash = hash_hmac('sha256', $ip, $config['db_password'] . $config['db_name']);
        $limit = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE ip_hash = ? AND submitted_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $limit->execute([$ipHash]);
        if ((int) $limit->fetchColumn() >= 3) {
            throw new InvalidArgumentException('Z tego adresu można wysłać maksymalnie 3 zgłoszenia na godzinę.');
        }

        $duplicate = $pdo->prepare("SELECT id FROM hubs WHERE host = ? AND port = ? AND protocol = ? AND status <> 'rejected' LIMIT 1");
        $duplicate->execute([$hub['host'], $hub['port'], $hub['protocol']]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('Ten adres został już zgłoszony lub znajduje się na liście.');
        }

        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO hubs (name, protocol, host, port, country, country_source, description, software, website, status) VALUES (?, ?, ?, ?, ?, IF(? IS NULL,NULL,"manual"), ?, ?, ?, "pending")');
        $insert->execute([
            $hub['name'], $hub['protocol'], $hub['host'], $hub['port'],
            $hub['country'], $hub['country'], $hub['description'], $hub['software'], $hub['website'],
        ]);
        $saveSubmission = $pdo->prepare('INSERT INTO submissions (ip_hash) VALUES (?)');
        $saveSubmission->execute([$ipHash]);
        $pdo->commit();
        $message = 'Dziękujemy! Zgłoszenie trafiło do kolejki weryfikacji administratora.';
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Hublist submission failed: ' . $exception->getMessage());
        $error = 'Nie udało się zapisać zgłoszenia. Spróbuj ponownie później.';
    }
}

page_start('Dodaj hub — zgłoszenie do Hublist.pl');
?>
<main>
    <section class="hero home-hero">
        <span class="hero-kicker">Dołącz do polskiej hublisty</span>
        <h1>Zgłoś własny hub</h1>
        <p>Dodaj publiczny hub Direct Connect do katalogu Hublist.pl. Zgłoszenie trafi do administratora i będzie widoczne na liście dopiero po weryfikacji.</p>
    </section>
    <section class="panel">
        <h2>Dane huba</h2>
        <p class="muted">Podaj publiczny adres huba i informacje przeznaczone do publikacji. Nie zgłaszaj prywatnych adresów ani danych osobowych.</p>
        <?php if ($message !== ''): ?><p class="success"><?= e($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
        <form method="post" class="grid">
            <input type="hidden" name="action" value="submit_hub">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label class="field">Nazwa huba<input name="name" maxlength="150" required></label>
            <label class="field">Protokół<select name="protocol" required>
                <?php foreach (HUB_PROTOCOLS as $protocol): ?><option value="<?= e($protocol) ?>"><?= e($protocol) ?></option><?php endforeach; ?>
            </select></label>
            <label class="field">Adres IP lub nazwa hosta<input name="host" maxlength="253" required placeholder="hub.example.org"></label>
            <label class="field">Port<input name="port" type="number" min="1" max="65535" placeholder="domyślny dla protokołu"></label>
            <label class="field">Kraj (kod ISO, np. PL)<input name="country" maxlength="2" pattern="[A-Za-z]{2}"></label>
            <label class="field">Oprogramowanie serwera<input name="software" maxlength="120"></label>
            <label class="field full">Opis<textarea name="description" maxlength="5000"></textarea></label>
            <label class="field full">Strona huba (HTTPS)<input name="website" type="url" maxlength="500" placeholder="https://example.org"></label>
            <label class="field" style="position:absolute;left:-10000px" aria-hidden="true">Pozostaw puste<input name="website_check" tabindex="-1" autocomplete="off"></label>
            <p class="note field full">Zgłaszając hub potwierdzasz, że możesz publikować podane informacje. Administrator sprawdzi zgłoszenie przed publikacją.</p>
            <div class="field full"><button type="submit">Wyślij do weryfikacji</button></div>
        </form>
    </section>
    <p><a href="index.php">← Wróć do hublisty</a></p>
</main>
<?php page_end(); ?>
