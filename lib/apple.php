<?php
// Apple pickup-availability client.
//
// Apple retired /shop/fulfillment-messages (now 404s); the current endpoint is
// /shop/retail/pickup-message. The response shape also changed: stores now live
// directly under body.stores (old shape: body.content.pickupMessage.stores).
//
// Strategy mirrors the original Python:
//   1. Batch parts in small groups.
//   2. On batch failure, retry each part individually.
//   3. Browser-like headers.
//   4. Parse both new and old response shapes.

declare(strict_types=1);

require_once __DIR__ . '/common.php';

const APPLE_PICKUP_URL = 'https://www.apple.com/shop/retail/pickup-message';
const APPLE_BATCH_SIZE = 5;
const APPLE_CACHE_TTL = 30; // seconds

const APPLE_HEADERS = [
    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'Accept: application/json, text/javascript, */*; q=0.01',
    'Accept-Language: en-US,en;q=0.9',
    'Referer: https://www.apple.com/shop/buy-mac/mac-mini',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: same-origin',
    'X-Requested-With: XMLHttpRequest',
];

function apple_cache_file(): string {
    return data_dir() . '/apple_cache.json';
}

function apple_build_params(array $part_numbers, string $location): string {
    $params = [
        'pl' => 'true',
        'mts.0' => 'regular',
        'mts.1' => 'compact',
        'location' => $location,
    ];
    foreach ($part_numbers as $i => $part) {
        $params['parts.' . $i] = $part;
    }
    return http_build_query($params);
}

function apple_extract_stores(array $data): array {
    $body = $data['body'] ?? [];
    if (!is_array($body)) {
        $body = [];
    }
    $new_shape = $body['stores'] ?? null;
    if (is_array($new_shape)) {
        return $new_shape;
    }
    $old = $body['content']['pickupMessage']['stores'] ?? null;
    return is_array($old) ? $old : [];
}

function apple_fetch(array $part_numbers, string $location): array {
    $url = APPLE_PICKUP_URL . '?' . apple_build_params($part_numbers, $location);
    [$status, $body] = http_get($url, APPLE_HEADERS, ['timeout' => 10]);
    if ($status >= 400 || $body === '') {
        throw new RuntimeException("Apple HTTP $status");
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Apple returned non-JSON');
    }

    $stores_raw = apple_extract_stores($data);
    $out = [];
    foreach ($stores_raw as $s) {
        if (!is_array($s)) {
            continue;
        }
        $parts_avail = $s['partsAvailability'] ?? [];
        $parts = [];
        if (is_array($parts_avail)) {
            foreach ($parts_avail as $part_no => $info) {
                if (!is_array($info)) {
                    continue;
                }
                $pickup_display = strtolower((string) ($info['pickupDisplay'] ?? ''));
                $quote = $info['pickupSearchQuote']
                    ?? $info['storePickupQuote']
                    ?? $info['pickupQuote']
                    ?? '';
                $parts[$part_no] = [
                    'available' => $pickup_display === 'available',
                    'status' => $quote,
                ];
            }
        }

        $distance_value = $s['storedistance'] ?? null;
        if ($distance_value !== null) {
            $distance_value = is_numeric($distance_value) ? (float) $distance_value : null;
        }

        $distance = $s['storeDistanceWithUnit'] ?? null;
        if (empty($distance) && $s['storedistance'] !== null) {
            $distance = trim($s['storedistance'] . ' ' . ($s['storeDistanceVicinity'] ?? ''));
        }

        $out[] = [
            'store_name' => $s['storeName'] ?? null,
            'store_number' => $s['storeNumber'] ?? null,
            'city' => $s['city'] ?? null,
            'state' => $s['state'] ?? null,
            'distance' => $distance,
            '_distance_value' => $distance_value,
            'parts' => $parts,
        ];
    }
    return $out;
}

function apple_merge(array &$accum, array $stores): void {
    foreach ($stores as $s) {
        $sn = $s['store_number'] ?? null;
        if (!$sn) {
            continue;
        }
        if (!isset($accum[$sn])) {
            $accum[$sn] = $s;
        } else {
            $accum[$sn]['parts'] = array_merge($accum[$sn]['parts'] ?? [], $s['parts'] ?? []);
        }
    }
}

function apple_check_availability(array $part_numbers, string $location): array {
    if (empty($part_numbers)) {
        return ['stores' => [], 'failed_parts' => []];
    }

    $cache_key = $location . '|' . implode('|', $part_numbers);
    $cache = cache_read(apple_cache_file()) ?? [];
    if (isset($cache[$cache_key]) && (time() - ($cache[$cache_key]['_t'] ?? 0)) < APPLE_CACHE_TTL) {
        $hit = $cache[$cache_key]['data'];
        return $hit;
    }

    $merged = [];
    $failed_parts = [];

    foreach (array_chunk($part_numbers, APPLE_BATCH_SIZE) as $batch) {
        try {
            $stores = apple_fetch($batch, $location);
            apple_merge($merged, $stores);
        } catch (Throwable $e) {
            foreach ($batch as $part) {
                try {
                    $stores = apple_fetch([$part], $location);
                    apple_merge($merged, $stores);
                } catch (Throwable $pe) {
                    $failed_parts[] = $part;
                }
            }
        }
    }

    $store_list = array_values($merged);
    usort($store_list, fn($a, $b) => ($a['_distance_value'] ?? 1e9) <=> ($b['_distance_value'] ?? 1e9));
    foreach ($store_list as &$s) {
        unset($s['_distance_value']);
    }
    unset($s);

    // Apple's new endpoint returns 200 with zero stores for invalid SKUs.
    // Treat any requested part that never appeared as a failed SKU.
    $seen_parts = [];
    foreach ($store_list as $s) {
        foreach (array_keys($s['parts'] ?? []) as $p) {
            $seen_parts[$p] = true;
        }
    }
    $already_failed = array_fill_keys($failed_parts, true);
    foreach ($part_numbers as $part) {
        if (!isset($seen_parts[$part]) && !isset($already_failed[$part])) {
            $failed_parts[] = $part;
        }
    }

    $result = ['stores' => $store_list, 'failed_parts' => $failed_parts];
    $cache[$cache_key] = ['_t' => time(), 'data' => $result];
    cache_write(apple_cache_file(), $cache);
    return $result;
}

function apple_diag(string $part, string $location): array {
    $url = APPLE_PICKUP_URL . '?' . apple_build_params([$part], $location);
    [$status, $body] = http_get($url, APPLE_HEADERS, ['timeout' => 10]);
    $json = null;
    if ($body !== '') {
        $dec = json_decode($body, true);
        $json = is_array($dec) ? $dec : null;
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status_code' => $status,
        'url' => $url,
        'body_json' => $json,
        'body_text_preview' => substr($body, 0, 2000),
    ];
}
