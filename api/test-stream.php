<?php
/**
 * api/test-stream.php — Diagnostic: test exact page.php code path, return JSON.
 * DELETE this file after diagnosis.
 *
 * Usage: https://mangas.maugrey.net/api/test-stream.php
 *   or with a specific file: ?file=one-piece/Compressed...cbz&page=0
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Capture any PHP errors/warnings
$errors = [];
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$errors) {
    $errors[] = "[$errno] $errstr in $errfile:$errline";
    return true;
});

// Capture fatal errors via shutdown function
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Headers may already be sent — try anyway
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'fatal'   => true,
            'type'    => $e['type'],
            'message' => $e['message'],
            'file'    => $e['file'],
            'line'    => $e['line'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
});

ini_set('display_errors', '0');
error_reporting(E_ALL);

$result = [];

// ---- 1. Environment ----
$result['php_version']            = PHP_VERSION;
$result['zlib_output_compression']= ini_get('zlib.output_compression');
$result['open_basedir']           = ini_get('open_basedir') ?: '(not set)';
$result['memory_limit']           = ini_get('memory_limit');
$result['ob_level_before']        = ob_get_level();

// ---- 2. Load lib.php ----
try {
    require_once __DIR__ . '/lib.php';
    $result['lib_loaded'] = true;
    $result['DATA_DIR']   = DATA_DIR;
    $result['CACHE_DIR']  = CACHE_DIR;
} catch (Throwable $e) {
    $result['lib_loaded'] = false;
    $result['lib_error']  = $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
    $result['php_errors'] = $errors;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- 3. Resolve test file ----
$fileParam = isset($_GET['file']) ? (string)$_GET['file'] : null;
$pageParam  = isset($_GET['page']) ? (int)$_GET['page']  : 0;

if (!$fileParam) {
    // Auto-pick first CBZ found
    foreach (getAllSeries() as $s) {
        foreach (getSeriesFiles($s['path']) as $f) {
            if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'cbz') {
                $fileParam = getRelativePath($f);
                break 2;
            }
        }
    }
}

$result['test_file_param'] = $fileParam;

if (!$fileParam) {
    $result['error'] = 'No CBZ file found in data/';
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- 4. Validate path ----
$path = validateFilePath($fileParam);
$result['validate_path']   = $path;
$result['validate_ok']     = ($path !== false);

if ($path === false) {
    $result['error'] = 'validateFilePath() returned false — path traversal guard or file not found';
    $result['candidate'] = DATA_DIR . '/' . $fileParam;
    $result['realpath_candidate'] = realpath(DATA_DIR . '/' . $fileParam);
    $result['php_errors'] = $errors;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- 5. Test ZipArchive::open() ----
$zip = new ZipArchive();
$openCode = $zip->open($path);
$result['ziparchive_open_code']   = $openCode;
$result['ziparchive_open_ok']     = ($openCode === true);

if ($openCode !== true) {
    $result['error'] = 'ZipArchive::open() failed';
    $zipErrors = [
        ZipArchive::ER_EXISTS  => 'File already exists',
        ZipArchive::ER_INCONS  => 'Zip archive inconsistent',
        ZipArchive::ER_INVAL   => 'Invalid argument',
        ZipArchive::ER_MEMORY  => 'Memory allocation failure',
        ZipArchive::ER_NOENT   => 'No such file',
        ZipArchive::ER_NOZIP   => 'Not a zip archive',
        ZipArchive::ER_OPEN    => 'Cannot open file',
        ZipArchive::ER_READ    => 'Read error',
        ZipArchive::ER_SEEK    => 'Seek error',
    ];
    $result['ziparchive_error_name'] = $zipErrors[$openCode] ?? 'Unknown code ' . $openCode;
    $result['file_exists']     = file_exists($path);
    $result['file_readable']   = is_readable($path);
    $result['file_size_bytes'] = file_exists($path) ? filesize($path) : null;
    $result['php_errors']      = $errors;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$result['zip_num_files'] = $zip->numFiles;

// ---- 6. List first 5 entries ----
$result['zip_first_entries'] = [];
for ($i = 0; $i < min(5, $zip->numFiles); $i++) {
    $stat = $zip->statIndex($i);
    $result['zip_first_entries'][] = [
        'index' => $i,
        'name'  => $stat['name'],
        'size'  => $stat['size'],
    ];
}

// ---- 7. Test getFileMeta() ----
$zip->close();
try {
    $meta = getFileMeta($path);
    $result['meta_ok']    = ($meta !== false);
    $result['meta_total'] = $meta ? $meta['total'] : null;
    $result['meta_type']  = $meta ? $meta['type']  : null;
    $result['meta_page0'] = ($meta && !empty($meta['pages'])) ? $meta['pages'][0] : null;
} catch (Throwable $e) {
    $result['meta_ok']    = false;
    $result['meta_error'] = $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
    $result['php_errors'] = $errors;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (!$meta) {
    $result['error'] = 'getFileMeta() returned false';
    $result['php_errors'] = $errors;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- 8. Test reading page bytes ----
$pageInfo = $meta['pages'][$pageParam] ?? $meta['pages'][0];
$result['test_page_index']    = $pageInfo['page'];
$result['test_page_zipIndex'] = $pageInfo['zipIndex'];
$result['test_page_name']     = $pageInfo['name'];

$zip2 = new ZipArchive();
$zip2->open($path);

// getStreamIndex() requires PHP 8.2+ — skip test, mark as N/A
$result['getStreamIndex_ok'] = 'N/A — requires PHP 8.2+ (server runs PHP ' . PHP_VERSION . ')';

// Test getFromIndex (available PHP 5.2+)
$data = $zip2->getFromIndex((int)$pageInfo['zipIndex']);
$result['getFromIndex_ok']    = ($data !== false && $data !== '');
$result['getFromIndex_bytes'] = ($data !== false) ? strlen($data) : null;
$zip2->close();

if ($data === false || $data === '') {
    $result['error'] = 'getFromIndex() failed — cannot read image data from ZIP';
    $result['php_errors'] = $errors;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---- 9. All good ----
$result['status']     = 'ALL_OK';
$result['conclusion'] = 'ZipArchive works. Image data is readable. Streaming should work.';
$result['php_errors'] = $errors;

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
