<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

if (isset($_GET['id'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit('Ta metoda żądania nie jest obsługiwana.');
    }
    $id = filter_var(is_string($_GET['id']) ? $_GET['id'] : '', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        http_response_code(400);
        exit('Nieprawidłowy identyfikator pobierania.');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT website FROM downloads WHERE id=?');
    $stmt->execute([$id]);
    $website = $stmt->fetchColumn();
    if (!is_string($website)) {
        http_response_code(404);
        exit('Nie znaleziono pozycji do pobrania.');
    }
    if (!safe_download_url($website)) {
        error_log('Rejected unsafe download URL for catalog entry ' . $id);
        http_response_code(500);
        exit('Adres pobierania tej pozycji jest nieprawidłowy.');
    }
    $pdo->prepare('UPDATE downloads SET download_count=download_count+1 WHERE id=?')->execute([$id]);
    header('Location: ' . $website, true, 302);
    exit;
}

$pdo = db();
$categoryRows = download_category_details();
$categories = [];
foreach ($categoryRows as $categoryRow) {
    $categories[$categoryRow['category_key']] = $categoryRow['name'];
}
$categoryDetails = [];
foreach ($categoryRows as $categoryRow) {
    $categoryDetails[$categoryRow['category_key']] = $categoryRow;
}
$category = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
if (!isset($categories[$category])) {
    $category = '';
}

if ($category !== '') {
    $stmt = $pdo->prepare('SELECT * FROM downloads WHERE category=? ORDER BY sort_order,name');
    $stmt->execute([$category]);
} else {
    $stmt = $pdo->query('SELECT * FROM downloads ORDER BY category,sort_order,name');
}
$items = $stmt->fetchAll();
$grouped = array_fill_keys(array_keys($categories), []);
foreach ($items as $item) {
    if (isset($grouped[$item['category']])) {
        $grouped[$item['category']][] = $item;
    }
}

page_start('Pobieralnia — programy Direct Connect');
?>
<main>
    <section class="hero">
        <h1>Pobieralnia — programy Direct Connect</h1>
        <p>Katalog zawiera odnośniki do stron projektów i wydań. Nie przechowujemy kopii zewnętrznych instalatorów ani skryptów.</p>
    </section>
    <section class="panel">
        <h2>Kategorie</h2>
        <nav class="nav" aria-label="Kategorie katalogu">
            <a href="download.php">Wszystkie</a>
            <?php foreach ($categories as $key => $label): ?>
                <a href="download.php?category=<?= e($key) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </section>
    <?php foreach ($categories as $key => $label):
        if ($category !== '' && $category !== $key) {
            continue;
        }
        ?>
        <section class="panel" id="<?= e($key) ?>">
            <h2><?= e($label) ?></h2>
            <?php if (!empty($categoryDetails[$key]['description'])): ?><p><?= e($categoryDetails[$key]['description']) ?></p><?php endif; ?>
            <?php if ($grouped[$key] === []): ?>
                <p class="empty">Brak wpisów w tej kategorii. Administrator może dodać odnośniki w panelu.</p>
            <?php else: ?>
                <div class="downloads">
                    <?php foreach ($grouped[$key] as $item): ?>
                        <article class="download">
                            <h3><?= e($item['name']) ?></h3>
                            <small><?= e($item['version'] ?: $item['platform'] ?: $label) ?></small>
                            <p><?= e($item['description'] ?: '') ?></p>
                            <p class="muted">Kliknięcia pobrania: <?= number_format((int) $item['download_count'], 0, ',', ' ') ?></p>
                            <a class="button secondary" href="download.php?id=<?= (int) $item['id'] ?>" target="_blank" rel="noopener noreferrer nofollow">Odwiedź stronę projektu / pobierania</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <section class="panel">
        <h2>Informacja o linkach</h2>
        <p>Linki prowadzą do stron projektów lub repozytoriów wybranych przez administratora. Dostępność, systemy operacyjne, licencje, wymagania oraz aktualność wydań sprawdź u autora. W przypadku archiwalnych skryptów przejrzyj kod i licencję przed użyciem.</p>
    </section>
</main>
<?php page_end(); ?>
