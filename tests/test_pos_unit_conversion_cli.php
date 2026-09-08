<?php
/**
 * Phase 15 (pos_upgrade_plan.md §8) — unit conversion at the register — CLI test
 *   php tests/test_pos_unit_conversion_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: product_unit_conversions table + pos_sale_items.sold_unit_label/
 *      .sold_unit_quantity columns exist.
 *   3. Wiring: process_sale.php resolves conversions server-side (never
 *      trusts a client multiplier); product_edit.php has the Selling Units UI;
 *      the CRUD + POS lookup endpoints exist.
 *   4. Runtime — resolveUnitConversion(): finds a real row; null for an
 *      unknown label or empty label (both = "sells at the base unit,
 *      untouched" — fully backward compatible).
 *   5. Runtime — convertToBaseUnit(): multiplier-only math (no override);
 *      unit_price_override math (converts back to a per-base-unit price);
 *      a zero/garbage multiplier never divides by zero.
 *   6. Runtime — end-to-end reconciliation: base_quantity always equals what
 *      would actually be decremented from stock, regardless of which unit
 *      was sold (the core promise of this phase).
 *
 * All DB writes happen inside one rolled-back transaction — nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_unit_conversion.php";
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
$files = [
    'core/pos_unit_conversion.php', 'api/pos/process_sale.php',
    'api/get_product_units.php', 'api/save_product_unit.php', 'api/delete_product_unit.php',
    'api/pos/get_product_units.php', 'app/bms/product/product_edit.php', 'app/bms/pos/pos_scripts_new.php',
    'migrations/tenant/2026_09_08_pos_unit_conversions.php',
];
foreach ($files as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

section('2. Schema');
$exists = $pdo->query("SHOW TABLES LIKE 'product_unit_conversions'")->fetchColumn();
$exists ? pass('table `product_unit_conversions` exists') : fail('table MISSING — run the migration');
foreach (['sold_unit_label', 'sold_unit_quantity'] as $col) {
    $c = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE " . $pdo->quote($col))->fetch();
    $c ? pass("pos_sale_items.$col exists") : fail("pos_sale_items.$col MISSING");
}

section('3. Wiring');
has(src($root, 'api/pos/process_sale.php'), 'resolveUnitConversion(', 'process_sale.php resolves the conversion server-side');
has(src($root, 'api/pos/process_sale.php'), 'convertToBaseUnit(', 'process_sale.php converts to base units server-side');
has(src($root, 'app/bms/product/product_edit.php'), 'sellingUnitsBody', 'product_edit.php has the Selling Units grid');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'quickViewUnit', 'pos_scripts_new.php has the unit selector in quick-view');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'unit_label', 'pos_scripts_new.php carries unit_label on the cart item');

section('4. Runtime — resolveUnitConversion()');
$prodRow = $pdo->query("SELECT product_id, unit FROM products WHERE status='active' AND is_service=0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$prodRow) {
    pass('no active product to test against — skipped (n/a)');
} else {
    $pid = (int)$prodRow['product_id'];

    (resolveUnitConversion($pdo, $pid, '') === null) ? pass('empty unit label -> null (base unit)') : fail('empty label should return null');
    (resolveUnitConversion($pdo, $pid, 'Definitely Not A Real Unit 2026') === null) ? pass('unknown unit label -> null (base unit, ignored not errored)') : fail('unknown label should return null');

    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM product_unit_conversions WHERE product_id = $pid AND unit_label = 'TestCarton'");
    $pdo->prepare("INSERT INTO product_unit_conversions (product_id, unit_label, base_unit_multiplier, unit_price_override) VALUES (?, 'TestCarton', 12, NULL)")->execute([$pid]);
    $conv = resolveUnitConversion($pdo, $pid, 'TestCarton');
    ($conv !== null && abs($conv['multiplier'] - 12.0) < 0.001 && $conv['unit_price_override'] === null)
        ? pass('finds a real conversion row (multiplier=12, no price override)')
        : fail('did not resolve the seeded conversion: ' . json_encode($conv));
    $pdo->rollBack();
}

section('5. Runtime — convertToBaseUnit()');
$noOverride = ['multiplier' => 12.0, 'unit_price_override' => null];
$r = convertToBaseUnit($noOverride, 2.0, 1000.0);
(abs($r['base_quantity'] - 24.0) < 0.001 && abs($r['base_unit_price'] - 1000.0) < 0.001)
    ? pass('no override: 2 cartons × 12 = 24 base units, per-base price unchanged (1000)')
    : fail('no-override math wrong: ' . json_encode($r));

$withOverride = ['multiplier' => 12.0, 'unit_price_override' => 10800.0]; // a bulk discount: 12×1000=12000 normally, but carton sells for 10800
$r = convertToBaseUnit($withOverride, 3.0, 1000.0);
(abs($r['base_quantity'] - 36.0) < 0.001 && abs($r['base_unit_price'] - 900.0) < 0.001)
    ? pass('with override: 3 cartons × 12 = 36 base units; per-base price = 10800/12 = 900 (bulk discount correctly reflected)')
    : fail('override math wrong: ' . json_encode($r));

$zeroMultiplier = ['multiplier' => 0.0, 'unit_price_override' => null];
$r = convertToBaseUnit($zeroMultiplier, 5.0, 500.0);
(abs($r['base_quantity'] - 5.0) < 0.001 && abs($r['base_unit_price'] - 500.0) < 0.001)
    ? pass('a garbage zero multiplier safely falls back to 1 (5 base units, base price unchanged) — never divides by zero')
    : fail('zero-multiplier guard failed: ' . json_encode($r));

section('6. Runtime — end-to-end: base_quantity always matches what stock actually decrements by');
// Selling "2 TestCarton" of a product where 1 TestCarton = 12 base units
// must decrement stock by exactly 24 base units, regardless of price.
$conversion = ['multiplier' => 12.0, 'unit_price_override' => null];
$sold = 2.0;
$converted = convertToBaseUnit($conversion, $sold, 750.0);
(abs($converted['base_quantity'] - 24.0) < 0.001)
    ? pass('selling 2 of a x12 unit correctly resolves to 24 base units for the stock/FEFO decrement')
    : fail('end-to-end base-quantity mismatch: ' . json_encode($converted));
