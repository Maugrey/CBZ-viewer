<?php
/**
 * debug.php — Diagnostic page for CBZ-Viewer
 * DELETE or password-protect this file on a production server.
 *
 * Access: http://cbz-viewer.test/debug.php
 */

// Force full error display
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// --- Helpers ---
function row(string $label, string $status, string $value = '', string $hint = ''): void {
    $color = match($status) {
        'ok'   => '#4caf7d',
        'warn' => '#f0a840',
        'fail' => '#e05c5c',
        default => '#8888aa',
    };
    $icon = match($status) { 'ok' => '✔', 'warn' => '⚠', 'fail' => '✘', default => '•' };
    echo "<tr>";
    echo "<td style='padding:.4rem .75rem;border-bottom:1px solid #2d2d42;font-weight:600;color:#e8e8f0'>".htmlspecialchars($label)."</td>";
    echo "<td style='padding:.4rem .75rem;border-bottom:1px solid #2d2d42;color:$color;font-weight:700'>$icon ".ucfirst($status)."</td>";
    echo "<td style='padding:.4rem .75rem;border-bottom:1px solid #2d2d42;color:#aaa;font-family:monospace;font-size:.85em'>".htmlspecialchars($value)."</td>";
    if ($hint) echo "<td style='padding:.4rem .75rem;border-bottom:1px solid #2d2d42;color:#f0a840;font-size:.82em'>".htmlspecialchars($hint)."</td>";
    else       echo "<td style='padding:.4rem .75rem;border-bottom:1px solid #2d2d42'></td>";
    echo "</tr>";
}
function section(string $title): void {
    echo "<tr><th colspan='4' style='padding:.75rem;background:#22222f;color:#7c6ff7;font-size:.9rem;text-transform:uppercase;letter-spacing:.06em'>$title</th></tr>";
}

$ROOT = dirname(__FILE__);

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CBZ-Viewer — Debug</title>
<style>
  body { font-family: 'Segoe UI', system-ui, sans-serif; background:#111118; color:#e8e8f0; margin:0; padding:1.5rem; }
  h1   { color:#7c6ff7; margin-bottom:1rem; }
  table{ width:100%; border-collapse:collapse; background:#1a1a26; border-radius:8px; overflow:hidden; margin-bottom:2rem; }
  .warn-box { background:#2a1f0a; border:1px solid #f0a840; border-radius:6px; padding:.75rem 1rem; margin-bottom:1.5rem; color:#f0a840; }
  pre  { background:#0a0a0f; border:1px solid #2d2d42; border-radius:6px; padding:1rem; overflow-x:auto; font-size:.82rem; color:#aaa; }
  .badge { display:inline-block; background:#7c6ff7; color:#fff; border-radius:4px; padding:.1em .5em; font-size:.8em; font-weight:700; }
</style>
</head>
<body>
<h1>🔧 CBZ-Viewer — Page de diagnostic</h1>
<div class="warn-box">⚠️ Supprimez ou protégez ce fichier sur un serveur de production !</div>

<table>
<?php

// ----------------------------------------------------------------
section('PHP');
// PHP version
$phpVer = PHP_VERSION;
$phpOk  = version_compare($phpVer, '8.0.0', '>=');
row('Version PHP', $phpOk ? 'ok' : 'fail', $phpVer,
    $phpOk ? '' : 'PHP 8.0+ requis (union types, match, str_contains)');

// Syntax check — include lib.php inside a try and catch any fatal via output buffering
$libPath = $ROOT . '/api/lib.php';
row('Fichier api/lib.php', file_exists($libPath) ? 'ok' : 'fail',
    $libPath, file_exists($libPath) ? '' : 'Fichier manquant');

// ----------------------------------------------------------------
section('Extensions PHP requises');

$extZip = extension_loaded('zip');
row('ext-zip (CBZ)', $extZip ? 'ok' : 'fail', $extZip ? 'Chargée' : 'NON chargée',
    $extZip ? '' : 'Activer dans php.ini : décommenter extension=zip → redémarrer Apache');

$extGd  = extension_loaded('gd');
row('ext-gd (miniatures fallback)', $extGd ? 'ok' : 'warn',
    $extGd ? 'Chargée' : 'NON chargée',
    $extGd ? '' : 'Activer gd dans cPanel → MultiPHP Extensions');

$extIm  = extension_loaded('imagick') || class_exists('Imagick');
row('ext-imagick (miniatures haute qualité)', $extIm ? 'ok' : 'warn',
    $extIm ? 'Chargée' : 'NON chargée',
    !$extIm ? 'Optionnel si gd disponible' : '');

$extRar = extension_loaded('rar') || class_exists('RarArchive');
row('ext-rar (PECL, CBR natif)', $extRar ? 'ok' : 'warn',
    $extRar ? 'Chargée' : 'NON chargée',
    $extRar ? '' : 'Optionnel — CBR peut utiliser unrar/7z via shell');

// ----------------------------------------------------------------
section('Chemins');

// DATA_DIR resolution
$dataRaw    = realpath($ROOT . '/data');
$dataDirOk  = $dataRaw !== false && is_dir($dataRaw);
row('Dossier data/', $dataDirOk ? 'ok' : 'fail',
    $dataRaw ?: ($ROOT . '/data → realpath() a retourné false'),
    $dataDirOk ? '' : 'Le dossier data/ doit exister');

// CACHE_DIR
$cacheRaw   = $ROOT . '/cache';
$cacheDirOk = is_dir($cacheRaw);
row('Dossier cache/', $cacheDirOk ? 'ok' : 'warn',
    $cacheRaw,
    $cacheDirOk ? '' : 'Sera créé automatiquement à la première visite');

// Cache writable
if ($cacheDirOk) {
    $cacheWrite = is_writable($cacheRaw);
    row('cache/ accessible en écriture', $cacheWrite ? 'ok' : 'fail',
        $cacheWrite ? 'Oui' : 'NON — PHP ne peut pas écrire dans cache/',
        $cacheWrite ? '' : 'chmod 755 cache/ — ou vérifier les droits utilisateur PHP');
}

// Cache sub-dirs
$thumbDir = $cacheRaw . '/thumbnails';
$metaDir  = $cacheRaw . '/metadata';
foreach ([['thumbnails', $thumbDir], ['metadata', $metaDir]] as [$name, $path]) {
    if (!is_dir($path)) {
        $ok = @mkdir($path, 0755, true);
        row("cache/$name/", $ok ? 'ok' : 'warn',
            $ok ? 'Créé à l\'instant' : 'Création impossible',
            $ok ? '' : 'Droits insuffisants');
    } else {
        row("cache/$name/", 'ok', 'Existe');
    }
}

// __DIR__ vs realpath (symlink check)
$dirFile  = dirname($libPath);
$dirReal  = realpath($dirFile);
row('__DIR__ (api/)', 'info', $dirFile);
if ($dirReal && $dirReal !== $dirFile) {
    row('realpath(__DIR__) → lien symbolique résolu', 'warn', $dirReal,
        'Normal avec un lien symbolique Laragon — peut affecter DATA_DIR si incohérent');
} elseif ($dirReal) {
    row('realpath(__DIR__)', 'ok', $dirReal, 'Pas de redirection symlink détectée');
}

// ----------------------------------------------------------------
section('PHP ini — valeurs importantes');

row('display_errors',         'info', ini_get('display_errors') ?: '(vide)');
row('error_reporting',        'info', (string)ini_get('error_reporting'));
row('error_log',              'info', ini_get('error_log') ?: '(non défini — stderr Apache)');
row('memory_limit',           'info', ini_get('memory_limit'));
row('max_execution_time',     'info', ini_get('max_execution_time') . 's');
row('upload_max_filesize',    'info', ini_get('upload_max_filesize'));

$zlibOn = (bool)(int)ini_get('zlib.output_compression');
row('zlib.output_compression',
    $zlibOn ? 'warn' : 'ok',
    $zlibOn ? 'On — PROBLÈME : compresse les images binaires et invalide Content-Length' : 'Off',
    $zlibOn ? 'Ajouter "php_flag zlib.output_compression Off" dans .htaccess ou corriger php.ini' : '');

$obLevel = ob_get_level();
row('Output buffering (ob_get_level)',
    $obLevel > 0 ? 'warn' : 'ok',
    "Niveau $obLevel",
    $obLevel > 0 ? 'Des buffers actifs peuvent bloquer le streaming — corrigé dans page.php' : '');

// ----------------------------------------------------------------
section('Contenu de data/');

if ($dataDirOk) {
    $series = array_filter(scandir($dataRaw), fn($d) => $d[0] !== '.' && is_dir($dataRaw.'/'.$d));
    if (empty($series)) {
        row('Séries détectées', 'warn', '(aucun sous-dossier dans data/)', 'Copiez vos CBZ/CBR dans data/{serie}/');
    } else {
        foreach ($series as $s) {
            $sPath = $dataRaw . '/' . $s;
            $files = array_filter(scandir($sPath), fn($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['cbz', 'cbr']));
            row($s, 'ok', count($files) . ' fichier(s) CBZ/CBR trouvé(s)');
            foreach ($files as $f) {
                $fp = $sPath . '/' . $f;
                row('  └─ ' . $f, 'info',
                    number_format(filesize($fp) / 1024 / 1024, 1) . ' Mo'
                    . ' · ext=' . strtoupper(pathinfo($f, PATHINFO_EXTENSION)));
            }
        }
    }
} else {
    row('data/', 'fail', 'Dossier inaccessible');
}

// ----------------------------------------------------------------
section('Test chargement api/lib.php');

if (file_exists($libPath)) {
    ob_start();
    try {
        require_once $libPath;
        ob_end_clean();
        row('require_once api/lib.php', 'ok', 'Aucune erreur fatale');

        // Test DATA_DIR constant
        row('Constante DATA_DIR', defined('DATA_DIR') ? 'ok' : 'fail', defined('DATA_DIR') ? DATA_DIR : 'NON définie');
        row('Constante CACHE_DIR', defined('CACHE_DIR') ? 'ok' : 'fail', defined('CACHE_DIR') ? CACHE_DIR : 'NON définie');

        // Quick test getAllSeries()
        if (function_exists('getAllSeries')) {
            $s = getAllSeries();
            row('getAllSeries()', 'ok', count($s) . ' série(s) trouvée(s)');
        }
    } catch (ParseError $e) {
        ob_end_clean();
        row('require_once api/lib.php', 'fail',
            'ParseError : ' . $e->getMessage() . ' (ligne ' . $e->getLine() . ')',
            'Probablement une syntaxe PHP 8+ sur une version antérieure');
    } catch (Throwable $e) {
        ob_end_clean();
        row('require_once api/lib.php', 'fail',
            get_class($e) . ': ' . $e->getMessage() . ' (ligne ' . $e->getLine() . ')');
    }
} else {
    row('require_once api/lib.php', 'fail', 'Fichier introuvable');
}

// ----------------------------------------------------------------
section('Shell — disponibilité des binaires CBR');

$execOk = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
row('exec() disponible', $execOk ? 'ok' : 'warn', $execOk ? 'Oui' : 'Désactivé via disable_functions',
    $execOk ? '' : 'CBR via shell impossible — activer ext-rar pour le support CBR');

if ($execOk) {
    $out = []; $code = -1;
    @exec('unrar --version 2>&1', $out, $code);
    $unrarOutput = strtolower(implode(' ', $out));
    $hasUnrar = $code === 0
        && !str_contains($unrarOutput, 'not recognized')
        && !str_contains($unrarOutput, 'not found')
        && !str_contains($unrarOutput, 'introuvable');
    row('Binaire unrar', $hasUnrar ? 'ok' : 'warn',
        empty($out) ? 'Non trouvé dans $PATH' : implode(' ', array_slice($out, 0, 1)),
        $hasUnrar ? '' : 'Non disponible — 7z sera utilisé en fallback CBR');

    $out = []; $code = -1;
    @exec('7z i 2>&1', $out, $code);
    $has7z = ($code === 0);
    row('Binaire 7z', $has7z ? 'ok' : 'warn', $has7z ? '7-Zip disponible' : 'Non trouvé dans $PATH');
}

?>
</table>

<h2 style="color:#7c6ff7;margin-bottom:.5rem">📋 Emplacement des logs</h2>
<pre>Chemins possibles du error_log PHP sur ce serveur :
  <?= htmlspecialchars(ini_get('error_log') ?: '(non défini)') ?>

  Relatif au répertoire courant (<?= htmlspecialchars(__DIR__) ?>) :
  <?= htmlspecialchars(__DIR__ . '/error_log') ?>

  <?= htmlspecialchars(dirname(__DIR__) . '/error_log') ?>

  <?= htmlspecialchars(dirname(__DIR__) . '/logs/error_log') ?>

Laragon uniquement :
  C:\laragon\tmp\php_errors.log
  C:\laragon\bin\apache\httpd-X.X.X\logs\error.log</pre>

<?php
// Check if any error_log files exist and show last lines
$logCandidates = [
    ini_get('error_log'),
    __DIR__ . '/error_log',
    dirname(__DIR__) . '/error_log',
    dirname(__DIR__) . '/logs/php_error.log',
    'C:/laragon/tmp/php_errors.log',
];
foreach ($logCandidates as $logFile) {
    if ($logFile && is_readable($logFile) && filesize($logFile) > 0) {
        $lines = array_slice(file($logFile), -30);
        echo "<h3 style='color:#4caf7d'>📄 Dernières lignes de : " . htmlspecialchars($logFile) . "</h3>";
        echo "<pre>" . htmlspecialchars(implode('', $lines)) . "</pre>";
    }
}
?>

<h2 style="color:#7c6ff7;margin-bottom:.5rem">🧪 Test d'une URL de page</h2>
<?php
if ($dataDirOk) {
    $series = array_filter(scandir($dataRaw), fn($d) => $d[0] !== '.' && is_dir($dataRaw.'/'.$d));
    foreach ($series as $s) {
        $files = array_filter(scandir($dataRaw.'/'.$s), fn($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['cbz', 'cbr']));
        foreach ($files as $f) {
            $rel = $s . '/' . $f;
            echo "<p>→ <a style='color:#7c6ff7' href='api/page.php?file=".urlencode($rel)."&amp;page=0' target='_blank'>api/page.php?file=".htmlspecialchars($rel)."&amp;page=0</a> (page 1)</p>";
            echo "<p>→ <a style='color:#7c6ff7' href='api/meta.php?file=".urlencode($rel)."' target='_blank'>api/meta.php?file=".htmlspecialchars($rel)."</a> (métadonnées JSON)</p>";
            echo "<p>→ <a style='color:#7c6ff7' href='api/thumb.php?file=".urlencode($rel)."' target='_blank'>api/thumb.php?file=".htmlspecialchars($rel)."</a> (miniature)</p>";
            break 2;
        }
    }
}
?>

</body>
</html>
