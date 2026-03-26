<?php
/**
 * api/page.php — Streams a single page image from a CBZ or CBR archive.
 *
 * GET ?file=serie/tome.cbz&page=N   (page is 0-indexed)
 */
declare(strict_types=1);

// Suppress PHP errors — they would corrupt the binary image output
ini_set('display_errors', '0');
error_reporting(0);

// Disable output buffering and compression BEFORE any output.
// On cPanel, zlib.output_compression may be On, which would corrupt binary
// image data and invalidate Content-Length headers.
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_clean();
}

require_once __DIR__ . '/lib.php';

$file    = isset($_GET['file']) ? (string)$_GET['file'] : '';
$pageNum = isset($_GET['page']) ? (int)$_GET['page']   : 0;

$path = validateFilePath($file);

if ($path === false) {
    http_response_code(404);
    exit('File not found');
}

streamPage($path, $pageNum);
