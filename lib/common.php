<?php
// Shared helpers: HTTP client, JSON I/O, simple file cache, path utils.
// No external dependencies — uses ext-curl, ext-json, ext-dom.

declare(strict_types=1);

const APP_DIR = __DIR__;
define('ROOT_DIR', dirname(__DIR__));

function env(string $key, string $default = ''): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function data_dir(): string {
    // PRODUCTS_FILE points at the catalog file; caches live alongside it.
    $pf = env('PRODUCTS_FILE', APP_DIR . '/products.json');
    $dir = dirname($pf);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function products_path(): string {
    return env('PRODUCTS_FILE', ROOT_DIR . '/products.json');
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

function http_get(string $url, array $headers = [], array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($opts['timeout'] ?? 25));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) ($opts['connect_timeout'] ?? 10));
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$status, $body === false ? '' : $body, $err];
}

function http_get_json(string $url, array $headers = [], array $opts = []): ?array {
    [$status, $body, $err] = http_get($url, $headers, $opts);
    if ($err !== '' || $status >= 400) {
        return null;
    }
    $dec = json_decode($body, true);
    return is_array($dec) ? $dec : null;
}

// ---------------------------------------------------------------------------
// JSON responses / request body
// ---------------------------------------------------------------------------

function send_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array {
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : [];
}

function query_param(string $key, string $default = ''): string {
    if (isset($_GET[$key])) {
        return (string) $_GET[$key];
    }
    return $default;
}

// ---------------------------------------------------------------------------
// Tiny file-backed cache (used by Apple + scrapers)
// ---------------------------------------------------------------------------

function cache_read(string $file): ?array {
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : null;
}

function cache_write(string $file, $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Non-fatal: a cache write failing (e.g. read-only volume) must never
    // corrupt the API response with a PHP warning.
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        return;
    }
    @rename($tmp, $file);
}

// Render a view file (HTML shell) to the client.
function render_view(string $name): void {
    $file = ROOT_DIR . '/views/' . $name;
    if (!is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    exit;
}

// Serve a static asset (css/js/etc.) from the project root, with the right
// Content-Type. Robust regardless of the built-in server's document root.
function serve_static(string $rel): void {
    $file = ROOT_DIR . '/' . ltrim($rel, '/');
    if (!is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    $mime = 'application/octet-stream';
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'css':  $mime = 'text/css'; break;
        case 'js':   $mime = 'application/javascript'; break;
        case 'html': $mime = 'text/html'; break;
        case 'json': $mime = 'application/json'; break;
        case 'svg':  $mime = 'image/svg+xml'; break;
        case 'png':  $mime = 'image/png'; break;
        case 'jpg':
        case 'jpeg': $mime = 'image/jpeg'; break;
        case 'gif':  $mime = 'image/gif'; break;
        case 'ico':  $mime = 'image/x-icon'; break;
        case 'woff2':$mime = 'font/woff2'; break;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
