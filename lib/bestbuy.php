<?php
// Best Buy Open Box client + cache.
//
// Two data sources:
//   1. Official Open Box API (https://api.bestbuy.com/beta/products/openBox) —
//      used when BESTBUY_API_KEY is set. See bb_fetch_all_api().
//   2. Key-less scraper — used when no API key is set but FLARESOLVERR_URL is.
//      Best Buy's open-box prices are NOT in the static HTML; they come from a
//      public GraphQL gateway (/gateway/graphql) that needs only a solved
//      session cookie (no API key). Flow per product:
//        a. Warm the FlareSolverr session by fetching an outlet category PLP.
//        b. Parse the PLP for product SKUs + base BSIN.
//        c. POST PlpView_ProductListItem_Init -> new price + open-box BSIN.
//        d. POST MPX_MoreBuyingOptionsSimpleAvailabilityData (per NY store) ->
//           open-box price. Compute discount vs new price.
//
// Get a free key at https://developer.bestbuy.com. Without a key, set
// FLARESOLVERR_URL (see docker-compose.yml) and the scraper runs instead.

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

// --- Key-less scraper config -------------------------------------------------
const BB_GRAPHQL_URL = 'https://www.bestbuy.com/gateway/graphql';
const BB_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
// Default NY store (locationId 467 / 11783) — the one in the captured session.
// Override with BB_STORES="Name|locationId|postalCode;..." (semicolon-separated).
const BB_DEFAULT_STORE = ['name' => 'My Store (NY)', 'locationId' => '467', 'postalCode' => '11783'];
// Outlet categories. Override with BB_CATEGORIES="url;url;...".
const BB_DEFAULT_CATEGORIES = [
    'https://www.bestbuy.com/site/outlet-refurbished-clearance/computers-tablets/pcmcat748300667665.c',
    'https://www.bestbuy.com/site/outlet-refurbished-clearance/laptops/pcmcat293300050002.c',
    'https://www.bestbuy.com/site/outlet-refurbished-clearance/tv-home-theater/pcmcat748300667645.c',
    'https://www.bestbuy.com/site/outlet-refurbished-clearance/cell-phones/pcmcat748300667697.c',
];

function bb_cache_file(): string {
    return data_dir() . '/bestbuy_cache.json';
}

function bb_get_api_key(): ?string {
    $k = env('BESTBUY_API_KEY', '');
    return $k === '' ? null : $k;
}

function bb_has_source(): bool {
    return bb_get_api_key() !== null || env('FLARESOLVERR_URL', '') !== '';
}

// ---------------------------------------------------------------------------
// Official API path
// ---------------------------------------------------------------------------

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
            'store' => null,
        ];
    }
    return $out;
}

function bb_fetch_all_api(): array {
    $snap = [
        'fetched_at' => time(),
        'deals' => [],
        'error' => null,
        'api_key_set' => true,
        'source' => 'api',
    ];
    $api_key = bb_get_api_key();
    if (!$api_key) {
        $snap['error'] = 'BESTBUY_API_KEY not set.';
        return $snap;
    }

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
        foreach ($results as $product) {
            foreach (bb_flatten_product($product) as $deal) {
                $key = $deal['sku'] . '|' . $deal['condition'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $snap['deals'][] = $deal;
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

// ---------------------------------------------------------------------------
// Key-less scraper path
// ---------------------------------------------------------------------------

function bb_solver_url(): string {
    return env('FLARESOLVERR_URL', 'http://flaresolverr:8191');
}

function bb_uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    $h = bin2hex($d);
    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}

function bb_graphql_headers(string $op): array {
    $pageId = bb_uuid();
    $reqId = 'xrequest::' . time() . '::23.195.36.208::' . bb_uuid() . '::1';
    return [
        'content-type: application/json',
        'origin: https://www.bestbuy.com',
        'x-client-id: plp-web',
        'x-requested-for-operation-name: ' . $op,
        'user-agent: ' . BB_UA,
        'x-page-request-id: ' . $pageId,
        'x-request-id: ' . $reqId,
    ];
}

function bb_graphql_post(string $op, string $queryFile, array $vars): ?array {
    $query = @file_get_contents(__DIR__ . '/' . $queryFile);
    if ($query === false || $query === '') {
        return null;
    }
    $body = json_encode([
        'operationName' => $op,
        'query' => $query,
        'variables' => $vars,
    ]);
    $resp = fs_request(BB_GRAPHQL_URL, $body, bb_graphql_headers($op));
    if ($resp === '') {
        return null;
    }
    $dec = json_decode($resp, true);
    if (!is_array($dec)) {
        return null;
    }
    return $dec;
}

// PlpView -> ['new_price'=>float, 'regular_price'=>float, 'ob_bsin'=>?string]
function bb_parse_plp_response(?array $dec): ?array {
    if (!is_array($dec)) {
        return null;
    }
    $p = $dec['data']['productBySkuId']['price'] ?? null;
    if (!is_array($p)) {
        return null;
    }
    $new = (float) ($p['customerPrice'] ?? $p['displayableRegularPrice'] ?? 0);
    $reg = (float) ($p['displayableRegularPrice'] ?? $p['customerPrice'] ?? 0);
    if ($new <= 0 && $reg <= 0) {
        return null;
    }
    // Find an open-box buying option; its pdpUrl carries the open-box BSIN.
    $ob_bsin = null;
    foreach ($dec['data']['productBySkuId']['buyingOptions'] ?? [] as $opt) {
        if (($opt['type'] ?? 'New') === 'New') {
            continue;
        }
        $pdp = $opt['pdpUrl'] ?? '';
        if (preg_match('#/([A-Z0-9]+)/sku/\d+#', $pdp, $m)) {
            $ob_bsin = $m[1];
            break;
        }
        // Fallback: explicit bsin on the product.
        $ob_bsin = $opt['product']['bsin'] ?? null;
        if ($ob_bsin) {
            break;
        }
    }
    return ['new_price' => $new, 'regular_price' => $reg, 'ob_bsin' => $ob_bsin];
}

function bb_graphql_plp(string $skuId): ?array {
    $vars = [
        'multiImageEnabled' => false,
        'useDisplayablePriceFields' => true,
        'usePlusXOffersFields' => true,
        'useMembershipUpsellFields' => true,
        'useEcoRebatesFields' => true,
        'useSpendAndGetFields' => true,
        'useCaboSucoFields' => true,
        'useGiftWithPurchaseFields' => true,
        'useOffersFields' => true,
        'skuId' => $skuId,
        'isBestbuyMember' => false,
        'productPriceInput' => [
            'customerId' => '',
            'salesChannel' => 'LargeView',
            'usePriceWithCart' => true,
            'cartTimestamp' => '',
            'useSuco' => true,
            'useCabo' => true,
            'visitorId' => bb_uuid(),
            'context' => 'plp',
            'displayLocation' => 'medium-plp',
        ],
        'skuOffersInput' => [
            'salesChannel' => 'LargeView',
            'checkmarkMessagingRequired' => true,
            'maxOffers' => 10,
            'filterFinanceMinPurchaseAmount' => false,
        ],
        'imageLimit' => 1,
        'salesChannel' => 'LargeView',
        'includeAppleIntelligenceFragment' => false,
        'isSafeMode' => false,
    ];
    $dec = bb_graphql_post('PlpView_ProductListItem_Init', 'bb_plp.graphql', $vars);
    return bb_parse_plp_response($dec);
}

// MPX -> ['low'=>float, 'high'=>float]
function bb_parse_mpx_response(?array $dec): ?array {
    if (!is_array($dec)) {
        return null;
    }
    $b = $dec['data']['bsinBuyingOptions'] ?? null;
    if (!is_array($b)) {
        return null;
    }
    $low = (float) ($b['lowestAvailableBSINPrice'] ?? 0);
    $high = (float) ($b['highestAvailableBSINPrice'] ?? 0);
    if ($low <= 0 && $high <= 0) {
        return null;
    }
    return ['low' => $low, 'high' => $high];
}

function bb_graphql_openbox(string $skuId, string $bsin, string $locationId, string $postalCode): ?array {
    $vars = [
        'bsin' => $bsin,
        'skuId' => $skuId,
        'locationId' => $locationId,
        'postalCode' => $postalCode,
        'salesChannel' => 'LargeView',
        'context' => 'PLP',
        'effectivePlanPaidMemberType' => 'NULL',
    ];
    $dec = bb_graphql_post('MPX_MoreBuyingOptionsSimpleAvailabilityData', 'bb_mpx.graphql', $vars);
    return bb_parse_mpx_response($dec);
}

function bb_scraper_stores(): array {
    $cfg = env('BB_STORES', '');
    if ($cfg === '') {
        return [BB_DEFAULT_STORE];
    }
    $stores = [];
    foreach (explode(';', $cfg) as $part) {
        $p = explode('|', trim($part));
        if (count($p) >= 3 && $p[1] !== '' && $p[2] !== '') {
            $stores[] = ['name' => $p[0], 'locationId' => $p[1], 'postalCode' => $p[2]];
        }
    }
    return $stores ?: [BB_DEFAULT_STORE];
}

function bb_scraper_categories(): array {
    $cfg = env('BB_CATEGORIES', '');
    if ($cfg === '') {
        return BB_DEFAULT_CATEGORIES;
    }
    $cats = [];
    foreach (explode(';', $cfg) as $u) {
        $u = trim($u);
        if ($u !== '') {
            $cats[] = $u;
        }
    }
    return $cats ?: BB_DEFAULT_CATEGORIES;
}

// Parse an outlet category PLP: extract base products (skuId, bsin, name, url, image).
// Open-box detection happens later via GraphQL (PLP only lists the base product).
function bb_parse_outlet_listing(string $html): array {
    $products = [];
    $seen = [];
    if (!preg_match_all('/"skuId":"(\d+)"/', $html, $m, PREG_OFFSET_CAPTURE)) {
        return $products;
    }
    foreach ($m[0] as $cap) {
        $i = $cap[1];
        $sku = $cap[0]; // includes quotes; extract digits
        if (!preg_match('/\d+/', $sku, $sm)) {
            continue;
        }
        $sku = $sm[0];
        if (isset($seen[$sku])) {
            continue;
        }
        // Window around the skuId to capture bsin / url / image / title.
        $w = substr($html, max(0, $i - 200), 8000);
        if (!preg_match('/"bsin":"([A-Z0-9]+)"/', $w, $bm)) {
            continue;
        }
        $bsin = $bm[1];
        if (!preg_match('/"pdp":"(https:\/\/www\.bestbuy\.com\/product\/[^"]+)"/', $w, $um)) {
            continue;
        }
        $url = $um[1];
        $im = preg_match('/"piscesHref":"(https:\/\/pisces\.bbystatic\.com\/[^"]+)"/', $w, $imx) ? $imx[1] : null;
        $nm = preg_match('/"title":"([^"]+)"/', $w, $nmx) ? $nmx[1] : '';
        if ($nm === '') {
            // Derive a name from the PDP slug.
            if (preg_match('#/product/([^/]+)/#', $url, $slm)) {
                $nm = ucwords(str_replace('-', ' ', $slm[1]));
            }
        }
        $seen[$sku] = true;
        $products[] = [
            'skuId' => $sku,
            'bsin' => $bsin,
            'name' => $nm,
            'url' => $url,
            'image' => $im,
            'category' => 'Open Box',
        ];
    }
    return $products;
}

function bb_fetch_all_scraper(): array {
    $snap = [
        'fetched_at' => time(),
        'deals' => [],
        'error' => null,
        'api_key_set' => true,
        'source' => 'scraper',
    ];
    if (env('FLARESOLVERR_URL', '') === '') {
        $snap['api_key_set'] = false;
        $snap['source'] = null;
        $snap['error'] = 'FLARESOLVERR_URL not set; key-less Best Buy scraping needs FlareSolverr. Set BESTBUY_API_KEY or FLARESOLVERR_URL.';
        return $snap;
    }

    $stores = bb_scraper_stores();
    $categories = bb_scraper_categories();
    $max_skus = (int) env('BB_MAX_SKUS', '12');
    $min_pct = (float) env('BB_MIN_PCT', '25');

    // Probe: Best Buy serves open-box prices ONLY via XHR GraphQL. FlareSolverr
    // can navigate pages but cannot run XHR, so a GraphQL POST through it comes
    // back as HTML (no price data), and a direct server POST is Cloudflare-blocked.
    // Detect that early so we don't burn dozens of solver calls for nothing.
    if (bb_graphql_plp('6673554') === null) {
        $snap['api_key_set'] = false;
        $snap['source'] = null;
        $snap['error'] = 'Key-less Best Buy scraping is blocked: open-box prices load via XHR GraphQL that '
            . 'FlareSolverr cannot execute (it only does page navigation), and a direct server POST is '
            . 'Cloudflare-blocked. Set BESTBUY_API_KEY (free at developer.bestbuy.com) for reliable open-box '
            . 'data, or deploy a headless-browser (Playwright/Puppeteer) scraper that can run XHR.';
        return $snap;
    }

    $seen = [];
    foreach ($categories as $cat_url) {
        // Warm the session + grab the listing in one FlareSolverr call.
        $html = fs_request($cat_url);
        if ($html === '') {
            $snap['error'] = ($snap['error'] ? $snap['error'] . '; ' : '') . "failed to load $cat_url";
            continue;
        }
        $list = bb_parse_outlet_listing($html);
        $processed = 0;
        foreach ($list as $prod) {
            if ($processed >= $max_skus) {
                break;
            }
            $sku = $prod['skuId'];
            if (isset($seen[$sku])) {
                continue;
            }
            $seen[$sku] = true;
            $processed++;

            $plp = bb_graphql_plp($sku);
            if (!$plp || $plp['ob_bsin'] === null) {
                continue;
            }
            $baseline = $plp['new_price'] > 0 ? $plp['new_price'] : $plp['regular_price'];
            if ($baseline <= 0) {
                continue;
            }

            $best = null;
            foreach ($stores as $store) {
                $ob = bb_graphql_openbox($sku, $plp['ob_bsin'], $store['locationId'], $store['postalCode']);
                if (!$ob) {
                    continue;
                }
                $open_box = $ob['low'] > 0 ? $ob['low'] : $ob['high'];
                if ($open_box <= 0 || $open_box >= $baseline) {
                    continue;
                }
                $pct = round(($baseline - $open_box) / $baseline * 100, 1);
                if ($pct < $min_pct) {
                    continue;
                }
                $cand = [
                    'store' => $store['name'],
                    'open_box' => $open_box,
                    'regular' => $baseline,
                    'pct' => $pct,
                ];
                if ($best === null || $pct > $best['pct']) {
                    $best = $cand;
                }
            }
            if (!$best) {
                continue;
            }

            $snap['deals'][] = [
                'sku' => $sku,
                'name' => $prod['name'],
                'url' => $prod['url'],
                'image' => $prod['image'],
                'category' => $prod['category'],
                'condition' => 'open-box',
                'condition_label' => 'Open Box (' . $best['store'] . ')',
                'regular_price' => $best['regular'],
                'open_box_price' => $best['open_box'],
                'discount_dollars' => round($best['regular'] - $best['open_box'], 2),
                'discount_pct' => $best['pct'],
                'rating' => null,
                'review_count' => null,
                'store' => $best['store'],
            ];
        }
    }
    return $snap;
}

// ---------------------------------------------------------------------------
// Orchestration
// ---------------------------------------------------------------------------

function bb_fetch_all(): array {
    if (bb_get_api_key() !== null) {
        return bb_fetch_all_api();
    }
    if (env('FLARESOLVERR_URL', '') !== '') {
        return bb_fetch_all_scraper();
    }
    return [
        'fetched_at' => time(),
        'deals' => [],
        'error' => 'No Best Buy source configured. Set BESTBUY_API_KEY or FLARESOLVERR_URL.',
        'api_key_set' => false,
        'source' => null,
    ];
}

function bb_load_cache(): ?array {
    return cache_read(bb_cache_file());
}

function bb_save_cache(array $snap): void {
    cache_write(bb_cache_file(), $snap);
}

function bb_refresh(): array {
    set_time_limit(0);
    $snap = bb_fetch_all();
    bb_save_cache($snap);
    return $snap;
}

function bb_get_cached(): ?array {
    return bb_load_cache();
}
