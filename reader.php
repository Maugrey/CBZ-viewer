<?php
/**
 * reader.php — Full-screen manga reader.
 * GET ?file=serie/tome.cbz[&page=N]
 */
declare(strict_types=1);
require_once __DIR__ . '/api/lib.php';

$file = isset($_GET['file']) ? (string)$_GET['file'] : '';
$path = validateFilePath($file);

if ($path === false) {
    http_response_code(404);
    $errorMsg = 'Fichier introuvable ou chemin invalide.';
    $meta     = null;
} else {
    $meta     = getFileMeta($path);
    $errorMsg = null;
    if ($meta === false) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $errorMsg = ($ext === 'cbr')
            ? 'Format CBR non supporté sur ce serveur. Activez l\'extension PHP "rar" dans cPanel → MultiPHP Extensions, ou convertissez le fichier en CBZ.'
            : 'Impossible d\'ouvrir l\'archive.';
    }
}

// Determine series slug and sibling volumes for Prev/Next tome navigation
$seriesSlug  = '';
$siblingFiles = [];
$thisIndex    = -1;
if ($path !== false) {
    $seriesDir  = dirname($path);
    $seriesSlug = basename($seriesDir);
    $siblingFiles = getSeriesFiles($seriesDir);
    foreach ($siblingFiles as $i => $f) {
        if ($f === $path) { $thisIndex = $i; break; }
    }
}

$prevFile = ($thisIndex > 0)
    ? getRelativePath($siblingFiles[$thisIndex - 1]) : null;
$nextFile = ($thisIndex >= 0 && $thisIndex < count($siblingFiles) - 1)
    ? getRelativePath($siblingFiles[$thisIndex + 1]) : null;

// Config to pass to JS
$jsConfig = [
    'file'          => $file,
    'total'         => $meta ? $meta['total'] : 0,
    'type'          => $meta ? $meta['type']  : 'unknown',
    'seriesSlug'    => $seriesSlug,
    'seriesUrl'     => 'series.php?s=' . urlencode($seriesSlug),
    'prevFile'      => $prevFile,
    'nextFile'      => $nextFile,
    'prevFileUrl'   => $prevFile ? 'reader.php?file=' . urlencode($prevFile) : null,
    'nextFileUrl'   => $nextFile ? 'reader.php?file=' . urlencode($nextFile) : null,
    'pageApiUrl'    => 'api/page.php',
    'thumbApiUrl'   => 'api/thumb.php',
    'startPage'     => isset($_GET['page']) ? (int)$_GET['page'] : null,
    'error'         => $errorMsg,
];

$volumeName = $path ? formatVolumeName($path) : 'Lecteur';
$pageTitle  = $path ? (formatSeriesName($seriesSlug) . ' — ' . $volumeName) : 'Lecteur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📖</text></svg>">
<meta name="theme-color" content="#111118">
</head>
<body class="reader-page" id="readerBody">

<!-- ==================== TOOLBAR ==================== -->
<div class="reader-toolbar" id="readerToolbar">
    <div class="toolbar-left">
        <a href="<?= htmlspecialchars('series.php?s=' . urlencode($seriesSlug)) ?>"
           class="toolbar-btn" title="Retour à la série (S)" id="btnSeries">
            ‹ <span class="toolbar-label"><?= htmlspecialchars(formatSeriesName($seriesSlug)) ?></span>
        </a>
    </div>
    <div class="toolbar-center" id="toolbarCenter">
        <button class="toolbar-btn icon-btn" id="btnPrevVol"
            title="Tome précédent"
            <?= $prevFile ? '' : 'disabled' ?>>«</button>
        <span class="volume-title" id="volumeTitle">
            <?= htmlspecialchars($volumeName) ?>
        </span>
        <button class="toolbar-btn icon-btn" id="btnNextVol"
            title="Tome suivant"
            <?= $nextFile ? '' : 'disabled' ?>>»</button>
    </div>
    <div class="toolbar-right">
        <button class="toolbar-btn icon-btn" id="btnFullscreen" title="Plein écran (F)">⛶</button>
        <button class="toolbar-btn icon-btn" id="btnSettings"  title="Paramètres (S)">⚙</button>
    </div>
</div>

<!-- ==================== READER AREA ==================== -->
<div class="reader-viewport" id="readerViewport">

    <?php if ($errorMsg): ?>
    <div class="reader-error">
        <div class="error-icon">⚠️</div>
        <h2>Erreur</h2>
        <p><?= htmlspecialchars($errorMsg) ?></p>
        <a href="<?= htmlspecialchars('series.php?s=' . urlencode($seriesSlug)) ?>">← Retour à la série</a>
    </div>
    <?php else: ?>

    <!-- Single page view -->
    <div class="page-stage" id="pageStage">
        <div class="page-container" id="pageContainer">
            <img class="page-img" id="pageImgA" src="" alt="Page" draggable="false">
            <img class="page-img page-img-right hidden" id="pageImgB" src="" alt="Page droite" draggable="false">
        </div>
        <!-- Tap zones for LTR/RTL navigation -->
        <div class="tap-zone tap-zone-left"  id="tapLeft"  title="Page précédente"></div>
        <div class="tap-zone tap-zone-right" id="tapRight" title="Page suivante"></div>
    </div>

    <!-- Webtoon mode: vertical continuous scroll -->
    <div class="webtoon-stage hidden" id="webtoonStage"></div>

    <!-- Loading spinner -->
    <div class="page-loader" id="pageLoader">
        <div class="spinner"></div>
    </div>

    <?php endif; ?>
</div>

<!-- ==================== BOTTOM BAR ==================== -->
<div class="reader-bottombar" id="readerBottombar">
    <button class="toolbar-btn icon-btn nav-btn" id="btnPrevPage" title="Page précédente (←)">‹</button>

    <div class="progress-wrap">
        <input type="range" id="pageSlider" class="page-slider"
               min="0" max="<?= max(0, ($meta ? $meta['total'] - 1 : 0)) ?>"
               value="0" step="1">
        <div class="progress-bar-track">
            <div class="progress-bar-fill" id="progressFill"></div>
        </div>
        <div class="page-indicator">
            <input type="number" id="pageInput" class="page-input"
                   min="1" max="<?= $meta ? $meta['total'] : 1 ?>" value="1">
            <span class="page-sep">/</span>
            <span class="page-total" id="pageTotal"><?= $meta ? $meta['total'] : '?' ?></span>
        </div>
    </div>

    <button class="toolbar-btn icon-btn nav-btn" id="btnNextPage" title="Page suivante (→)">›</button>
</div>

<!-- ==================== SETTINGS PANEL ==================== -->
<div class="settings-overlay hidden" id="settingsOverlay" role="dialog" aria-modal="true" aria-label="Paramètres">
    <div class="settings-panel" id="settingsPanel">
        <div class="settings-header">
            <h2>Paramètres</h2>
            <button class="settings-close" id="settingsClose" aria-label="Fermer">✕</button>
        </div>

        <div class="settings-section">
            <label class="settings-label">Mode de lecture</label>
            <div class="radio-group" id="readingMode">
                <label class="radio-item">
                    <input type="radio" name="readingMode" value="rtl" checked>
                    <span>← Droite → Gauche <small>(Manga JP)</small></span>
                </label>
                <label class="radio-item">
                    <input type="radio" name="readingMode" value="ltr">
                    <span>→ Gauche → Droite <small>(BD occidentale)</small></span>
                </label>
                <label class="radio-item">
                    <input type="radio" name="readingMode" value="webtoon">
                    <span>↓ Scroll vertical <small>(Webtoon)</small></span>
                </label>
            </div>
        </div>

        <div class="settings-section" id="doublePagSection">
            <label class="settings-label">Double page</label>
            <div class="toggle-row">
                <label class="toggle">
                    <input type="checkbox" id="doublePage">
                    <span class="toggle-slider"></span>
                </label>
                <span>Afficher 2 pages côte à côte</span>
            </div>
            <div class="toggle-row" id="coverOffsetRow">
                <label class="toggle">
                    <input type="checkbox" id="coverOffset">
                    <span class="toggle-slider"></span>
                </label>
                <span>Décaler d'une page (couverture seule)</span>
            </div>
        </div>

        <div class="settings-section">
            <label class="settings-label">Zoom par défaut</label>
            <div class="zoom-controls">
                <button class="zoom-preset" data-zoom="fit-width">Largeur</button>
                <button class="zoom-preset" data-zoom="fit-height">Hauteur</button>
                <button class="zoom-preset" data-zoom="fit-page">Page</button>
                <button class="zoom-preset active" data-zoom="1">100%</button>
                <button class="zoom-preset" data-zoom="1.5">150%</button>
                <button class="zoom-preset" data-zoom="2">200%</button>
            </div>
            <div class="zoom-custom-row">
                <button class="toolbar-btn icon-btn" id="zoomOut">−</button>
                <span id="zoomLabel">100%</span>
                <button class="toolbar-btn icon-btn" id="zoomIn">+</button>
            </div>
        </div>

        <div class="settings-section">
            <label class="settings-label">Thème</label>
            <div class="toggle-row">
                <label class="toggle">
                    <input type="checkbox" id="lightTheme">
                    <span class="toggle-slider"></span>
                </label>
                <span>Mode clair</span>
            </div>
        </div>

        <div class="settings-section">
            <label class="settings-label">Raccourcis clavier</label>
            <table class="shortcuts-table">
                <tr><td>←  /  →</td><td>Page précédente / suivante</td></tr>
                <tr><td>↑  /  ↓</td><td>Page précédente / suivante</td></tr>
                <tr><td>F</td><td>Plein écran</td></tr>
                <tr><td>S</td><td>Paramètres</td></tr>
                <tr><td>+  /  −</td><td>Zoom avant / arrière</td></tr>
                <tr><td>0</td><td>Réinitialiser le zoom</td></tr>
                <tr><td>Échap</td><td>Fermer / quitter le plein écran</td></tr>
            </table>
        </div>

        <div class="settings-section settings-footer">
            <button class="btn-danger" id="btnClearProgress">
                🗑 Effacer la progression de ce tome
            </button>
        </div>
    </div>
</div>

<!-- ==================== BOOTSTRAP JS ==================== -->
<script>
window.READER_CONFIG = <?= json_encode($jsConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="assets/js/reader.js" defer></script>

</body>
</html>
