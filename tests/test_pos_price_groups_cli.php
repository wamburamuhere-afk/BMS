<?php
/**
 * Phase 14 (pos_upgrade_plan.md §8) — POS selling price tiers — CLI test
 *   php tests/test_pos_price_groups_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: price_groups, product_price_group_prices tables exist;
 *      customers.default_price_group_id column exists.
 *   3. Seed data: "Retail" (is_default) and "Wholesale" groups exist.
 *   4. Wiring: process_sale.php and simple_products.php are group-aware;
 *      core helper is actually used, not re-inlined.
 *   5. Runtime — resolveGroupPrices():
 *        a. price_group_id = 0 -> always empty (no group chosen).
 *        b. A real override row is resolved; a product with none is absent
 *           from the result (sparse fallback).
 *   6. Runtime — simple_products.php's own SQL shape (COALESCE(MAX(...))
 *      under GROUP BY) reconciles to the same values resolveGroupPrices()
 *      returns, for a temporary group+override created and rolled back.
 *   7. Runtime — process_sale.php's merge: a product with an override in
 *      $products_map picks up the group price; one without keeps its plain
 *      selling_price untouched.
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

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — resolveGroupPrices()');
$anyProductId = (int)($pdo->query("SELECT product_id FROM products WHERE status='active' LIMIT 1")->fetchColumn() ?: 0);
if (!$anyProductId) {
    pass('no active product to test against — skipped (n/a)');
} else {
    $r = resolveGroupPrices($pdo, 0, [$anyProductId]);
    (empty($r)) ? pass('price_group_id=0 -> always empty result') : fail('price_group_id=0 should resolve to nothing');

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
