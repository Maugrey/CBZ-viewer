<?php
/**
 * series.php — Volume grid for one series.
 * GET ?s={series-slug}
 */
declare(strict_types=1);
require_once __DIR__ . '/api/lib.php';

$slug       = isset($_GET['s']) ? (string)$_GET['s'] : '';
$seriesPath = validateSeriesPath($slug);

if ($seriesPath === false) {
    http_response_code(404);
    $pageTitle = 'Série introuvable';
    $files     = [];
    $seriesName = 'Série introuvable';
} else {
    $files      = getSeriesFiles($seriesPath);
    $seriesName = formatSeriesName($slug);
    $pageTitle  = $seriesName;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — Manga Library</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📖</text></svg>">
</head>
<body class="library-page">

<header class="site-header">
    <div class="header-inner">
        <nav class="breadcrumb" aria-label="Fil d'Ariane">
            <a href="index.php" class="breadcrumb-link">
                <span>📚</span> Bibliothèque
            </a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current"><?= htmlspecialchars($seriesName) ?></span>
        </nav>
    </div>
</header>

<main class="container">
<?php if ($seriesPath === false): ?>
    <div class="empty-state">
        <div class="empty-icon">❓</div>
        <h2>Série introuvable</h2>
        <p><a href="index.php">← Retour à la bibliothèque</a></p>
    </div>
<?php elseif (empty($files)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <h2>Aucun tome trouvé</h2>
        <p>Copiez des fichiers CBZ/CBR dans <code>data/<?= htmlspecialchars($slug) ?>/</code></p>
    </div>
<?php else: ?>
    <div class="grid-header">
        <h2 class="grid-title">
            <?= htmlspecialchars($seriesName) ?>
            <span class="badge"><?= count($files) ?> tome<?= count($files) > 1 ? 's' : '' ?></span>
        </h2>
    </div>
    <div class="card-grid" id="volumeGrid">
        <?php foreach ($files as $i => $file): ?>
        <?php
            $rel   = getRelativePath($file);
            $thumb = 'api/thumb.php?file=' . urlencode($rel);
            $href  = 'reader.php?file=' . urlencode($rel);
            $name  = formatVolumeName($file);
            $ext   = strtoupper(pathinfo($file, PATHINFO_EXTENSION));
        ?>
        <a class="card" href="<?= htmlspecialchars($href) ?>">
            <div class="card-cover">
                <img
                    src="<?= htmlspecialchars($thumb) ?>"
                    alt="<?= htmlspecialchars($name) ?>"
                    loading="lazy"
                    decoding="async"
                >
                <span class="card-badge"><?= htmlspecialchars($ext) ?></span>
            </div>
            <div class="card-body">
                <p class="card-title"><?= htmlspecialchars($name) ?></p>
                <p class="card-meta" data-file="<?= htmlspecialchars($rel) ?>">
                    <span class="page-progress"></span>
                </p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</main>

<footer class="site-footer">
    <p>CBZ-Viewer — <a href="index.php">📚 Bibliothèque</a></p>
</footer>

<script>
// Show reading progress from localStorage
document.querySelectorAll('[data-file]').forEach(el => {
    const key  = 'cbzv_progress_' + el.dataset.file;
    const data = localStorage.getItem(key);
    if (!data) return;
    try {
        const p    = JSON.parse(data);
        const span = el.querySelector('.page-progress');
        if (span && p.page !== undefined && p.total !== undefined) {
            const pct = Math.round((p.page + 1) / p.total * 100);
            span.textContent = `Page ${p.page + 1} / ${p.total} (${pct}%)`;
            span.style.color = pct >= 100 ? 'var(--accent-green)' : 'var(--accent)';
        }
    } catch {}
});
</script>
</body>
</html>
