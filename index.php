<?php
// techinstock — PHP front controller.
//
// Single-file router that replaces FastAPI. Run with the built-in server:
//   php -S 0.0.0.0:8000 index.php
//
// Static assets under /static/ are served directly by the built-in server
// (the router returns false for them). Everything else is routed here.

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/apple.php';
require_once __DIR__ . '/lib/microcenter.php';
require_once __DIR__ . '/lib/bestbuy.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Static files: serve them directly (docroot-independent).
if (str_starts_with($path, '/static/') && is_file(ROOT_DIR . '/' . ltrim($path, '/'))) {
    serve_static($path);
}

function route_404(): void {
    send_json(['detail' => 'Not found'], 404);
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/') {
    render_view('home.html');
}
if ($method === 'GET' && $path === '/apple') {
    render_view('apple.html');
}
if ($method === 'GET' && $path === '/microcenter') {
    render_view('microcenter.html');
}
if ($method === 'GET' && $path === '/bestbuy') {
    render_view('bestbuy.html');
}
if ($method === 'GET' && $path === '/healthz') {
    send_json(['status' => 'ok']);
}

// ---------------------------------------------------------------------------
// Apple — catalog + availability API
// ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/products') {
    $data = products_load();
    $data['default_zip'] = products_default_zip();
    send_json($data);
}

if ($method === 'POST' && $path === '/api/products') {
    $data = products_add(read_json_body());
    $data['default_zip'] = products_default_zip();
    send_json($data, 200);
}

if ($method === 'PATCH' && preg_match('#^/api/categories/([^/]+)$#', $path, $m)) {
    $id = urldecode($m[1]);
    $data = products_edit_category($id, read_json_body());
    $data['default_zip'] = products_default_zip();
    send_json($data);
}

if ($method === 'DELETE' && preg_match('#^/api/products/(.+)$#', $path, $m)) {
    $part = urldecode($m[1]);
    $data = products_delete($part);
    $data['default_zip'] = products_default_zip();
    send_json($data);
}

if ($method === 'PATCH' && preg_match('#^/api/products/(.+)$#', $path, $m)) {
    $part = urldecode($m[1]);
    $data = products_edit($part, read_json_body());
    $data['default_zip'] = products_default_zip();
    send_json($data);
}

if ($method === 'GET' && $path === '/api/check') {
    $zip = query_param('zip', products_default_zip());
    if (strlen($zip) < 3 || strlen($zip) > 10) {
        send_json(['detail' => 'zip must be 3-10 chars'], 400);
    }
    $data = products_load();
    $all_parts = [];
    foreach ($data['categories'] ?? [] as $cat) {
        foreach ($cat['products'] ?? [] as $p) {
            $all_parts[] = $p['part'];
        }
    }
    if (empty($all_parts)) {
        send_json(['zip' => $zip, 'stores' => [], 'failed_parts' => [], 'checked_at' => null]);
    }
    try {
        $result = apple_check_availability($all_parts, $zip);
    } catch (Throwable $e) {
        send_json(['detail' => 'Apple API error: ' . $e->getMessage()], 502);
    }
    send_json([
        'zip' => $zip,
        'stores' => $result['stores'],
        'failed_parts' => $result['failed_parts'],
        'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

if ($method === 'GET' && $path === '/api/diag') {
    $part = query_param('part', '');
    $zip = query_param('zip', products_default_zip());
    if (strlen($part) < 4) {
        send_json(['detail' => 'part required (min 4 chars)'], 400);
    }
    send_json(apple_diag($part, $zip));
}

// ---------------------------------------------------------------------------
// Micro Center API
// ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/microcenter/api/stores') {
    send_json(['default' => MC_DEFAULT_STORE, 'stores' => MC_STORES]);
}

if ($method === 'GET' && $path === '/microcenter/api/deals') {
    $store = query_param('store', MC_DEFAULT_STORE);
    if (!isset(MC_STORES[$store])) {
        send_json(['detail' => "unknown store '$store'"], 404);
    }
    $snap = mc_get_cached($store);
    if ($snap === null) {
        send_json([
            'store_key' => $store,
            'store_name' => MC_STORES[$store]['name'],
            'fetched_at' => null,
            'deals' => [],
            'error' => 'no cache yet — hit refresh',
        ]);
    }
    send_json($snap);
}

if ($method === 'POST' && $path === '/microcenter/api/refresh') {
    $store = query_param('store', MC_DEFAULT_STORE);
    if (!isset(MC_STORES[$store])) {
        send_json(['detail' => "unknown store '$store'"], 404);
    }
    send_json(mc_refresh($store));
}

// ---------------------------------------------------------------------------
// Best Buy API
// ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/bestbuy/api/status') {
    $snap = bb_get_cached();
    send_json([
        'api_key_set' => bb_get_api_key() !== null,
        'fetched_at' => $snap['fetched_at'] ?? null,
        'count' => $snap ? count($snap['deals']) : 0,
        'error' => $snap['error'] ?? null,
    ]);
}

if ($method === 'GET' && $path === '/bestbuy/api/deals') {
    $snap = bb_get_cached();
    if ($snap === null) {
        send_json([
            'fetched_at' => null,
            'deals' => [],
            'error' => 'no cache yet — hit refresh',
            'api_key_set' => bb_get_api_key() !== null,
        ]);
    }
    send_json($snap);
}

if ($method === 'POST' && $path === '/bestbuy/api/refresh') {
    send_json(bb_refresh());
}

// Nothing matched.
route_404();
