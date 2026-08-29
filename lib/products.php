<?php
// Apple catalog (products.json) load/save + CRUD.
// Ported from the original FastAPI handlers in main.py.

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function products_load(): array {
    $path = products_path();
    if (!is_file($path)) {
        return ['categories' => []];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['categories' => []];
    }
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : ['categories' => []];
}

function products_save(array $data): void {
    $path = products_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

function products_find_category_index(array $categories, string $id): int {
    foreach ($categories as $i => $c) {
        if (strcasecmp($c['id'] ?? '', $id) === 0) {
            return $i;
        }
    }
    return -1;
}

function products_default_zip(): string {
    return env('DEFAULT_ZIP', '11793');
}

// Reindex categories + products to dense, zero-based arrays so JSON output
// stays a list (not an object with numeric keys).
function products_normalize(array &$data): void {
    $cats = [];
    foreach ($data['categories'] ?? [] as $cat) {
        if (empty($cat['products'])) {
            continue;
        }
        $cats[] = [
            'id' => $cat['id'],
            'name' => $cat['name'],
            'products' => array_values($cat['products']),
        ];
    }
    $data['categories'] = $cats;
}

// Locate a product by part number; returns [catIndex, prodIndex] or null.
function products_find_product(array $categories, string $part): ?array {
    foreach ($categories as $ci => $cat) {
        foreach ($cat['products'] ?? [] as $pi => $p) {
            if (($p['part'] ?? '') === $part) {
                return [$ci, $pi];
            }
        }
    }
    return null;
}

function products_add(array $body): array {
    $category_id = trim((string) ($body['category_id'] ?? ''));
    $part = trim((string) ($body['part'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    if ($category_id === '' || $part === '' || $name === '') {
        send_json(['detail' => 'category_id, part and name are required'], 422);
    }

    $data = products_load();
    $categories = &$data['categories'];
    $ci = products_find_category_index($categories, $category_id);
    if ($ci === -1) {
        $categories[] = [
            'id' => strtolower($category_id),
            'name' => ucwords(str_replace('-', ' ', $category_id)),
            'products' => [],
        ];
        $ci = array_key_last($categories);
    }

    foreach ($categories[$ci]['products'] as $p) {
        if (($p['part'] ?? '') === $part) {
            send_json(['detail' => 'Part already tracked'], 409);
        }
    }
    $categories[$ci]['products'][] = ['part' => $part, 'name' => $name];
    products_normalize($data);
    products_save($data);
    return $data;
}

function products_edit(string $part, array $body): array {
    $data = products_load();

    $loc = products_find_product($data['categories'], $part);
    if ($loc === null) {
        send_json(['detail' => 'Part not found'], 404);
    }
    [$ci, $pi] = $loc;

    if (isset($body['name'])) {
        $data['categories'][$ci]['products'][$pi]['name'] = trim((string) $body['name']);
    }
    if (isset($body['part'])) {
        $new_part = trim((string) $body['part']);
        foreach ($data['categories'][$ci]['products'] as $p) {
            if (($p['part'] ?? '') === $new_part) {
                send_json(['detail' => 'Part number already exists'], 409);
            }
        }
        $data['categories'][$ci]['products'][$pi]['part'] = $new_part;
    }

    // Move to a different category if requested.
    if (isset($body['category_id'])) {
        $target_id = (string) $body['category_id'];
        $ti = products_find_category_index($data['categories'], $target_id);
        if ($ti === -1) {
            $data['categories'][] = [
                'id' => strtolower(trim($target_id)),
                'name' => ucwords(str_replace('-', ' ', trim($target_id))),
                'products' => [],
            ];
            $ti = array_key_last($data['categories']);
        }
        if ($ti !== $ci) {
            $moved = $data['categories'][$ci]['products'][$pi];
            array_splice($data['categories'][$ci]['products'], $pi, 1);
            $data['categories'][$ti]['products'][] = $moved;
            // Re-find the moved product after both arrays were mutated.
            $ci = $ti;
            foreach ($data['categories'][$ti]['products'] as $j => $p) {
                if (($p['part'] ?? null) === ($moved['part'] ?? null)) {
                    $pi = $j;
                    break;
                }
            }
        }
    }

    // Reorder within the (possibly new) category.
    if (isset($body['position'])) {
        $idx = max(0, min((int) $body['position'], count($data['categories'][$ci]['products'])));
        $item = $data['categories'][$ci]['products'][$pi];
        array_splice($data['categories'][$ci]['products'], $pi, 1);
        array_splice($data['categories'][$ci]['products'], $idx, 0, [$item]);
    }

    products_normalize($data);
    products_save($data);
    return $data;
}

function products_edit_category(string $category_id, array $body): array {
    $data = products_load();
    $categories = &$data['categories'];
    $ci = products_find_category_index($categories, $category_id);
    if ($ci === -1) {
        send_json(['detail' => 'Category not found'], 404);
    }

    if (isset($body['name'])) {
        $categories[$ci]['name'] = trim((string) $body['name']);
    }
    if (isset($body['id'])) {
        $new_id = strtolower(trim((string) $body['id']));
        foreach ($categories as $i => $c) {
            if ($i !== $ci && strcasecmp($c['id'] ?? '', $new_id) === 0) {
                send_json(['detail' => 'Category ID already exists'], 409);
            }
        }
        $categories[$ci]['id'] = $new_id;
    }
    products_save($data);
    return $data;
}

function products_delete(string $part): array {
    $data = products_load();
    $removed = false;
    foreach ($data['categories'] as &$cat) {
        $before = count($cat['products']);
        $cat['products'] = array_values(array_filter($cat['products'], fn($p) => ($p['part'] ?? '') !== $part));
        if (count($cat['products']) !== $before) {
            $removed = true;
        }
    }
    unset($cat);
    if (!$removed) {
        send_json(['detail' => 'Part not found'], 404);
    }
    $data['categories'] = array_values(array_filter($data['categories'], fn($c) => !empty($c['products'])));
    products_normalize($data);
    products_save($data);
    return $data;
}
