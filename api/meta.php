<?php
/**
 * api/meta.php — Returns JSON metadata for a CBZ or CBR file.
 *
 * GET ?file=serie/tome.cbz
 *
 * Response: { type, total, pages: [{page, zipIndex, name}], file, filename }
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/lib.php';

$file = isset($_GET['file']) ? (string)$_GET['file'] : '';
$path = validateFilePath($file);

if ($path === false) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found or invalid path'], JSON_UNESCAPED_UNICODE);
    exit;
}

$meta = getFileMeta($path);

if ($meta === false) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Cannot read archive',
        'message' => (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'cbr')
            ? 'Format CBR non supporté sur ce serveur. '
              . 'Activez l\'extension PHP "rar" dans cPanel → MultiPHP Extensions, '
              . 'ou convertissez le fichier en CBZ.'
            : 'Impossible d\'ouvrir l\'archive.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Enrich with file info for the client
$meta['file']     = getRelativePath($path);
$meta['filename'] = pathinfo($path, PATHINFO_BASENAME);

echo json_encode($meta, JSON_UNESCAPED_UNICODE);
