<?php
// Micro Center open-box scraper + cache.
//
// Micro Center front-runs Akamai bot protection, so a plain HTTP GET gets a
// challenge page. The original Python used nodriver (headless Chromium) and
// waited for the JS fingerprint to finish before reading the DOM. The faithful
// PHP equivalent is to shell out to headless Chromium with --dump-dom and a
// virtual-time budget, then parse the rendered HTML with DOMDocument/XPath.
//
// Parsing is intentionally tolerant: every selector falls back to a broader
// sibling before giving up on a card, so minor markup tweaks won't kill the feed.

declare(strict_types=1);

require_once __DIR__ . '/common.php';

const MC_STORES = [
    // California
    'tustin'          => ['id' => '101', 'name' => 'Tustin, CA', 'state' => 'CA'],
    'santa-clara'     => ['id' => '195', 'name' => 'Santa Clara, CA', 'state' => 'CA'],
    // Colorado
    'denver'          => ['id' => '181', 'name' => 'Denver, CO', 'state' => 'CO'],
    // Florida
    'miami'           => ['id' => '045', 'name' => 'Miami, FL', 'state' => 'FL'],
    // Georgia
    'duluth'          => ['id' => '041', 'name' => 'Duluth, GA', 'state' => 'GA'],
    'marietta'        => ['id' => '171', 'name' => 'Marietta, GA', 'state' => 'GA'],
    // Illinois
    'chicago'         => ['id' => '151', 'name' => 'Chicago, IL', 'state' => 'IL'],
    'westmont'        => ['id' => '025', 'name' => 'Westmont, IL', 'state' => 'IL'],
    // Indiana
    'indianapolis'    => ['id' => '131', 'name' => 'Indianapolis, IN', 'state' => 'IN'],
    // Kansas
    'overland-park'   => ['id' => '191', 'name' => 'Overland Park, KS', 'state' => 'KS'],
    // Massachusetts
    'cambridge'       => ['id' => '121', 'name' => 'Cambridge, MA', 'state' => 'MA'],
    // Maryland
    'rockville'       => ['id' => '085', 'name' => 'Rockville, MD', 'state' => 'MD'],
    'parkville'       => ['id' => '125', 'name' => 'Parkville (Baltimore), MD', 'state' => 'MD'],
    // Michigan
    'madison-heights' => ['id' => '055', 'name' => 'Madison Heights, MI', 'state' => 'MI'],
    // Minnesota
    'st-louis-park'   => ['id' => '045', 'name' => 'St. Louis Park, MN', 'state' => 'MN'],
    // Missouri
    'brentwood'       => ['id' => '095', 'name' => 'Brentwood (St. Louis), MO', 'state' => 'MO'],
    // North Carolina
    'charlotte'       => ['id' => '165', 'name' => 'Charlotte, NC', 'state' => 'NC'],
    // New Jersey
    'paterson'        => ['id' => '075', 'name' => 'North Jersey (Paterson), NJ', 'state' => 'NJ'],
    // New York
    'westbury'        => ['id' => '065', 'name' => 'Westbury, NY', 'state' => 'NY'],
    'flushing'        => ['id' => '051', 'name' => 'Flushing, NY', 'state' => 'NY'],
    'yonkers'         => ['id' => '105', 'name' => 'Yonkers, NY', 'state' => 'NY'],
    'brooklyn'        => ['id' => '115', 'name' => 'Brooklyn, NY', 'state' => 'NY'],
    // Ohio
    'columbus'        => ['id' => '141', 'name' => 'Columbus, OH', 'state' => 'OH'],
    'mayfield-heights'=> ['id' => '051', 'name' => 'Mayfield Heights, OH', 'state' => 'OH'],
    'sharonville'     => ['id' => '071', 'name' => 'Sharonville (Cincinnati), OH', 'state' => 'OH'],
    // Pennsylvania
    'st-davids'       => ['id' => '061', 'name' => 'St. Davids, PA', 'state' => 'PA'],
    // Texas
    'houston'         => ['id' => '155', 'name' => 'Houston, TX', 'state' => 'TX'],
    'dallas'          => ['id' => '135', 'name' => 'Dallas (Richardson), TX', 'state' => 'TX'],
    // Virginia
    'fairfax'         => ['id' => '081', 'name' => 'Fairfax, VA', 'state' => 'VA'],
];
const MC_DEFAULT_STORE = 'westbury';

// "Shop All Open Box Deals" — every category. (The old 4294966937 was the
// Graphics Cards open-box node, which is why the scraper only saw GPUs.)
const MC_OPEN_BOX_FQ = 'Valuable Links:Open Box';
const MC_SEARCH_URL = 'https://www.microcenter.com/search/search_results.aspx';
const MC_PAGE_SIZE = 96;
const MC_MAX_PAGES = 20;
const MC_REQUEST_TIMEOUT = 25.0;

const MC_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

function mc_cache_file(): string {
    return data_dir() . '/microcenter_cache.json';
}

// ---------------------------------------------------------------------------
// Render: solve Cloudflare via FlareSolverr, fall back to headless Chromium
// ---------------------------------------------------------------------------

function mc_solver_url(): string {
    return env('FLARESOLVERR_URL', 'http://flaresolverr:8191');
}

// Ask FlareSolverr to fetch + solve the Cloudflare "Just a moment…" challenge.
// Returns the solved HTML, or '' if it couldn't.
function mc_render_via_solver(string $url): string {
    $solver = rtrim(mc_solver_url(), '/');
    $payload = json_encode([
        'cmd' => 'request.get',
        'url' => $url,
        'maxTimeout' => 60000,
    ]);
    [$status, $body] = http_get($solver . '/v1', [
        'Content-Type: application/json',
        'Accept: application/json',
    ], ['timeout' => 75, 'post' => $payload]);

    if ($status < 200 || $status >= 300 || $body === '') {
        return '';
    }
    $dec = json_decode($body, true);
    if (!is_array($dec)) {
        return '';
    }
    if (($dec['status'] ?? '') === 'ok' && isset($dec['solution']['response'])) {
        return (string) $dec['solution']['response'];
    }
    return '';
}

// Last-resort: headless Chromium --dump-dom. Usually blocked by Cloudflare, but
// harmless to try if FlareSolverr is unavailable.
function mc_render_via_chromium(string $url): string {
    $ua = escapeshellarg(MC_USER_AGENT);
    $u = escapeshellarg($url);
    $cmd = sprintf(
        'chromium --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage '
        . '--disable-software-rasterizer --lang=en-US --user-agent=%s '
        . '--virtual-time-budget=12000 --timeout=20000 --dump-dom %s 2>/dev/null',
        $ua,
        $u
    );
    $out = shell_exec($cmd);
    return $out === null ? '' : $out;
}

function mc_render_html(string $url): string {
    $html = mc_render_via_solver($url);
    if ($html !== '') {
        return $html;
    }
    return mc_render_via_chromium($url);
}

// ---------------------------------------------------------------------------
// HTML parsing helpers (DOMXPath)
// ---------------------------------------------------------------------------

function mc_class_pred(string $class): string {
    return sprintf("contains(concat(' ', normalize-space(@class), ' '), ' %s ')", $class);
}

function mc_xpath_one(DOMNode $ctx, string $expr): ?DOMElement {
    $xp = new DOMXPath($ctx->ownerDocument);
    $n = $xp->query($expr, $ctx);
    if ($n === false || $n->length === 0) {
        return null;
    }
    $first = $n->item(0);
    return $first instanceof DOMElement ? $first : null;
}

function mc_first_text(DOMNode $ctx, array $exprs): ?string {
    foreach ($exprs as $e) {
        $el = mc_xpath_one($ctx, $e);
        if ($el) {
            $t = trim(preg_replace('/\s+/', ' ', $el->textContent));
            if ($t !== '') {
                return $t;
            }
        }
    }
    return null;
}

function mc_attr(DOMElement $el, string $name): string {
    return $el->hasAttribute($name) ? (string) $el->getAttribute($name) : '';
}

function mc_parse_price(?string $text): ?float {
    if ($text === null || $text === '') {
        return null;
    }
    if (preg_match('/\$?\s*([\d,]+\.\d{2}|\d+)/', $text, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    return null;
}

// ---------------------------------------------------------------------------
// Card extraction
// ---------------------------------------------------------------------------

function mc_extract_card(DOMElement $card): ?array {
    // Name + product URL
    $a = mc_xpath_one($card, './/a[' . mc_class_pred('productClickItemV2') . ']')
        ?? mc_xpath_one($card, './/h2/a')
        ?? mc_xpath_one($card, './/a[contains(@href, \'/product/\')]');
    if (!$a) {
        return null;
    }
    $name = mc_attr($a, 'data-name');
    if ($name === '') {
        $name = trim(preg_replace('/\s+/', ' ', $a->textContent));
    }
    $href = mc_attr($a, 'href');
    $url = str_starts_with($href, 'http') ? $href : 'https://www.microcenter.com' . $href;

    // SKU
    $sku = mc_attr($card, 'data-id') ?: mc_attr($card, 'data-sku');
    if (!$sku) {
        $inner = mc_xpath_one($card, './/*[@data-id]') ?? mc_xpath_one($card, './/*[@data-sku]');
        if ($inner) {
            $sku = mc_attr($inner, 'data-id') ?: mc_attr($inner, 'data-sku');
        }
    }
    if (!$sku) {
        if (preg_match('#/product/(\d+)#', $url, $m)) {
            $sku = $m[1];
        } else {
            $sku = $url;
        }
    }

    // Image
    $img = mc_xpath_one($card, './/img');
    $image = null;
    if ($img) {
        $src = mc_attr($img, 'src') ?: mc_attr($img, 'data-src') ?: mc_attr($img, 'data-original');
        if ($src !== '') {
            $image = str_starts_with($src, '//') ? 'https:' . $src : $src;
        }
    }

    // Open-box vs regular price. Micro Center uses two different markups:
    //  - Category open-box page: `itemprop="price"` is the OPEN BOX price
    //    ("Our price $X"); the original/new price is in a <strike> ("Original price $Y").
    //  - General "Shop All Open Box" page: `itemprop="price"` is the NEW/REGULAR
    //    price ("Regular price $Y"); the OPEN BOX price sits in a
    //    `.price-label.compareTo` ("Open Box From $X").
    $itemprop_txt = mc_first_text($card, ['.//*[@itemprop=\'price\']']);
    $strike_txt   = mc_first_text($card, ['.//strike', './/del', './/s']);
    $ob_label_txt = mc_first_text($card, [
        './/*[' . mc_class_pred('compareTo') . ']',
        './/*[' . mc_class_pred('price-label') . ']',
    ]);

    $open_box_price = null;
    $regular_price = null;
    if ($ob_label_txt !== null) {
        // Layout B: compareTo = open-box price, itemprop = regular price.
        $regular_price = mc_parse_price($itemprop_txt);
        $open_box_price = mc_parse_price($ob_label_txt);
    } elseif ($strike_txt !== null) {
        // Layout A: itemprop = open-box price, strike = regular price.
        $open_box_price = mc_parse_price($itemprop_txt);
        $regular_price = mc_parse_price($strike_txt);
    }

    if ($open_box_price === null || $regular_price === null || $regular_price <= 0) {
        return null;
    }
    if ($open_box_price >= $regular_price) {
        return null; // not actually a discount
    }

    $condition = mc_first_text($card, [
        './/*[' . mc_class_pred('condition') . ']',
        './/*[' . mc_class_pred('openBoxCondition') . ']',
        './/*[' . mc_class_pred('product-condition') . ']',
    ]) ?: 'Open Box';

    $category = mc_attr($card, 'data-category');
    if (!$category) {
        $cinner = mc_xpath_one($card, './/*[@data-category]');
        if ($cinner) {
            $category = mc_attr($cinner, 'data-category');
        }
    }
    if (!$category) {
        $category = mc_first_text($card, [
            './/*[' . mc_class_pred('category') . ']',
            './/*[' . mc_class_pred('breadcrumb') . ']',
        ]);
    }
    $category = $category ? trim(ucwords(strtolower($category))) : 'Other';
    if ($category === '') {
        $category = 'Other';
    }

    $discount_dollars = round($regular_price - $open_box_price, 2);
    $discount_pct = round($discount_dollars / $regular_price * 100, 1);

    return [
        'sku' => (string) $sku,
        'name' => $name,
        'url' => $url,
        'image' => $image,
        'category' => $category,
        'condition' => $condition,
        'regular_price' => $regular_price,
        'open_box_price' => $open_box_price,
        'discount_dollars' => $discount_dollars,
        'discount_pct' => $discount_pct,
    ];
}

function mc_parse_listing(string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $xp = new DOMXPath($doc);
    $expr = '//li[' . mc_class_pred('product_wrapper') . ']'
        . ' | //article[' . mc_class_pred('product_wrapper') . ']'
        . ' | //*[' . mc_class_pred('product_wrapper') . ']'
        . ' | //li[@data-id]';
    $nodes = $xp->query($expr);
    $out = [];
    if ($nodes) {
        foreach ($nodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            try {
                $d = mc_extract_card($c);
                if ($d) {
                    $out[] = $d;
                }
            } catch (Throwable $e) {
                // one bad card shouldn't kill the page
            }
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Fetch + cache
// ---------------------------------------------------------------------------

function mc_fetch_store(string $store_key): array {
    if (!isset(MC_STORES[$store_key])) {
        throw new InvalidArgumentException("unknown store '$store_key'");
    }
    $meta = MC_STORES[$store_key];
    $snap = [
        'store_key' => $store_key,
        'store_id' => $meta['id'],
        'store_name' => $meta['name'],
        'fetched_at' => time(),
        'deals' => [],
        'error' => null,
    ];

    $seen = [];
    $page = 1;
    try {
        for (; $page <= MC_MAX_PAGES; $page++) {
            $params = [
                'fq' => MC_OPEN_BOX_FQ,
                'storeid' => $meta['id'],
                'myStore' => 'true',
                'pagecount' => (string) MC_PAGE_SIZE,
                'currentpage' => (string) $page,
                'sortby' => 'match',
            ];
            $url = MC_SEARCH_URL . '?' . http_build_query($params);
            $html = mc_render_html($url);
            if ($html === '') {
                $snap['error'] = "page $page: chromium returned no HTML";
                break;
            }
            $page_deals = mc_parse_listing($html);

            $new = 0;
            foreach ($page_deals as $d) {
                $key = $d['sku'] . '|' . $d['condition'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $snap['deals'][] = $d;
                    $new++;
                }
            }
            if ($new === 0) {
                break;
            }
            // jitter to look less bot-like
            usleep((int) ((0.6 + mt_rand() / mt_getrandmax() * 0.8) * 1_000_000));
        }
    } catch (Throwable $e) {
        $snap['error'] = "page $page: " . $e->getMessage();
    }

    return $snap;
}

function mc_load_cache(): array {
    return cache_read(mc_cache_file()) ?? [];
}

function mc_save_cache(array $cache): void {
    cache_write(mc_cache_file(), $cache);
}

function mc_refresh(string $store_key): array {
    $snap = mc_fetch_store($store_key);
    $cache = mc_load_cache();
    $cache[$store_key] = $snap;
    mc_save_cache($cache);
    return $snap;
}

function mc_get_cached(string $store_key): ?array {
    $cache = mc_load_cache();
    return $cache[$store_key] ?? null;
}
