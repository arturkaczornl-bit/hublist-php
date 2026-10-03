<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

$categories = download_categories();
$category = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
if (!isset($categories[$category])) {
    $category = '';
}

$pdo = db();
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

page_start('Download — programy Direct Connect');
?>
<main>
    <section class="hero">
        <h1>Download — oprogramowanie Direct Connect</h1>
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
            <?php if ($grouped[$key] === []): ?>
                <p class="empty">Brak wpisów w tej kategorii. Administrator może dodać odnośniki w panelu.</p>
            <?php else: ?>
                <div class="downloads">
                    <?php foreach ($grouped[$key] as $item): ?>
                        <article class="download">
                            <h3><?= e($item['name']) ?></h3>
                            <small><?= e($item['version'] ?: $item['platform'] ?: $label) ?></small>
                            <p><?= e($item['description'] ?: '') ?></p>
                            <a class="button secondary" href="<?= e($item['website']) ?>" target="_blank" rel="noopener noreferrer nofollow">Otwórz stronę projektu / pobierania</a>
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
