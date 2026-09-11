<?php
/**
 * Phase 14 (pos_upgrade_plan.md §8) — POS selling price tiers — CLI test
 * Extended for Phase 25 (pos_upgrade_plan.md §9) — product professional
 * fields' promotional-pricing layer, since it's the same resolver
 * (resolveGroupPrices()) rather than a new pricing engine.
 *   php tests/test_pos_price_groups_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: price_groups, product_price_group_prices tables exist;
 *      customers.default_price_group_id column exists; Phase 25:
 *      product_promotions table, pos_sale_items.promo_original_price,
 *      products.warranty_unit/guarantee_period/guarantee_unit/barcode_symbology.
 *   3. Seed data: "Retail" (is_default) and "Wholesale" groups exist.
 *   4. Wiring: process_sale.php and simple_products.php are group-aware;
 *      core helper is actually used, not re-inlined. Phase 25: promo helper
 *      wired into the same call sites + the product-edit promotions manager.
 *   5. Runtime — resolveGroupPrices():
 *        a. price_group_id = 0 and no active promo -> empty (no override).
 *        b. A real override row is resolved; a product with none is absent
 *           from the result (sparse fallback).
 *   6. Runtime — simple_products.php's own SQL shape (COALESCE(MAX(...))
 *      under GROUP BY) reconciles to the same values resolveGroupPrices()
 *      returns, for a temporary group+override created and rolled back.
 *   7. Runtime — process_sale.php's merge: a product with an override in
 *      $products_map picks up the group price; one without keeps its plain
 *      selling_price untouched.
 *   8. Runtime — Phase 25 promo-price resolution (resolveActivePromoPrices()
 *      and resolveGroupPrices()'s promo layer): an active, in-window promo
 *      wins over a price-group override; an expired promo and a future
 *      (not-yet-started) promo both fall through to the group/base price;
 *      reconciles to direct SQL against product_promotions.
 *
 * All DB writes happen inside one rolled-back transaction — nothing persists.
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
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
$files = [
    'core/pos_price_groups.php', 'api/pos/process_sale.php', 'api/pos/simple_products.php',
    'api/pos/get_price_groups.php', 'api/pos/save_price_group.php', 'api/pos/toggle_price_group_status.php',
    'api/pos/get_price_group_products.php', 'api/pos/save_price_group_product_price.php',
    'api/pos/search_customers.php', 'app/bms/pos/pos.php', 'app/bms/pos/pos_scripts_new.php',
    'app/bms/pos/price_groups.php', 'app/bms/customer/customers.php', 'api/add_customer.php',
    'api/process_edit_customer.php', 'migrations/tenant/2026_09_08_pos_price_groups.php',
    // Phase 25 (pos_upgrade_plan.md §9)
    'api/pos/print_receipt.php', 'api/update_product.php', 'api/create_product.php',
    'api/get_product_promotions.php', 'api/save_product_promotion.php', 'api/toggle_product_promotion.php',
    'app/bms/product/product_edit.php',
    'migrations/tenant/2026_09_10_pos_product_professional_fields.php',
    'migrations/2026_09_10_pos_product_professional_fields_legacy_db.php',
];
foreach ($files as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema');
foreach (['price_groups', 'product_price_group_prices'] as $t) {
    $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    $exists ? pass("table `$t` exists") : fail("table `$t` MISSING — run the migration");
}
$col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'default_price_group_id'")->fetch();
$col ? pass('customers.default_price_group_id exists') : fail('customers.default_price_group_id MISSING');

// Phase 25 (pos_upgrade_plan.md §9)
$exists = $pdo->query("SHOW TABLES LIKE 'product_promotions'")->fetchColumn();
$exists ? pass('table `product_promotions` exists') : fail('table `product_promotions` MISSING — run the migration');
foreach (['warranty_unit', 'guarantee_period', 'guarantee_unit', 'barcode_symbology'] as $c) {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE " . $pdo->quote($c))->fetch();
    $col ? pass("products.$c exists") : fail("products.$c MISSING");
}
$col = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'promo_original_price'")->fetch();
$col ? pass('pos_sale_items.promo_original_price exists') : fail('pos_sale_items.promo_original_price MISSING');

// ─────────────────────────────────────────────────────────────────────────
section('3. Seed data');
$retail = $pdo->query("SELECT price_group_id, is_default FROM price_groups WHERE name = 'Retail'")->fetch(PDO::FETCH_ASSOC);
($retail && (int)$retail['is_default'] === 1) ? pass('Retail group exists and is_default=1') : fail('Retail group missing or not default');
$wholesale = $pdo->query("SELECT price_group_id FROM price_groups WHERE name = 'Wholesale'")->fetch(PDO::FETCH_ASSOC);
$wholesale ? pass('Wholesale group exists') : fail('Wholesale group missing');

// ─────────────────────────────────────────────────────────────────────────
section('4. Wiring');
$sale = src($root, 'api/pos/process_sale.php');
has($sale, 'resolveGroupPrices(', 'process_sale.php resolves group prices via the extracted core helper');
has($sale, '$price_group_id', 'process_sale.php reads price_group_id from the request');
$simple = src($root, 'api/pos/simple_products.php');
has($simple, 'effective_price', 'simple_products.php exposes effective_price');
has($simple, 'product_price_group_prices', 'simple_products.php joins the override table');
$scripts = src($root, 'app/bms/pos/pos_scripts_new.php');
has($scripts, 'posSelectedPriceGroupId', 'pos_scripts_new.php tracks the selected price group');
has($scripts, 'effective_price', 'pos_scripts_new.php reads effective_price when adding to cart');
has($scripts, 'default_price_group_id', "pos_scripts_new.php auto-applies a customer's default group");

// Phase 25 (pos_upgrade_plan.md §9)
has($sale, 'resolveActivePromoPrices(', 'process_sale.php resolves active promos for the "was/now" receipt line');
has($sale, 'promo_original_price', 'process_sale.php writes promo_original_price onto the sale line');
has($simple, 'product_promotions', 'simple_products.php joins the promotions table');
$receipt = src($root, 'api/pos/print_receipt.php');
has($receipt, 'promo_original_price', 'print_receipt.php renders the promo was/now strikethrough');
$productEdit = src($root, 'app/bms/product/product_edit.php');
has($productEdit, 'barcode_symbology', 'product_edit.php exposes the barcode symbology field');
has($productEdit, 'warranty_unit', 'product_edit.php exposes the warranty unit field');
has($productEdit, 'guarantee_period', 'product_edit.php exposes the guarantee fields');
has($productEdit, 'get_product_promotions.php', 'product_edit.php loads the promotions manager');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — resolveGroupPrices()');
$anyProductId = (int)($pdo->query("SELECT product_id FROM products WHERE status='active' LIMIT 1")->fetchColumn() ?: 0);
if (!$anyProductId) {
    pass('no active product to test against — skipped (n/a)');
} else {
    $r = resolveGroupPrices($pdo, 0, [$anyProductId]);
    (empty($r)) ? pass('price_group_id=0 with no active promo -> empty result') : fail('price_group_id=0 with no active promo should resolve to nothing (found: ' . json_encode($r) . ')');

    $r = resolveGroupPrices($pdo, (int)$retail['price_group_id'], []);
    (empty($r)) ? pass('empty product_ids -> empty result') : fail('empty product_ids should resolve to nothing');
}

// ─────────────────────────────────────────────────────────────────────────
section('6/7. Runtime — a real override, resolved consistently everywhere (rolled back)');
$products = $pdo->query("SELECT product_id, selling_price FROM products WHERE status='active' AND is_service=0 LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($products) < 2) {
    pass('fewer than 2 active non-service products — runtime override test skipped (n/a)');
} else {
    [$withOverride, $withoutOverride] = $products;
    $overridePrice = round(((float)$withOverride['selling_price']) * 0.8, 2); // a plausible "wholesale" discount

    $pdo->beginTransaction();

    $testGroupId = (int)$pdo->query("SELECT price_group_id FROM price_groups WHERE name='Wholesale'")->fetchColumn();
    $pdo->prepare("
        INSERT INTO product_price_group_prices (product_id, price_group_id, price)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE price = VALUES(price)
    ")->execute([$withOverride['product_id'], $testGroupId, $overridePrice]);

    // resolveGroupPrices() sees exactly the override, nothing for the other product.
    $resolved = resolveGroupPrices($pdo, $testGroupId, [$withOverride['product_id'], $withoutOverride['product_id']]);
    (isset($resolved[$withOverride['product_id']]) && abs($resolved[$withOverride['product_id']] - $overridePrice) < 0.01)
        ? pass('resolveGroupPrices() returns the override price for the overridden product')
        : fail('override not resolved correctly: ' . json_encode($resolved));
    (!isset($resolved[$withoutOverride['product_id']]))
        ? pass('resolveGroupPrices() omits the product with no override (sparse fallback)')
        : fail('non-overridden product unexpectedly present in resolved map');

    // simple_products.php's own SQL shape reconciles to the same value.
    $stmt = $pdo->prepare("
        SELECT p.product_id, COALESCE(MAX(pgp.price), p.selling_price) as effective_price
        FROM products p
        LEFT JOIN product_price_group_prices pgp ON pgp.product_id = p.product_id AND pgp.price_group_id = :gid
        WHERE p.product_id IN (:p1, :p2)
        GROUP BY p.product_id
    ");
    $stmt->execute([':gid' => $testGroupId, ':p1' => $withOverride['product_id'], ':p2' => $withoutOverride['product_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    (abs(((float)$rows[$withOverride['product_id']]) - $overridePrice) < 0.01)
        ? pass("simple_products.php's SQL shape resolves the same override price")
        : fail('simple_products.php SQL shape mismatch: ' . json_encode($rows));
    (abs(((float)$rows[$withoutOverride['product_id']]) - (float)$withoutOverride['selling_price']) < 0.01)
        ? pass("simple_products.php's SQL shape falls back to selling_price for the non-overridden product")
        : fail('non-overridden fallback mismatch');

    // process_sale.php's merge-into-$products_map pattern, replicated exactly.
    $products_map = [
        $withOverride['product_id']    => ['selling_price' => (float)$withOverride['selling_price']],
        $withoutOverride['product_id'] => ['selling_price' => (float)$withoutOverride['selling_price']],
    ];
    $groupPrices = resolveGroupPrices($pdo, $testGroupId, array_keys($products_map));
    foreach ($groupPrices as $pid => $price) { $products_map[$pid]['selling_price'] = $price; }
    (abs($products_map[$withOverride['product_id']]['selling_price'] - $overridePrice) < 0.01)
        ? pass("process_sale.php's merge pattern picks up the override")
        : fail('merge pattern did not apply the override');
    (abs($products_map[$withoutOverride['product_id']]['selling_price'] - (float)$withoutOverride['selling_price']) < 0.01)
        ? pass("process_sale.php's merge pattern leaves the non-overridden product's selling_price untouched")
        : fail('merge pattern incorrectly touched the non-overridden product');

    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('8. Runtime — Phase 25 promo-price resolution (rolled back)');
$products = $pdo->query("SELECT product_id, selling_price FROM products WHERE status='active' AND is_service=0 LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
if (count($products) < 3) {
    pass('fewer than 3 active non-service products — promo runtime test skipped (n/a)');
} else {
    [$activePromoProduct, $expiredPromoProduct, $futurePromoProduct] = $products;
    $activePrice  = round(((float)$activePromoProduct['selling_price']) * 0.5, 2);
    $expiredPrice = round(((float)$expiredPromoProduct['selling_price']) * 0.5, 2);
    $futurePrice  = round(((float)$futurePromoProduct['selling_price']) * 0.5, 2);

    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO product_promotions (product_id, price, starts_at, ends_at, status) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active')")
        ->execute([$activePromoProduct['product_id'], $activePrice]);
    $pdo->prepare("INSERT INTO product_promotions (product_id, price, starts_at, ends_at, status) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'active')")
        ->execute([$expiredPromoProduct['product_id'], $expiredPrice]);
    $pdo->prepare("INSERT INTO product_promotions (product_id, price, starts_at, ends_at, status) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 10 DAY), 'active')")
        ->execute([$futurePromoProduct['product_id'], $futurePrice]);

    $ids = [$activePromoProduct['product_id'], $expiredPromoProduct['product_id'], $futurePromoProduct['product_id']];

    $activeMap = resolveActivePromoPrices($pdo, $ids);
    (isset($activeMap[$activePromoProduct['product_id']]) && abs($activeMap[$activePromoProduct['product_id']] - $activePrice) < 0.01)
        ? pass('resolveActivePromoPrices() resolves the currently-active, in-window promo')
        : fail('active promo not resolved correctly: ' . json_encode($activeMap));
    (!isset($activeMap[$expiredPromoProduct['product_id']]))
        ? pass('resolveActivePromoPrices() omits an expired promo')
        : fail('expired promo incorrectly resolved as active');
    (!isset($activeMap[$futurePromoProduct['product_id']]))
        ? pass('resolveActivePromoPrices() omits a not-yet-started (future) promo')
        : fail('future promo incorrectly resolved as active');

    // resolveGroupPrices(): promo wins even with NO price group chosen (0).
    $noGroupMap = resolveGroupPrices($pdo, 0, $ids);
    (isset($noGroupMap[$activePromoProduct['product_id']]) && abs($noGroupMap[$activePromoProduct['product_id']] - $activePrice) < 0.01)
        ? pass('resolveGroupPrices(0, ...) still applies an active promo with no price group chosen')
        : fail('promo not applied when price_group_id=0: ' . json_encode($noGroupMap));
    (!isset($noGroupMap[$expiredPromoProduct['product_id']]) && !isset($noGroupMap[$futurePromoProduct['product_id']]))
        ? pass('resolveGroupPrices(0, ...) leaves expired/future-promo products absent (fall through to plain selling_price)')
        : fail('expired/future promo unexpectedly present: ' . json_encode($noGroupMap));

    // Promo wins over a price-group override on the SAME product.
    $wholesaleId = (int)$pdo->query("SELECT price_group_id FROM price_groups WHERE name='Wholesale'")->fetchColumn();
    $groupOverridePrice = round(((float)$activePromoProduct['selling_price']) * 0.9, 2); // less of a discount than the promo
    $pdo->prepare("INSERT INTO product_price_group_prices (product_id, price_group_id, price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)")
        ->execute([$activePromoProduct['product_id'], $wholesaleId, $groupOverridePrice]);
    $withGroupMap = resolveGroupPrices($pdo, $wholesaleId, [$activePromoProduct['product_id']]);
    (isset($withGroupMap[$activePromoProduct['product_id']]) && abs($withGroupMap[$activePromoProduct['product_id']] - $activePrice) < 0.01)
        ? pass('resolveGroupPrices() lets an active promo win over a price-group override on the same product')
        : fail('promo did not win over group override: ' . json_encode($withGroupMap));

    // Reconcile against direct SQL on product_promotions.
    $directActive = (float)$pdo->query("
        SELECT MIN(price) FROM product_promotions
        WHERE product_id = {$activePromoProduct['product_id']} AND status = 'active'
          AND starts_at <= NOW() AND ends_at >= NOW()
    ")->fetchColumn();
    (abs($directActive - $activePrice) < 0.01)
        ? pass('active promo price reconciles to direct SQL against product_promotions')
        : fail("direct SQL mismatch: expected $activePrice, got $directActive");

    $pdo->rollBack();
}
