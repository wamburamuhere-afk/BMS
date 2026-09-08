<?php
/**
 * Phase 20 (pos_upgrade_plan.md §8) — cash denomination counting — CLI test
 *   php tests/test_pos_denomination_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: cash_denomination_counts table + tzs_denominations setting exist.
 *   3. Wiring: open_shift.php/close_shift.php validate + persist an optional
 *      breakdown; zreport.php reads it back.
 *   4. Runtime — posDenominationList(): parses the configured CSV setting;
 *      falls back to a sane default when missing/empty.
 *   5. Runtime — validateDenominationBreakdown(): sum must equal the entered
 *      total; only configured denomination values are accepted; a negative
 *      count is rejected; an empty/omitted breakdown is always valid
 *      (optional — never blocks shift open/close).
 *   6. Runtime — save/get round-trip: a saved breakdown reads back exactly;
 *      resaving (retry) replaces rather than duplicates.
 *
 * All DB writes happen inside one rolled-back transaction — nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_denominations.php";
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
    'core/pos_denominations.php', 'api/pos/open_shift.php', 'api/pos/close_shift.php',
    'app/bms/pos/pos.php', 'app/bms/pos/pos_modals_new.php', 'app/bms/pos/pos_scripts_new.php',
    'app/bms/pos/zreport.php', 'migrations/tenant/2026_09_08_pos_cash_denominations.php',
] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

section('2. Schema');
$exists = $pdo->query("SHOW TABLES LIKE 'cash_denomination_counts'")->fetchColumn();
$exists ? pass('table `cash_denomination_counts` exists') : fail('table MISSING — run the migration');
$setting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tzs_denominations'")->fetchColumn();
$setting ? pass("setting 'tzs_denominations' exists ($setting)") : fail("setting 'tzs_denominations' MISSING");

section('3. Wiring');
has(src($root, 'api/pos/open_shift.php'), 'validateDenominationBreakdown(', 'open_shift.php validates the breakdown');
has(src($root, 'api/pos/open_shift.php'), 'saveDenominationBreakdown(', 'open_shift.php persists the breakdown');
has(src($root, 'api/pos/close_shift.php'), 'validateDenominationBreakdown(', 'close_shift.php validates the breakdown');
has(src($root, 'api/pos/close_shift.php'), 'saveDenominationBreakdown(', 'close_shift.php persists the breakdown');
has(src($root, 'app/bms/pos/zreport.php'), 'getDenominationBreakdown(', 'zreport.php reads the breakdown back');
has(src($root, 'app/bms/pos/pos_modals_new.php'), 'openDenomGrid', 'Start Shift modal has the denomination grid');
has(src($root, 'app/bms/pos/pos_modals_new.php'), 'closeDenomGrid', 'End Shift modal has the denomination grid');

section('4. Runtime — posDenominationList()');
$list = posDenominationList();
(!empty($list) && in_array(1000.0, $list, true))
    ? pass('posDenominationList() returns a real list including 1000')
    : fail('posDenominationList() returned an unexpected list: ' . json_encode($list));

section('5. Runtime — validateDenominationBreakdown()');
$v = validateDenominationBreakdown([], 5000.0);
($v['valid']) ? pass('empty/omitted breakdown is always valid (optional feature, never blocks)') : fail('empty breakdown incorrectly rejected');

$allowed = posDenominationList();
$a = $allowed[0]; $b = $allowed[1] ?? $allowed[0];
$breakdown = [['value' => $a, 'count' => 2], ['value' => $b, 'count' => 1]];
$expectedTotal = ($a * 2) + ($b * 1);
$v = validateDenominationBreakdown($breakdown, $expectedTotal);
($v['valid'] && abs($v['sum'] - $expectedTotal) < 0.01) ? pass('a correctly-summed breakdown validates against the matching total') : fail('correct breakdown incorrectly rejected: ' . json_encode($v));

$v = validateDenominationBreakdown($breakdown, $expectedTotal + 500);
(!$v['valid']) ? pass('a breakdown that does NOT sum to the entered total is rejected') : fail('mismatched breakdown incorrectly accepted');

$v = validateDenominationBreakdown([['value' => 33333, 'count' => 1]], 33333);
(!$v['valid']) ? pass('a value not on the configured denomination list is rejected') : fail('unconfigured denomination value incorrectly accepted');

$v = validateDenominationBreakdown([['value' => $a, 'count' => -1]], -1 * $a);
(!$v['valid']) ? pass('a negative count is rejected') : fail('negative count incorrectly accepted');

section('6. Runtime — save/get round-trip (rolled back)');
$userRow = $pdo->query("SELECT user_id FROM users WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$userRow) {
    pass('no active user fixture — skipped (n/a)');
} else {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, starting_cash, status) VALUES ('DENOMTEST', ?, 1, 0, 'active')")->execute([(int)$userRow['user_id']]);
    $shiftId = (int)$pdo->lastInsertId();

    saveDenominationBreakdown($pdo, $shiftId, 'open', $breakdown);
    $readBack = getDenominationBreakdown($pdo, $shiftId, 'open');
    (count($readBack) === 2 && abs(array_sum(array_column($readBack, 'subtotal')) - $expectedTotal) < 0.01)
        ? pass('saved breakdown reads back with the correct row count and total')
        : fail('read-back mismatch: ' . json_encode($readBack));

    // Retry (e.g. a resubmitted request) replaces, not duplicates.
    saveDenominationBreakdown($pdo, $shiftId, 'open', $breakdown);
    $readBack2 = getDenominationBreakdown($pdo, $shiftId, 'open');
    (count($readBack2) === 2)
        ? pass('resaving the same breakdown replaces rather than duplicates rows')
        : fail('resave duplicated rows instead of replacing: ' . count($readBack2) . ' rows');

    // 'close' context is independent of 'open'.
    $closeReadBack = getDenominationBreakdown($pdo, $shiftId, 'close');
    (empty($closeReadBack)) ? pass("'close' context stays empty — contexts are independent") : fail('close context unexpectedly has rows');

    $pdo->rollBack();
}
