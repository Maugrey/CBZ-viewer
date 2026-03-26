<?php
/**
 * index.php — Library: grid of series with cover thumbnails.
 */
declare(strict_types=1);
require_once __DIR__ . '/api/lib.php';

$series = getAllSeries();
$pageTitle = 'Bibliothèque Manga';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
</head>
<body class="library-page">

<header class="site-header">
    <div class="header-inner">
        <h1 class="site-title">
            <span class="site-icon">📚</span>
            Manga Library
        </h1>
    </div>
</header>

<main class="container">
    <?php if (empty($series)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <h2>Aucune série trouvée</h2>
        <p>Copiez vos fichiers CBZ/CBR dans le dossier <code>data/{nom-de-série}/</code></p>
    </div>
    <?php else: ?>
    <div class="grid-header">
        <h2 class="grid-title">Séries <span class="badge"><?= count($series) ?></span></h2>
    </div>
    <div class="card-grid" id="seriesGrid">
        <?php foreach ($series as $s): ?>
        <?php
            $rel   = getRelativePath($s['firstFile']);
            $thumb = 'api/thumb.php?file=' . urlencode($rel);
            $href  = 'series.php?s=' . urlencode($s['slug']);
        ?>
        <a class="card" href="<?= htmlspecialchars($href) ?>">
            <div class="card-cover">
                <img
                    src="<?= htmlspecialchars($thumb) ?>"
                    alt="<?= htmlspecialchars($s['name']) ?>"
                    loading="lazy"
                    decoding="async"
                >
            </div>
            <div class="card-body">
                <p class="card-title"><?= htmlspecialchars($s['name']) ?></p>
                <p class="card-meta"><?= $s['count'] ?> tome<?= $s['count'] > 1 ? 's' : '' ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<footer class="site-footer">
    <p>CBZ-Viewer — Lecture locale · <a href="https://github.com/Maugrey/CBZ-viewer" target="_blank" rel="noopener">GitHub</a></p>
</footer>

</body>
</html>
