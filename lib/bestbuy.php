<?php
// Best Buy Open Box client + cache.
//
// Uses the official Open Box API: https://api.bestbuy.com/beta/products/openBox
// Each result is a product with several "offers" (one per condition tier). We
// flatten those into individual rows so the UI can sort uniformly by discount.
//
// Get a free key at https://developer.bestbuy.com. Without a key the tracker
// reports api_key_set=false and the UI shows a setup banner.

declare(strict_types=1);

require_once __DIR__ . '/common.php';

const BB_API_BASE = 'https://api.bestbuy.com/beta/products/openBox';
const BB_PAGE_SIZE = 100;
const BB_MAX_PAGES = 30;
const BB_REQUEST_TIMEOUT = 25.0;
const BB_INTER_PAGE_DELAY = 250000; // microseconds

const BB_CONDITION_LABELS = [
    'excellent-certified' => 'Excellent — Certified',
    'excellent' => 'Excellent',
    'satisfactory' => 'Satisfactory',
    'fair' => 'Fair',
];

function bb_cache_file(): string {
    return data_dir() . '/bestbuy_cache.json';
}

function bb_get_api_key(): ?string {
    $k = env('BESTBUY_API_KEY', '');
    return $k === '' ? null : $k;
}

function bb_category_from_path($path): string {
    if (!is_array($path)) {
        return 'Other';
    }
    $names = [];
    foreach ($path as $p) {
        if (is_array($p)) {
            $n = $p['name'] ?? '';
            if ($n !== '' && strtolower($n) !== 'best buy' && strtolower($n) !== 'all products') {
                $names[] = $n;
            }
        }
    }
    return $names ? end($names) : 'Other';
}

function bb_flatten_product(array $product): array {
    $out = [];
    $sku = (string) ($product['sku'] ?? '');
    if ($sku === '') {
        return $out;
    }
    $name = ($product['names']['title'] ?? $product['name'] ?? '');
    if ($name === '') {
        return $out;
    }
    $url = $product['links']['web'] ?? '';
    $image = $product['images']['standard'] ?? null;
    $category = bb_category_from_path($product['categoryPath'] ?? null);

    $reviews = $product['customerReviews'] ?? [];
    $rating = isset($reviews['averageScore']) ? (is_numeric($reviews['averageScore']) ? (float) $reviews['averageScore'] : null) : null;
    $review_count = isset($reviews['count']) ? (is_numeric($reviews['count']) ? (int) $reviews['count'] : null) : null;

    foreach ($product['offers'] ?? [] as $offer) {
        $prices = $offer['prices'] ?? [];
        $open_box_price = isset($prices['current']) ? (float) $prices['current'] : null;
        $regular_price = isset($prices['regular']) ? (float) $prices['regular'] : null;
        if ($open_box_price === null || $regular_price === null || $regular_price <= 0 || $open_box_price >= $regular_price) {
            continue;
        }
        $cond = strtolower((string) ($offer['condition'] ?? ''));
        $label = BB_CONDITION_LABELS[$cond] ?? (trim(str_replace('-', ' ', $cond)) !== '' ? ucwords(str_replace('-', ' ', $cond)) : 'Open Box');

        $discount_dollars = round($regular_price - $open_box_price, 2);
        $discount_pct = round($discount_dollars / $regular_price * 100, 1);

        $out[] = [
            'sku' => $sku,
            'name' => $name,
            'url' => $url,
            'image' => $image,
            'category' => $category,
            'condition' => $cond,
            'condition_label' => $label,
            'regular_price' => $regular_price,
            'open_box_price' => $open_box_price,
            'discount_dollars' => $discount_dollars,
            'discount_pct' => $discount_pct,
            'rating' => $rating,
            'review_count' => $review_count,
        ];
    }
    return $out;
}

function bb_fetch_all(): array {
    $snap = [
        'fetched_at' => time(),
        'deals' => [],
        'error' => null,
        'api_key_set' => false,
    ];
    $api_key = bb_get_api_key();
    if (!$api_key) {
        $snap['error'] = 'BESTBUY_API_KEY not set. Get a free key at https://developer.bestbuy.com and add it to your docker-compose environment.';
        return $snap;
    }
    $snap['api_key_set'] = true;

    $seen = [];
    $headers = ['Accept: application/json'];
    for ($page = 1; $page <= BB_MAX_PAGES; $page++) {
        $params = [
            'apiKey' => $api_key,
            'format' => 'json',
            'pageSize' => (string) BB_PAGE_SIZE,
            'page' => (string) $page,
        ];
        $url = BB_API_BASE . '?' . http_build_query($params);
        [$status, $body] = http_get($url, $headers, ['timeout' => BB_REQUEST_TIMEOUT]);

        if ($status === 403) {
            $snap['error'] = 'API rejected the key (403). Check BESTBUY_API_KEY.';
            break;
        }
        if ($status === 429) {
            usleep(2_000_000);
            [$status, $body] = http_get($url, $headers, ['timeout' => BB_REQUEST_TIMEOUT]);
        }
        if ($status >= 400) {
            $snap['error'] = "page $page: HTTP $status";
            break;
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $snap['error'] = "page $page: non-JSON response";
            break;
        }
        $results = $payload['results'] ?? [];
        if (!is_array($results) || $results === []) {
            break;
        }
        $new = 0;
        foreach ($results as $product) {
            foreach (bb_flatten_product($product) as $deal) {
                $key = $deal['sku'] . '|' . $deal['condition'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $snap['deals'][] = $deal;
                $new++;
            }
        }
        $total_pages = $payload['metadata']['resultSet']['totalPages'] ?? null;
        if ($total_pages !== null && $page >= (int) $total_pages) {
            break;
        }
        usleep(BB_INTER_PAGE_DELAY);
    }
    return $snap;
}

function bb_load_cache(): ?array {
    return cache_read(bb_cache_file());
}

function bb_save_cache(array $snap): void {
    cache_write(bb_cache_file(), $snap);
}

function bb_refresh(): array {
    $snap = bb_fetch_all();
    bb_save_cache($snap);
    return $snap;
}

function bb_get_cached(): ?array {
    return bb_load_cache();
}
