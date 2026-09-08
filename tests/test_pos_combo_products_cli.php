<?php
/**
 * Phase 23 (pos_upgrade_plan.md §8) — combo/bundle products — CLI test
 *   php tests/test_pos_combo_products_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: products.is_combo exists, defaults to 0 (every existing
 *      product is completely unaffected).
 *   3. Wiring: process_sale.php pre-checks + consumes combo components
 *      instead of the combo's own stock; void_sale.php/create_return.php
 *      reverse into components; product_edit.php has the toggle + grid.
 *   4. Runtime — getComboComponents()/checkComboAvailability(): resolves a
 *      real combo's components; correctly detects a shortfall.
 *   5. Runtime — consumeComboComponents()/reverseComboComponents(): selling
 *      1 combo decrements every component by exactly qty_per_unit; a full
 *      reversal restores every component exactly; a partial reversal caps
 *      at the requested quantity.
 *   6. Runtime — an insufficient-stock component is detected BEFORE any
 *      write happens (the pre-check, matching "blocked before the sale
 *      posts, not a partial failure").
 *
 * All DB writes happen inside one rolled-back transaction — nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_combo_products.php";
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

section('1. Files lint clean');
foreach ([
    'core/pos_combo_products.php', 'api/pos/process_sale.php', 'api/pos/void_sale.php',
    'api/pos/create_return.php', 'api/update_product.php', 'api/get_combo_components.php',
    'api/save_combo_component.php', 'api/delete_combo_component.php',
    'app/bms/product/product_edit.php', 'migrations/tenant/2026_09_08_pos_combo_products.php',
] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

section('2. Schema');
$col = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_combo'")->fetch(PDO::FETCH_ASSOC);
$col ? pass('products.is_combo exists') : fail('column MISSING — run the migration');
($col && (string)$col['Default'] === '0') ? pass('default is 0 — every existing product is unaffected') : fail('default is not 0: ' . json_encode($col));

section('3. Wiring');
$sale = src($root, 'api/pos/process_sale.php');
has($sale, 'checkComboAvailability(', 'process_sale.php pre-checks combo availability before any write');
has($sale, 'consumeComboComponents(', 'process_sale.php consumes combo components');
has($sale, "!empty(\$db_product['is_combo'])", 'process_sale.php branches on is_combo before the normal stock path');
has(src($root, 'api/pos/void_sale.php'), 'reverseComboComponents(', 'void_sale.php reverses combo components');
has(src($root, 'api/pos/create_return.php'), 'reverseComboComponents(', 'create_return.php reverses combo components (partial-capable)');
has(src($root, 'api/update_product.php'), "'is_combo'", 'update_product.php persists is_combo');
has(src($root, 'app/bms/product/product_edit.php'), 'is_combo_toggle', 'product_edit.php has the combo toggle + components grid');

section('4/5/6. Runtime — combo resolution, consumption, reversal, and the pre-check (rolled back)');
$products = $pdo->query("SELECT product_id FROM products WHERE status='active' AND is_service=0 LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
$whRow = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (count($products) < 3 || !$whRow) {
    pass('fewer than 3 active non-service products or no warehouse — skipped (n/a)');
} else {
    [$comboP, $compA, $compB] = array_map(fn($p) => (int)$p['product_id'], $products);
    $wid = (int)$whRow['warehouse_id'];

    $pdo->beginTransaction();

    // Make the combo product genuinely a combo, with 2 components.
    $pdo->prepare("UPDATE products SET is_combo = 1 WHERE product_id = ?")->execute([$comboP]);
    $pdo->exec("DELETE FROM product_assembly_components WHERE parent_product_id = $comboP");
    $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, component_name, unit, qty_per_unit, total_qty) VALUES (?, ?, 'A', 'pcs', 2, 2)")->execute([$comboP, $compA]);
    $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, component_name, unit, qty_per_unit, total_qty) VALUES (?, ?, 'B', 'pcs', 3, 3)")->execute([$comboP, $compB]);

    // Ensure both components have plenty of stock in this warehouse.
    foreach ([$compA, $compB] as $cp) {
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ? AND warehouse_id = ?")->execute([$cp, $wid]);
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 100, 0)")->execute([$cp, $wid]);
    }

    $components = getComboComponents($pdo, $comboP);
    (count($components) === 2) ? pass('getComboComponents() resolves both components of the combo') : fail('expected 2 components, got ' . count($components));

    $shortfalls = checkComboAvailability($pdo, $comboP, 5, $wid);
    (empty($shortfalls)) ? pass('checkComboAvailability() finds no shortfall when stock is plentiful (5 combos = 10 A + 15 B, both available)') : fail('unexpected shortfall: ' . json_encode($shortfalls));

    // Sell 4 combos: should decrement A by 8, B by 12.
    $before = [
        $compA => (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compA AND warehouse_id=$wid")->fetchColumn(),
        $compB => (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compB AND warehouse_id=$wid")->fetchColumn(),
    ];
    $anyUser = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 LIMIT 1")->fetchColumn() ?: 1);
    consumeComboComponents($pdo, $comboP, 4.0, $wid, null, 999888, 'COMBO-TEST-1', $anyUser);
    $afterA = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compA AND warehouse_id=$wid")->fetchColumn();
    $afterB = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compB AND warehouse_id=$wid")->fetchColumn();
    (abs(($before[$compA] - $afterA) - 8.0) < 0.001) ? pass('selling 4 combos decremented component A by exactly 8 (4 × qty_per_unit 2)') : fail("A decrement wrong: {$before[$compA]} -> $afterA");
    (abs(($before[$compB] - $afterB) - 12.0) < 0.001) ? pass('selling 4 combos decremented component B by exactly 12 (4 × qty_per_unit 3)') : fail("B decrement wrong: {$before[$compB]} -> $afterB");

    $movements = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reference_id=999888 AND reference_number='COMBO-TEST-1' AND movement_type='sale_out'")->fetchColumn();
    ($movements === 2) ? pass('exactly 2 sale_out stock_movements rows recorded (one per component)') : fail("expected 2 movement rows, got $movements");

    // Full reversal (void): restores both components exactly.
    reverseComboComponents($pdo, $comboP, 4.0, $wid, null, 999888, 'COMBO-TEST-1', $anyUser, 'return');
    $restoredA = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compA AND warehouse_id=$wid")->fetchColumn();
    $restoredB = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compB AND warehouse_id=$wid")->fetchColumn();
    (abs($restoredA - $before[$compA]) < 0.001 && abs($restoredB - $before[$compB]) < 0.001)
        ? pass('full reversal restored both components exactly to their pre-sale levels')
        : fail("full reversal mismatch: A $restoredA (want {$before[$compA]}), B $restoredB (want {$before[$compB]})");

    // Partial reversal: consume 4 again, then reverse only 1 combo's worth (A: 2, B: 3).
    consumeComboComponents($pdo, $comboP, 4.0, $wid, null, 999889, 'COMBO-TEST-2', $anyUser);
    reverseComboComponents($pdo, $comboP, 1.0, $wid, null, 999889, 'COMBO-TEST-2', $anyUser, 'return');
    $partialA = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compA AND warehouse_id=$wid")->fetchColumn();
    $partialB = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compB AND warehouse_id=$wid")->fetchColumn();
    // Net effect of (consume 4, restore 1) = consume 3 combos' worth: A -6, B -9 from baseline.
    (abs(($before[$compA] - $partialA) - 6.0) < 0.001 && abs(($before[$compB] - $partialB) - 9.0) < 0.001)
        ? pass('partial reversal (1 of 4) restored exactly that share — net effect matches 3 combos still consumed')
        : fail("partial reversal mismatch: A off by " . ($before[$compA] - $partialA - 6.0) . ", B off by " . ($before[$compB] - $partialB - 9.0));

    // Insufficient stock: drop component B's stock below what 100 combos would need.
    $pdo->prepare("UPDATE product_stocks SET stock_quantity = 5 WHERE product_id = ? AND warehouse_id = ?")->execute([$compB, $wid]);
    $shortfalls = checkComboAvailability($pdo, $comboP, 100.0, $wid);
    (!empty($shortfalls) && $shortfalls[0]['component_product_id'] === $compB || (isset($shortfalls[1]) && $shortfalls[1]['component_product_id'] === $compB))
        ? pass('checkComboAvailability() correctly flags component B as short when 100 combos need 300 but only 5 remain')
        : fail('shortfall not detected: ' . json_encode($shortfalls));

    // Confirm NOTHING was written by the pre-check itself (it's read-only).
    $stockAfterCheck = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$compB AND warehouse_id=$wid")->fetchColumn();
    (abs($stockAfterCheck - 5.0) < 0.001)
        ? pass('the availability pre-check is read-only — component B stock is still exactly 5, untouched')
        : fail('pre-check unexpectedly wrote to stock');

    $pdo->rollBack();
}
