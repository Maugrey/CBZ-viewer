<?php
/**
 * CBZ-Viewer — Shared library
 *
 * Handles: CBZ/CBR metadata extraction, page streaming, thumbnail generation,
 * file-system scanning, path validation, and disk caching.
 *
 * Requirements: PHP 8.1+, ext-zip (standard), ext-gd or ext-imagick (thumbnails)
 * Optional:     ext-rar (PECL) or unrar/7z binary for CBR support
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Base paths
// ---------------------------------------------------------------------------

// Use dirname(__DIR__) to avoid unresolved '..' in paths (important on Windows).
$_rootDir = dirname(__DIR__);

define('DATA_DIR',  rtrim(
    str_replace('\\', '/', realpath($_rootDir . '/data') ?: ($_rootDir . '/data')),
    '/'
));
define('CACHE_DIR', rtrim(
    str_replace('\\', '/', $_rootDir . '/cache'),
    '/'
));
define('SUPPORTED_IMG_EXTS', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
unset($_rootDir);

/** Normalize a filesystem path to forward slashes + lowercase for comparison. */
function normPath(string $p): string {
    return strtolower(str_replace('\\', '/', $p));
}

// ---------------------------------------------------------------------------
// PATH VALIDATION
// ---------------------------------------------------------------------------

/**
 * Resolve and validate a CBZ/CBR file path relative to data/.
 * Returns absolute path on success, false on failure / path traversal attempt.
 */
function validateFilePath(string $file): string|false
{
    if (empty($file)) return false;
    $file = str_replace('\\', '/', trim($file, '/'));
    if (str_contains($file, '..')) return false;

    $candidate = DATA_DIR . '/' . $file;
    $real      = realpath($candidate);
    if ($real === false || !is_file($real)) return false;
    // Strict containment check — normalize both sides for Windows (case-insensitive, mixed slashes)
    if (!str_starts_with(normPath($real), normPath(DATA_DIR) . '/')) {
        return false;
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    if (!in_array($ext, ['cbz', 'cbr'], true)) return false;
    return $real;
}

/**
 * Validate a series slug (single directory name, no traversal).
 * Returns absolute path on success, false otherwise.
 */
function validateSeriesPath(string $series): string|false
{
    $series = basename($series);
    if (empty($series) || in_array($series, ['.', '..'], true)) return false;
    $real = realpath(DATA_DIR . '/' . $series);
    if ($real === false || !is_dir($real)) return false;
    if (!str_starts_with(normPath($real), normPath(DATA_DIR) . '/')) {
        return false;
    }
    return $real;
}

// ---------------------------------------------------------------------------
// FILESYSTEM HELPERS
// ---------------------------------------------------------------------------

function ensureDir(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0755, true);
}

/** Create cache/.htaccess once to block direct HTTP access */
function protectCacheDir(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    ensureDir(CACHE_DIR);
    $ht = CACHE_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
}

/**
 * Scan data/ for series directories.
 * Returns array of ['slug', 'name', 'path', 'count', 'firstFile']
 */
function getAllSeries(): array
{
    if (!is_dir(DATA_DIR)) return [];
    $list = [];
    foreach (scandir(DATA_DIR) ?: [] as $item) {
        if ($item[0] === '.') continue;
        $path  = DATA_DIR . '/' . $item;
        if (!is_dir($path)) continue;
        $files = getSeriesFiles($path);
        if (empty($files)) continue;
        $list[] = [
            'slug'      => $item,
            'name'      => formatSeriesName($item),
            'path'      => $path,
            'count'     => count($files),
            'firstFile' => $files[0],
        ];
    }
    usort($list, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $list;
}

/**
 * Return sorted CBZ/CBR files in a series directory (absolute paths).
 */
function getSeriesFiles(string $seriesPath): array
{
    $files = [];
    foreach (scandir($seriesPath) ?: [] as $item) {
        if ($item[0] === '.') continue;
        $path = $seriesPath . '/' . $item;
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (in_array($ext, ['cbz', 'cbr'], true)) {
            $files[] = $path;
        }
    }
    usort($files, fn($a, $b) => strnatcasecmp(basename($a), basename($b)));
    return $files;
}

function formatSeriesName(string $slug): string
{
    return ucwords(str_replace(['-', '_'], ' ', $slug));
}

function formatVolumeName(string $filepath): string
{
    $name = pathinfo($filepath, PATHINFO_FILENAME);
    return trim(preg_replace('/\s+/', ' ', str_replace(['_', '.'], ' ', $name)));
}

/** Return path relative to data/ (uses forward slashes) */
function getRelativePath(string $absolutePath): string
{
    // Normalize both to forward slashes; do a case-insensitive prefix strip (Windows)
    $rel  = str_replace('\\', '/', $absolutePath);
    $base = str_replace('\\', '/', DATA_DIR);
    $relLow  = strtolower($rel);
    $baseLow = strtolower($base);
    if (str_starts_with($relLow, $baseLow)) {
        return ltrim(substr($rel, strlen($base)), '/');
    }
    return ltrim($rel, '/');
}

// ---------------------------------------------------------------------------
// METADATA  (cached JSON on disk)
// ---------------------------------------------------------------------------

/**
 * Get page metadata for a CBZ or CBR file.
 * Returns: ['type' => 'cbz'|'cbr', 'total' => int, 'pages' => [...]]
 * Each page: ['page' => int, 'zipIndex' => int|null, 'name' => string]
 */
function getFileMeta(string $filePath): array|false
{
    protectCacheDir();
    $cacheDir  = CACHE_DIR . '/metadata';
    ensureDir($cacheDir);
    $cacheKey  = md5($filePath . '@' . filemtime($filePath));
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

    if (is_file($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) return $data;
    }

    $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $meta = match ($ext) {
        'cbz'   => getCbzMeta($filePath),
        'cbr'   => getCbrMeta($filePath),
        default => false,
    };

    if ($meta !== false) {
        file_put_contents($cacheFile, json_encode($meta, JSON_UNESCAPED_UNICODE));
    }
    return $meta;
}

function getCbzMeta(string $filePath): array|false
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return false;

    $images = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = $stat['name'];
        if (str_ends_with($name, '/')) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, SUPPORTED_IMG_EXTS, true)) {
            $images[] = ['zipIndex' => $i, 'name' => $name];
        }
    }
    $zip->close();

    usort($images, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    $pages = [];
    foreach ($images as $p => $img) {
        $pages[] = ['page' => $p, 'zipIndex' => $img['zipIndex'], 'name' => $img['name']];
    }
    return ['type' => 'cbz', 'total' => count($pages), 'pages' => $pages];
}

function getCbrMeta(string $filePath): array|false
{
    if (class_exists('RarArchive')) {
        return getCbrMetaRar($filePath);
    }
    return getCbrMetaShell($filePath);
}

function getCbrMetaRar(string $filePath): array|false
{
    $rar = @RarArchive::open($filePath);
    if ($rar === false) return false;

    $images = [];
    foreach ($rar->getEntries() as $entry) {
        if ($entry->isDirectory()) continue;
        $name = $entry->getName();
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, SUPPORTED_IMG_EXTS, true)) {
            $images[] = $name;
        }
    }
    $rar->close();

    usort($images, 'strnatcasecmp');
    $pages = [];
    foreach ($images as $p => $name) {
        $pages[] = ['page' => $p, 'zipIndex' => null, 'name' => $name];
    }
    return ['type' => 'cbr', 'total' => count($pages), 'pages' => $pages];
}

function getCbrMetaShell(string $filePath): array|false
{
    if (!function_exists('exec')) return false;

    $escaped = escapeshellarg($filePath);

    // Try unrar (list bare filenames)
    $out = [];
    @exec("unrar lb $escaped 2>/dev/null", $out, $code);
    if ($code === 0 && !empty($out)) {
        return buildMetaFromNames($out, 'cbr');
    }

    // Try 7z
    $out = [];
    @exec("7z l -ba -slt $escaped 2>/dev/null", $out, $code);
    if ($code === 0 && !empty($out)) {
        $names = parse7zListing($out);
        if (!empty($names)) return buildMetaFromNames($names, 'cbr');
    }

    return false;
}

function parse7zListing(array $lines): array
{
    $names = [];
    $path  = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'Path = ')) {
            $path = substr($line, 7);
        } elseif ($line === '' && $path !== null) {
            $names[] = $path;
            $path    = null;
        }
    }
    if ($path !== null) $names[] = $path;
    return $names;
}

function buildMetaFromNames(array $names, string $type): array
{
    $images = [];
    foreach ($names as $name) {
        $name = trim($name);
        if (empty($name)) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, SUPPORTED_IMG_EXTS, true)) {
            $images[] = $name;
        }
    }
    usort($images, 'strnatcasecmp');
    $pages = [];
    foreach ($images as $p => $name) {
        $pages[] = ['page' => $p, 'zipIndex' => null, 'name' => $name];
    }
    return ['type' => $type, 'total' => count($pages), 'pages' => $pages];
}

// ---------------------------------------------------------------------------
// PAGE STREAMING
// ---------------------------------------------------------------------------

function streamPage(string $filePath, int $pageNum): void
{
    $meta = getFileMeta($filePath);
    if ($meta === false || $pageNum < 0 || $pageNum >= $meta['total']) {
        http_response_code(404);
        exit('Page not found');
    }

    $info = $meta['pages'][$pageNum];
    $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($ext === 'cbz') {
        streamCbzPage($filePath, (int)$info['zipIndex'], $info['name']);
    } else {
        streamCbrPage($filePath, $info['name']);
    }
}

function streamCbzPage(string $filePath, int $zipIndex, string $name): void
{
    // Ensure no output buffering or compression interferes with binary output
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) ob_end_clean();

    $zip = new ZipArchive();
    $opened = $zip->open($filePath);
    if ($opened !== true) {
        error_log('CBZ-Viewer streamCbzPage: ZipArchive::open() failed, code=' . $opened . ' file=' . $filePath);
        http_response_code(500);
        exit();
    }

    $mime = getMimeType($name);

    // getFromIndex() is available since PHP 5.2 (getStreamIndex() requires PHP 8.2+)
    $data = $zip->getFromIndex($zipIndex);
    $zip->close();

    if ($data === false || $data === '') {
        error_log('CBZ-Viewer streamCbzPage: getFromIndex() failed, zipIndex=' . $zipIndex . ' file=' . $filePath);
        http_response_code(500);
        exit();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: public, max-age=3600');
    header('Accept-Ranges: none');
    echo $data;
}

function streamCbrPage(string $filePath, string $name): void
{
    // Method 1 — PECL RarArchive
    if (class_exists('RarArchive')) {
        $rar = @RarArchive::open($filePath);
        if ($rar !== false) {
            $entry = $rar->getEntry($name);
            if ($entry) {
                header('Content-Type: '  . getMimeType($name));
                header('Cache-Control: public, max-age=3600');
                $stream = $entry->getStream();
                fpassthru($stream);
                fclose($stream);
                $rar->close();
                return;
            }
            $rar->close();
        }
    }

    // Method 2 — popen (binary-safe)
    if (function_exists('popen')) {
        $esc  = escapeshellarg($filePath);
        $escN = escapeshellarg($name);

        foreach (["unrar p -inul $esc $escN 2>/dev/null",
                  "7z e -so $esc $escN 2>/dev/null"] as $cmd) {
            $pipe = @popen($cmd, 'r');
            if ($pipe !== false) {
                header('Content-Type: '  . getMimeType($name));
                header('Cache-Control: public, max-age=3600');
                fpassthru($pipe);
                pclose($pipe);
                return;
            }
        }
    }

    // No method available
    http_response_code(501);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'   => 'cbr_not_supported',
        'message' => 'Format CBR non supporté sur ce serveur. '
                   . 'Activez l\'extension PHP "rar" dans cPanel → MultiPHP Extensions, '
                   . 'ou convertissez le fichier en CBZ.',
    ], JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// THUMBNAILS
// ---------------------------------------------------------------------------

function getThumbnailCachePath(string $filePath): string
{
    protectCacheDir();
    $dir = CACHE_DIR . '/thumbnails';
    ensureDir($dir);
    return $dir . '/' . md5($filePath) . '.jpg';
}

function serveThumbnail(string $filePath): void
{
    $cache = getThumbnailCachePath($filePath);
    if (!is_file($cache)) {
        $data = generateThumbnail($filePath);
        if ($data !== false) {
            file_put_contents($cache, $data);
        } else {
            servePlaceholder();
            return;
        }
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($cache));
    header('Cache-Control: public, max-age=86400');
    readfile($cache);
}

function generateThumbnail(string $filePath): string|false
{
    $raw = getFirstPageRaw($filePath);
    if ($raw === false || empty($raw)) return false;
    return resizeToJpeg($raw, 280);
}

function getFirstPageRaw(string $filePath): string|false
{
    $meta = getFileMeta($filePath);
    if ($meta === false || empty($meta['pages'])) return false;

    $first = $meta['pages'][0];
    $ext   = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($ext === 'cbz') {
        $zip  = new ZipArchive();
        if ($zip->open($filePath) !== true) return false;
        $data = $zip->getFromIndex((int)$first['zipIndex']);
        $zip->close();
        return ($data !== false) ? $data : false;
    }

    // CBR — PECL
    if (class_exists('RarArchive')) {
        $rar = @RarArchive::open($filePath);
        if ($rar !== false) {
            $entry = $rar->getEntry($first['name']);
            if ($entry) {
                $stream = $entry->getStream();
                $data   = stream_get_contents($stream);
                fclose($stream);
                $rar->close();
                return $data;
            }
            $rar->close();
        }
    }

    // CBR — shell (write to temp file, binary-safe)
    if (function_exists('exec')) {
        $esc  = escapeshellarg($filePath);
        $escN = escapeshellarg($first['name']);
        $tmp  = tempnam(sys_get_temp_dir(), 'cbr_');
        foreach (["unrar p -inul $esc $escN",
                  "7z e -so $esc $escN"] as $cmd) {
            @exec("$cmd > $tmp 2>/dev/null", $o, $code);
            if ($code === 0 && is_file($tmp) && filesize($tmp) > 100) {
                $data = file_get_contents($tmp);
                @unlink($tmp);
                return $data;
            }
        }
        @unlink($tmp);
    }

    return false;
}

function resizeToJpeg(string $raw, int $width): string|false
{
    // Prefer Imagick
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->readImageBlob($raw);
            $im->thumbnailImage($width, 0);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(82);
            $out = $im->getImageBlob();
            $im->destroy();
            return $out;
        } catch (Exception) {
            // fall through
        }
    }

    // GD fallback
    $src = @imagecreatefromstring($raw);
    if ($src === false) return false;

    $srcW   = imagesx($src);
    $srcH   = imagesy($src);
    $height = (int)round($srcH * $width / $srcW);

    $thumb = imagecreatetruecolor($width, $height);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $width, $height, $srcW, $srcH);

    ob_start();
    imagejpeg($thumb, null, 82);
    $out = ob_get_clean();

    imagedestroy($src);
    imagedestroy($thumb);
    return $out ?: false;
}

function servePlaceholder(): void
{
    $w = 280; $h = 400;
    $img = imagecreatetruecolor($w, $h);
    $bg  = imagecolorallocate($img, 35, 35, 45);
    $fg  = imagecolorallocate($img, 110, 110, 140);
    $ac  = imagecolorallocate($img, 120, 90, 200);
    imagefill($img, 0, 0, $bg);
    // Simple book icon (rect)
    imagefilledrectangle($img, 80, 100, 200, 300, $ac);
    imagefilledrectangle($img, 85, 105, 195, 295, $bg);
    imagestring($img, 2, 90, 185, 'No preview', $fg);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=3600');
    imagejpeg($img, null, 80);
    imagedestroy($img);
}

// ---------------------------------------------------------------------------
// MISC HELPERS
// ---------------------------------------------------------------------------

function getMimeType(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        default       => 'application/octet-stream',
    };
}

/** Detect available CBR method for display in UI */
function detectCbrSupport(): string
{
    if (class_exists('RarArchive')) return 'rar_ext';
    if (function_exists('popen')) {
        // Quick test (won't hurt if binaries don't exist)
        return 'shell';
    }
    return 'none';
}
