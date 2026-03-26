<?php
/**
 * api/thumb.php — Serves (or generates and caches) a JPEG cover thumbnail.
 *
 * GET ?file=serie/tome.cbz
 *
 * Returns a 280px-wide JPEG (first page, resized).
 * Cached on disk; auto-invalidated if source file is modified.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/lib.php';

$file = isset($_GET['file']) ? (string)$_GET['file'] : '';
$path = validateFilePath($file);

if ($path === false) {
    http_response_code(404);
    servePlaceholder();
    exit;
}

serveThumbnail($path);
