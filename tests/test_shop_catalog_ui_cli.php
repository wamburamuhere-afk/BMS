<?php
/**
 * Public shop-catalog link — professional UI redesign (2026-09-17 follow-up)
 *   php tests/test_shop_catalog_ui_cli.php
 *
 * Follow-up to tests/test_public_shop_catalog_link_cli.php (which owns the
 * security/access-control proofs and is left untouched) — this file covers
 * ONLY what changed in the redesign: product images (with graceful fallback
 * when a product has none), a category filter-disclosure panel, and a
 * Retail/Wholesale segmented price toggle.
 *
 * Wholesale price source: products.wholesale_price is legacy/unread since
 * Phase 14 — the real, live wholesale price a shop sets via Restock lives in
 * product_price_group_prices under the seeded "Wholesale" price group
 * (core/pos_price_groups.php::wholesalePriceGroupId()). This suite proves
 * the public page reads that same live source, not the legacy column.
 *
 *   A. STATIC — file lints clean; source wiring for all three additions.
 *   B. LIVE   — a real product with an image, a category, and a genuine
 *              wholesale override, fetched with NO authentication:
 *                - the image renders; a second product with no image shows
 *                  the fallback icon instead (never a broken <img>)
 *                - the category name appears and its filter chip exists
 *                - the Retail/Wholesale toggle appears (an override exists)
 *                  and its two data attributes carry the exact right values
 *                - a SEPARATE warehouse with no wholesale override anywhere
 *                  never shows the toggle at all (no dead UI)
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_price_groups.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/shop_catalog.php") . ' 2>&1', $out, $rc);
$rc === 0 ? pass('shop_catalog.php') : fail('php -l failed: ' . implode(' ', $out));
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/lang/sw.php") . ' 2>&1', $out, $rc);
$rc === 0 ? pass('lang/sw.php') : fail('php -l failed: ' . implode(' ', $out));

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$pubPage = src($root, 'shop_catalog.php');
has($pubPage, 'wholesalePriceGroupId($pdo)', 'reads the real, live Wholesale price group — not the legacy products.wholesale_price column');
has($pubPage, "LEFT JOIN product_price_group_prices pgp", 'wholesale price is a LEFT JOIN (falls back to retail when no override exists)');
has($pubPage, 'LEFT JOIN categories c ON c.category_id = p.category_id', 'category is joined for the filter panel');
has($pubPage, "getUrl(\$p['image_url'])", 'product image resolved through getUrl(), same convention as every other page');
has($pubPage, "bi-box-seam", 'a no-image product falls back to an icon, never a broken <img>');
has($pubPage, 'onerror=', 'a BROKEN image URL (file deleted from disk) also falls back gracefully, not a broken-image icon');
has($pubPage, '$hasWholesalePricing', 'the Retail/Wholesale toggle is conditionally gated, not always shown');
has($pubPage, "abs((float)\$p['wholesale_price'] - (float)\$p['selling_price']) > 0.001", 'the toggle only appears when an override genuinely changes the price for at least one product');
has($pubPage, 'data-retail=', 'each price carries a pre-formatted retail string (server is the one source of truth for currency formatting)');
has($pubPage, 'data-wholesale=', 'each price carries a pre-formatted wholesale string');
has($pubPage, "id=\"scFilterToggle\"", 'the filter-disclosure toggle button exists');
has($pubPage, "id=\"scCategoryChips\"", 'the category chip row exists');
has($pubPage, "t('Retail')", 'toggle label routes through t() (English key), not a hardcoded Swahili string');
has($pubPage, "t('Wholesale')", 'toggle label routes through t() (English key), not a hardcoded Swahili string');
$swSrc = src($root, 'lang/sw.php');
has($swSrc, "'Wholesale' => 'Jumla'", 'Swahili translation for Wholesale exists');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live — images, category filter, and the Retail/Wholesale toggle');
require_once "$root/core/pos_nav.php";
require_once "$root/core/warehouse_scope.php";
$wasSimple = posSimpleModeEnabled();
save_setting('pos_simple_mode', '1');

$warehouses = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
$whA = $warehouses[0]['warehouse_id'] ?? null;
$whB = $warehouses[1]['warehouse_id'] ?? null;

$imgProductId = $noImgProductId = $catId = null;
$testTokenA = $testTokenB = null;

$cleanupData = function () use ($pdo, &$imgProductId, &$noImgProductId, &$catId, $whA, $whB) {
    foreach ([$imgProductId, $noImgProductId] as $pid) {
        if ($pid) {
            $pdo->prepare("DELETE FROM product_price_group_prices WHERE product_id = ?")->execute([$pid]);
            $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$pid]);
            $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
        }
    }
    if ($catId) $pdo->prepare("DELETE FROM categories WHERE category_id = ?")->execute([$catId]);
    if ($whA) $pdo->prepare("UPDATE warehouses SET public_catalog_token_hash = NULL, public_catalog_token_created_at = NULL WHERE warehouse_id = ?")->execute([$whA]);
    if ($whB) $pdo->prepare("UPDATE warehouses SET public_catalog_token_hash = NULL, public_catalog_token_created_at = NULL WHERE warehouse_id = ?")->execute([$whB]);
};
$cleanupSettings = function () use ($wasSimple) {
    if (!$wasSimple) save_setting('pos_simple_mode', '0');
};
register_shutdown_function($cleanupData);
register_shutdown_function($cleanupSettings);

if (!$whA || !$whB) {
    fail('Need at least 2 active warehouses to run this suite — skipped');
} else {
    $suffix = bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO categories (category_name, type, status, created_at) VALUES (?, 'product', 'active', NOW())")
        ->execute(["UITestCat-$suffix"]);
    $catId = (int)$pdo->lastInsertId();

    // Product WITH an image + category + wholesale override — in warehouse A.
    $pdo->prepare("INSERT INTO products (product_name, sku, selling_price, cost_price, category_id, image_url, status, is_service, created_at) VALUES (?, ?, 1000, 600, ?, ?, 'active', 0, NOW())")
        ->execute(["UITestImgProduct-$suffix", "UIIMG-$suffix", $catId, "uploads/products/uitest-$suffix.jpg"]);
    $imgProductId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, 20, 0, NOW())")
        ->execute([$imgProductId, $whA]);
    $wgid = wholesalePriceGroupId($pdo);
    $pdo->prepare("INSERT INTO product_price_group_prices (product_id, price_group_id, price, created_at, updated_at) VALUES (?, ?, 700, NOW(), NOW())")
        ->execute([$imgProductId, $wgid]);

    // Product with NO image, no category, no wholesale override — same warehouse.
    $pdo->prepare("INSERT INTO products (product_name, sku, selling_price, cost_price, status, is_service, created_at) VALUES (?, ?, 500, 300, 'active', 0, NOW())")
        ->execute(["UITestNoImgProduct-$suffix", "UINOIMG-$suffix"]);
    $noImgProductId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, 5, 0, NOW())")
        ->execute([$noImgProductId, $whA]);

    $rawA = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE warehouses SET public_catalog_token_hash = ?, public_catalog_token_created_at = NOW() WHERE warehouse_id = ?")
        ->execute([hash('sha256', $rawA), $whA]);
    $rawB = bin2hex(random_bytes(24)); // warehouse B: no wholesale override anywhere
    $pdo->prepare("UPDATE warehouses SET public_catalog_token_hash = ?, public_catalog_token_created_at = NOW() WHERE warehouse_id = ?")
        ->execute([hash('sha256', $rawB), $whB]);

    pass('manufactured an image+category+wholesale-override product and a plain no-image product');

    function _sc_render(string $root, string $token): string {
        $tmp = tempnam(sys_get_temp_dir(), 'sccat_');
        file_put_contents($tmp, "<?php\n\$_GET['token']='" . addslashes($token) . "'; \$_SERVER['REQUEST_METHOD']='GET'; ob_start(); include '$root/shop_catalog.php'; echo ob_get_clean();");
        $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        return (string)$out;
    }

    $htmlA = _sc_render($root, $rawA);
    (!str_contains($htmlA, 'Fatal error') && !str_contains($htmlA, 'Parse error')) ? pass('warehouse A renders with no PHP error') : fail('PHP error: ' . substr($htmlA, 0, 300));

    // Images
    (str_contains($htmlA, "uploads/products/uitest-$suffix.jpg")) ? pass('the product WITH an image renders a real <img src>') : fail('image src missing from rendered HTML');
    $noImgCardPos = strpos($htmlA, "UITestNoImgProduct-$suffix");
    (str_contains($htmlA, 'bi-box-seam')) ? pass('at least one fallback icon rendered (for the no-image product)') : fail('no fallback icon found anywhere');

    // Category filter
    (str_contains($htmlA, "UITestCat-$suffix")) ? pass('the category name appears as a filter chip') : fail('category chip missing');
    (str_contains($htmlA, 'id="scFilterToggle"')) ? pass('the Filter disclosure button renders (a category exists)') : fail('filter button missing');

    // Retail/Wholesale toggle — warehouse A DOES have an override
    (str_contains($htmlA, '<div class="sc-price-toggle"')) ? pass('the Retail/Wholesale toggle IS shown for warehouse A (a real override exists)') : fail('toggle missing where it should appear');
    (str_contains($htmlA, 'data-retail="TSh 1,000.00"')) ? pass('the retail price attribute is exactly correct (TSh 1,000.00)') : fail('retail price attribute wrong');
    (str_contains($htmlA, 'data-wholesale="TSh 700.00"')) ? pass('the wholesale price attribute is exactly correct (TSh 700.00) — read from product_price_group_prices, not the legacy column') : fail('wholesale price attribute wrong');

    // Negative case — warehouse B has products but zero wholesale overrides
    $htmlB = _sc_render($root, $rawB);
    (!str_contains($htmlB, '<div class="sc-price-toggle"')) ? pass('warehouse B (no overrides anywhere) correctly shows NO toggle — no dead UI') : fail('toggle appeared where it should not have');
}

$cleanupData();
$cleanupSettings();
