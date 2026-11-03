<?php
/**
 * POS void → cash drawer — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_void_drawer_cash_cli.php
 *
 * Bug (live, 2026-10-26): voiding a CREDIT sale that took a deposit left the
 * deposit on the POS header's "Cash Balance" (1,000) while the Z-report, which
 * reads the sales, said 0. void_sale.php only took cash back for sales whose
 * payment_method was 'cash'.
 *
 * Verifies:
 *   1. Files lint-clean; void_sale.php reads the drawer through posSaleDrawerCashOwed().
 *   2. Real api/pos/void_sale.php runs (in-process) against the local DB:
 *      a) credit sale + 1,000 deposit   → 1,000 refunded, nothing owed        (the bug)
 *      b) cash sale 6,000               → 6,000 refunded                       (regression)
 *      c) older cash sale, no drawer row → grand_total refunded               (fallback kept)
 *      d) split sale, row has no sale_id → its cash leg refunded, by receipt
 *      e) credit sale with NO deposit   → nothing refunded
 *   3. posFixVoidedSaleDrawerCash() (the migration) — in a rolled-back txn:
 *      a) adds the missing refund to an OPEN shift, once (idempotent)
 *      b) leaves a CLOSED shift alone
 *      c) adds nothing for a sale whose void already refunded it
 *
 * Exit 0 = all pass. Synthetic rows are removed in a finally block.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
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
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function ok(bool $cond, string $m): void { $cond ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

global $pdo;
$voidFile = "$root/api/pos/void_sale.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. Lint + wiring');
// ─────────────────────────────────────────────────────────────────────────
foreach ([$voidFile, "$root/core/pos_shift_reporting.php",
          "$root/migrations/tenant/2026_10_26_pos_void_drawer_cash_fix.php",
          "$root/migrations/2026_10_26_pos_void_drawer_cash_fix_legacy_db.php"] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    ok($rc === 0, basename($f) . ' lint-clean');
}
$src = file_get_contents($voidFile);
ok(strpos($src, "posSaleDrawerCashOwed(\$pdo, \$sale_id") !== false, 'void_sale.php asks the drawer ledger what is owed');
ok(strpos($src, "if (\$sale['payment_method'] === 'cash') {\n        \$shiftStmt") === false
   && strpos($src, "=== 'cash') {\r\n        \$shiftStmt") === false, "void_sale.php no longer gates the refund on payment_method === 'cash'");
ok(strpos(file_get_contents("$root/migrations/tenant/2026_10_26_pos_void_drawer_cash_fix.php"), 'posFixVoidedSaleDrawerCash') !== false
   && strpos(file_get_contents("$root/migrations/2026_10_26_pos_void_drawer_cash_fix_legacy_db.php"), 'posFixVoidedSaleDrawerCash') !== false,
   'both migrations (tenant + legacy-DB) call the shared repair function');

// ─────────────────────────────────────────────────────────────────────────
// Helpers for the DB sections
// ─────────────────────────────────────────────────────────────────────────
$tag      = 'TESTVOID-' . substr(md5((string)microtime(true)), 0, 8);
$saleIds  = [];
$shiftIds = [];

function makeShift(PDO $pdo, int $userId, string $status, string $tag, array &$shiftIds): int {
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, status, start_time, starting_cash) VALUES (?, ?, ?, NOW(), 0)")
        ->execute([$tag . '-S' . count($shiftIds), $userId, $status]);
    $id = (int)$pdo->lastInsertId();
    $shiftIds[] = $id;
    return $id;
}
function makeSale(PDO $pdo, int $shiftId, string $receipt, string $method, float $total, string $status = 'completed'): int {
    $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, payment_method, grand_total, sale_status, is_return_sale, sale_date)
                   VALUES (?, ?, 4, ?, ?, ?, 0, NOW())")
        ->execute([$receipt, $shiftId, $method, $total, $status]);
    return (int)$pdo->lastInsertId();
}
function drawerRow(PDO $pdo, int $shiftId, string $type, float $amt, string $receipt, ?int $saleId, ?string $reason = null): void {
    $pdo->prepare("INSERT INTO cash_register_transactions (shift_id, transaction_type, amount, payment_method, reference_number, sale_id, reason, created_by, created_at)
                   VALUES (?, ?, ?, 'cash', ?, ?, ?, 4, NOW())")
        ->execute([$shiftId, $type, $amt, $receipt, $saleId, $reason]);
}
function drawerNet(PDO $pdo, int $saleId, string $receipt): float {
    $r = posSaleDrawerCashOwed($pdo, $saleId, $receipt);
    return $r['owed'];
}
function refundTotal(PDO $pdo, int $saleId, string $receipt): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_register_transactions
                         WHERE transaction_type = 'refund' AND reason LIKE 'Void:%' AND (sale_id = ? OR (sale_id IS NULL AND reference_number = ?))");
    $s->execute([$saleId, $receipt]);
    return (float)$s->fetchColumn();
}
/** Run the real endpoint in-process; returns its decoded JSON. */
function runVoid(int $saleId): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['sale_id' => $saleId, 'reason' => 'test void', '_csrf' => 'test-csrf'];
    unset($_SERVER['HTTP_AUTHORIZATION']);
    ob_start();
    (function () { include $GLOBALS['voidFile']; })();
    $out = ob_get_clean();
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr($out, 0, 200)];
}

try {
    // ─────────────────────────────────────────────────────────────────────
    section('2. Real void_sale.php against the local DB');
    // ─────────────────────────────────────────────────────────────────────
    $st = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = 4 AND status = 'active' LIMIT 1");
    $st->execute();
    $opShift = (int)$st->fetchColumn();
    if (!$opShift) $opShift = makeShift($pdo, 4, 'active', $tag, $shiftIds);

    // a) credit sale with a 1,000 deposit — the live bug
    $rA = "$tag-A";
    $a  = makeSale($pdo, $opShift, $rA, 'credit', 2000);  $saleIds[] = $a;
    drawerRow($pdo, $opShift, 'sale', 1000, $rA, $a);
    ok(drawerNet($pdo, $a, $rA) == 1000.0, 'a) before void: drawer holds the 1,000 deposit');
    $res = runVoid($a);
    ok(!empty($res['success']), 'a) void succeeded' . (empty($res['success']) ? ' — ' . ($res['message'] ?? '') : ''));
    ok(refundTotal($pdo, $a, $rA) == 1000.0, 'a) the 1,000 deposit was taken back out');
    ok(drawerNet($pdo, $a, $rA) == 0.0, 'a) nothing left owed — Cash Balance returns to what it was');

    // b) cash sale — regression
    $rB = "$tag-B";
    $b  = makeSale($pdo, $opShift, $rB, 'cash', 6000);  $saleIds[] = $b;
    drawerRow($pdo, $opShift, 'sale', 6000, $rB, $b);
    $res = runVoid($b);
    ok(!empty($res['success']) && refundTotal($pdo, $b, $rB) == 6000.0 && drawerNet($pdo, $b, $rB) == 0.0, 'b) cash sale: 6,000 refunded, nothing owed');

    // c) older cash sale with no drawer row — fallback to grand_total
    $rC = "$tag-C";
    $c  = makeSale($pdo, $opShift, $rC, 'cash', 3000);  $saleIds[] = $c;
    $res = runVoid($c);
    ok(!empty($res['success']) && refundTotal($pdo, $c, $rC) == 3000.0, 'c) cash sale with no drawer row: grand_total refunded (old behaviour kept)');

    // d) split sale — drawer row has no sale_id, matched by receipt number
    $rD = "$tag-D";
    $d  = makeSale($pdo, $opShift, $rD, 'mixed', 1500);  $saleIds[] = $d;
    drawerRow($pdo, $opShift, 'sale', 500, $rD, null);
    $res = runVoid($d);
    ok(!empty($res['success']) && refundTotal($pdo, $d, $rD) == 500.0 && drawerNet($pdo, $d, $rD) == 0.0, 'd) split sale: its 500 cash leg refunded (matched by receipt)');

    // e) credit sale with no deposit — nothing in the drawer, nothing refunded
    $rE = "$tag-E";
    $e  = makeSale($pdo, $opShift, $rE, 'credit', 4000);  $saleIds[] = $e;
    $res = runVoid($e);
    ok(!empty($res['success']) && refundTotal($pdo, $e, $rE) == 0.0, 'e) credit sale with no deposit: nothing refunded');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Repair function (migration) — rolled-back transaction');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();
    $openS   = makeShift($pdo, 9, 'active', "$tag-M", $shiftIds);
    $closedS = makeShift($pdo, 9, 'closed', "$tag-M", $shiftIds);

    // open shift: credit sale, 1,000 deposit, voided, no refund — what the live shift looked like
    $rF = "$tag-F"; $f1 = makeSale($pdo, $openS, $rF, 'credit', 2000, 'voided'); drawerRow($pdo, $openS, 'sale', 1000, $rF, $f1);
    // closed shift: same shape — must be left alone
    $rG = "$tag-G"; $g1 = makeSale($pdo, $closedS, $rG, 'credit', 2000, 'voided'); drawerRow($pdo, $closedS, 'sale', 1000, $rG, $g1);
    // open shift: voided cash sale that WAS refunded — nothing owed
    $rH = "$tag-H"; $h1 = makeSale($pdo, $openS, $rH, 'cash', 6000, 'voided');
    drawerRow($pdo, $openS, 'sale', 6000, $rH, $h1); drawerRow($pdo, $openS, 'refund', 6000, $rH, $h1, 'Void: test');

    posFixVoidedSaleDrawerCash($pdo);
    ok(refundTotal($pdo, $f1, $rF) == 1000.0 && drawerNet($pdo, $f1, $rF) == 0.0, 'a) open shift: missing 1,000 refund added');
    $before = refundTotal($pdo, $f1, $rF);
    posFixVoidedSaleDrawerCash($pdo);
    ok(refundTotal($pdo, $f1, $rF) == $before, 'a) run again: no second refund (idempotent)');
    ok(refundTotal($pdo, $g1, $rG) == 0.0, 'b) closed shift left alone');
    ok(refundTotal($pdo, $h1, $rH) == 6000.0, 'c) already-refunded cash sale: nothing added');

    $pdo->rollBack();
    $left = $pdo->prepare("SELECT COUNT(*) FROM cash_register_transactions WHERE reference_number IN (?, ?, ?)");
    $left->execute([$rF, $rG, $rH]);
    ok((int)$left->fetchColumn() === 0, 'rollback left no repair-test rows behind');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Remove everything section 2 committed.
    foreach ($saleIds as $sid) {
        $pdo->prepare("DELETE FROM cash_register_transactions WHERE sale_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM pos_sales WHERE sale_id = ?")->execute([$sid]);
    }
    $pdo->prepare("DELETE FROM cash_register_transactions WHERE reference_number LIKE ?")->execute([$tag . '%']);
    foreach ($shiftIds as $shid) {
        $pdo->prepare("DELETE FROM cash_register_transactions WHERE shift_id = ?")->execute([$shid]);
        $pdo->prepare("DELETE FROM cash_register_shifts WHERE shift_id = ?")->execute([$shid]);
    }
}
