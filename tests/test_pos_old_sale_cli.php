<?php
/**
 * POS "Record Old Sale" (Rekodi Mauzo ya Zamani) — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_old_sale_cli.php
 *
 * Phase 1 — foundation (core/pos_old_sales.php, migrations, permission):
 *   1. Files lint-clean; both migrations exist and are wired the same.
 *   2. Columns + permission row present on the local DB.
 *   3. Time rules: future refused, older than the limit refused, inside allowed.
 *   4. Shift rules (rolled-back transaction):
 *      a) a closed shift of the user covering the time is found ('closed')
 *      b) the still-open shift covering the time is found ('active')
 *      c) no shift → preview 'new'; resolve creates ONE closed back-dated shift
 *      d) a second time the same day reuses and widens it ('backdated')
 *      e) another user's shift is never used
 *   5. Recompute of a closed shift: expected cash, difference, totals.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/pos_old_sales.php";
require_once "$root/core/pos_shift_reporting.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']    = 4;
$_SESSION['username']   = 'admin';
$_SESSION['role']       = 'admin';
$_SESSION['is_admin']   = true;
$_SESSION['csrf_token'] = 'test-csrf';

$failures = 0;
$passes   = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

global $pdo;
$TAG = 'TESTOLD-' . substr(md5((string)microtime(true)), 0, 6);

function mkShift(PDO $pdo, int $user, string $status, string $start, ?string $end, string $tag, int $past = 0, float $startCash = 0, float $endCash = 0): int {
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, status, start_time, end_time, starting_cash, ending_cash, is_past_entry)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$tag . '-' . mt_rand(1000, 9999), $user, $status, $start, $end, $startCash, $endCash, $past]);
    return (int)$pdo->lastInsertId();
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files');
// ─────────────────────────────────────────────────────────────────────────
$files = ["$root/core/pos_old_sales.php",
          "$root/migrations/tenant/2026_10_27_pos_record_old_sale.php",
          "$root/migrations/2026_10_27_pos_record_old_sale_legacy_db.php"];
foreach ($files as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    ok($rc === 0, basename($f) . ' lint-clean');
}
$t = file_get_contents($files[1]); $l = file_get_contents($files[2]);
ok(strpos($t, "'pos_past_sales'") !== false && strpos($l, "'pos_past_sales'") !== false, 'both migrations add the pos_past_sales permission');
ok(strpos($t, 'cash_register_shifts ADD COLUMN is_past_entry') !== false && strpos($l, 'cash_register_shifts ADD COLUMN is_past_entry') !== false, 'both migrations add the shift flag');
ok(strpos(file_get_contents("$root/core/feature_registry.php"), "'pos_past_sales'") !== false, 'permission belongs to the POS feature (feature_registry)');

// ─────────────────────────────────────────────────────────────────────────
section('2. Local DB has the foundation');
// ─────────────────────────────────────────────────────────────────────────
ok((bool)$pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'is_past_entry'")->fetch(), 'pos_sales.is_past_entry exists');
ok((bool)$pdo->query("SHOW COLUMNS FROM cash_register_shifts LIKE 'is_past_entry'")->fetch(), 'cash_register_shifts.is_past_entry exists');
ok((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key = 'pos_past_sales'")->fetchColumn() === 1, 'exactly one pos_past_sales permission row');

// ─────────────────────────────────────────────────────────────────────────
section('3. Time rules');
// ─────────────────────────────────────────────────────────────────────────
$now = strtotime('2026-10-27 11:20:00');
ok(posOldSaleMaxDays() === 30, 'default limit is 30 days');
ok(posOldSaleTimeError('2026-10-24 14:30', $now) === null, '3 days ago is allowed');
ok(posOldSaleTimeError('2026-09-27 08:00', $now) === null, 'exactly 30 days ago is allowed');
ok(posOldSaleTimeError('2026-09-26 23:59', $now) !== null, '31 days ago is refused');
ok(posOldSaleTimeError('2026-10-27 12:00', $now) !== null, 'later today is refused (future)');
ok(posOldSaleTimeError('2026-10-28 09:00', $now) !== null, 'tomorrow is refused');
ok(posOldSaleTimeError('24/10/2026', $now) !== null, 'a malformed date is refused');

// ─────────────────────────────────────────────────────────────────────────
section('4. Shift rules (rolled back)');
// ─────────────────────────────────────────────────────────────────────────
$U = 900001; $OTHER = 900002;
$pdo->beginTransaction();
try {
    $closed = mkShift($pdo, $U, 'closed', '2026-10-24 08:00:00', '2026-10-24 19:00:00', $TAG);
    $active = mkShift($pdo, $U, 'active', '2026-10-26 12:38:00', null, $TAG);
    mkShift($pdo, $OTHER, 'closed', '2026-10-22 08:00:00', '2026-10-22 19:00:00', $TAG);

    $p = posOldSaleShiftPreview($pdo, $U, '2026-10-24 14:30:00');
    ok($p['kind'] === 'closed' && $p['shift_id'] === $closed, "a) 24/10 14:30 → the user's closed shift");
    $p = posOldSaleShiftPreview($pdo, $U, '2026-10-26 15:00:00');
    ok($p['kind'] === 'active' && $p['shift_id'] === $active, 'b) 26/10 15:00 → the shift still open since 12:38');
    $p = posOldSaleShiftPreview($pdo, $U, '2026-10-24 07:00:00');
    ok($p['kind'] === 'new', 'c) 24/10 07:00 (before that shift opened) → a new back-dated shift');
    $p = posOldSaleShiftPreview($pdo, $U, '2026-10-22 10:15:00');
    ok($p['kind'] === 'new', "e) 22/10 → another user's shift is not used");

    $r1 = posOldSaleResolveShift($pdo, $U, '2026-10-22 10:15:00', 5, 1);
    ok(!empty($r1['created']) && $r1['status'] === 'closed' && (int)$r1['is_past_entry'] === 1, 'c) resolve creates a closed, back-dated shift');
    ok((int)$r1['warehouse_id'] === 5 && $r1['start_time'] === '2026-10-22 10:15:00', 'c) it carries the sale’s shop and starts at the sale time');
    $r2 = posOldSaleResolveShift($pdo, $U, '2026-10-22 16:40:00', 5, 1);
    ok(empty($r2['created']) && (int)$r2['shift_id'] === (int)$r1['shift_id'], 'd) a later sale the same day reuses that shift');
    $w = $pdo->prepare("SELECT start_time, end_time FROM cash_register_shifts WHERE shift_id = ?");
    $w->execute([(int)$r1['shift_id']]); $w = $w->fetch(PDO::FETCH_ASSOC);
    ok($w['start_time'] === '2026-10-22 10:15:00' && $w['end_time'] === '2026-10-22 16:40:00', 'd) its open/close times widen to cover both sales');
    $r3 = posOldSaleResolveShift($pdo, $U, '2026-10-22 08:05:00', 5, 1);
    ok((int)$r3['shift_id'] === (int)$r1['shift_id'], 'd) an earlier time that day still reuses it (widens the start)');
    ok(posOldSaleShiftPreview($pdo, $U, '2026-10-22 12:00:00')['kind'] === 'backdated', 'd) preview now names it as the back-dated shift');
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM cash_register_shifts WHERE user_id = ? AND is_past_entry = 1");
    $cnt->execute([$U]);
    ok((int)$cnt->fetchColumn() === 1, 'd) still only one back-dated shift for that day');

    // ─────────────────────────────────────────────────────────────────────
    section('5. Recompute a closed shift');
    // ─────────────────────────────────────────────────────────────────────
    // Counted 60,300 at close; only 32,000 of cash sales were recorded (opening 20,000).
    $counted = mkShift($pdo, $U, 'closed', '2026-10-23 08:00:00', '2026-10-23 19:00:00', $TAG, 0, 20000, 64300);
    $pdo->prepare("INSERT INTO cash_register_transactions (shift_id, transaction_type, amount, payment_method, created_by) VALUES (?, 'sale', 32000, 'cash', 4)")->execute([$counted]);
    posOldSaleRecomputeShift($pdo, $counted);
    $row = $pdo->prepare("SELECT expected_cash, cash_difference, ending_cash FROM cash_register_shifts WHERE shift_id = ?");
    $row->execute([$counted]); $a = $row->fetch(PDO::FETCH_ASSOC);
    ok((float)$a['expected_cash'] == 52000.0 && (float)$a['cash_difference'] == 12300.0, 'before: expected 52,000, overage 12,300');
    $pdo->prepare("INSERT INTO cash_register_transactions (shift_id, transaction_type, amount, payment_method, created_by) VALUES (?, 'sale', 12300, 'cash', 4)")->execute([$counted]);
    posOldSaleRecomputeShift($pdo, $counted);
    $row->execute([$counted]); $b = $row->fetch(PDO::FETCH_ASSOC);
    ok((float)$b['expected_cash'] == 64300.0 && (float)$b['cash_difference'] == 0.0, 'after the old sale: expected 64,300, difference 0');
    ok((float)$b['ending_cash'] == 64300.0, 'the counted cash is kept as it was');

    $pdo->prepare("INSERT INTO cash_register_transactions (shift_id, transaction_type, amount, payment_method, created_by) VALUES (?, 'sale', 8000, 'cash', 4)")->execute([(int)$r1['shift_id']]);
    posOldSaleRecomputeShift($pdo, (int)$r1['shift_id']);
    $row->execute([(int)$r1['shift_id']]); $c = $row->fetch(PDO::FETCH_ASSOC);
    ok((float)$c['expected_cash'] == 8000.0 && (float)$c['ending_cash'] == 8000.0 && (float)$c['cash_difference'] == 0.0, 'a back-dated shift (no count) follows expected cash, difference 0');

    $before = $pdo->prepare("SELECT updated_at, expected_cash FROM cash_register_shifts WHERE shift_id = ?");
    $before->execute([$active]); $x = $before->fetch(PDO::FETCH_ASSOC);
    posOldSaleRecomputeShift($pdo, $active);
    $before->execute([$active]); $y = $before->fetch(PDO::FETCH_ASSOC);
    ok($x == $y, 'an active shift is left alone (its totals are live)');
} finally {
    $pdo->rollBack();
}
$left = $pdo->prepare("SELECT COUNT(*) FROM cash_register_shifts WHERE user_id IN (?, ?)");
$left->execute([$U, $OTHER]);
ok((int)$left->fetchColumn() === 0, 'rollback left no test shifts behind');
