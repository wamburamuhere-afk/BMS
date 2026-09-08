<?php
/**
 * Phase 19 (pos_upgrade_plan.md §8) — customer credit-limit enforcement — CLI test
 *   php tests/test_pos_credit_limit_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Wiring: process_sale.php enforces the limit via the extracted helper,
 *      catches the dedicated exception type, and returns a structured
 *      error_code (never string-matched, which would break under translation).
 *   3. Runtime — customerOutstandingBalance(): sums only open (non-voided,
 *      non-fully-paid, non-return) sales' remaining balance for one customer.
 *   4. Runtime — assertPosCreditLimitPermitted():
 *        a. Under the limit -> no throw.
 *        b. Over the limit, no override -> throws PosCreditLimitExceededException.
 *        c. Over the limit, override permitted -> no throw.
 *        d. credit_limit = 0 (the column's own default) -> any real balance blocks.
 *        e. $newBalanceDue = 0 (credit sale fully paid via deposit) -> never blocks.
 *
 * All DB writes happen inside one rolled-back transaction — nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_credit_limit.php";
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
foreach (['core/pos_credit_limit.php', 'api/pos/process_sale.php', 'api/pos/search_customers.php', 'app/bms/pos/pos.php', 'app/bms/pos/pos_scripts_new.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

section('2. Wiring');
$sale = src($root, 'api/pos/process_sale.php');
has($sale, 'assertPosCreditLimitPermitted(', 'process_sale.php enforces the limit via the extracted helper');
has($sale, 'PosCreditLimitExceededException', 'process_sale.php catches the dedicated exception type');
has($sale, "'error_code' => 'credit_limit_exceeded'", 'process_sale.php returns a structured error_code, not a string-matched message');
has($sale, "canEdit('pos')", 'process_sale.php re-checks the override permission fresh, server-side');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'error_code', 'client detects the structured error_code, not translated text');

section('3. Runtime — customerOutstandingBalance() (rolled back)');
$custRow = $pdo->query("SELECT customer_id FROM customers WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$shiftUserRow = $pdo->query("SELECT user_id FROM users WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$custRow || !$shiftUserRow) {
    pass('no active customer/user fixture — skipped (n/a)');
} else {
    $pdo->beginTransaction();
    $cid = (int)$custRow['customer_id'];
    $uid = (int)$shiftUserRow['user_id'];

    $before = customerOutstandingBalance($pdo, $cid);

    // A dummy shift + open (unpaid) sale of 5,000 for this customer.
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, starting_cash, status) VALUES ('CLTEST', ?, 1, 0, 'active')")->execute([$uid]);
    $shiftId = (int)$pdo->lastInsertId();
    $pdo->prepare("
        INSERT INTO pos_sales (receipt_number, shift_id, user_id, customer_id, sale_status, subtotal, tax_amount, grand_total, payment_method, payment_status, sale_date)
        VALUES ('CLTEST-1', ?, ?, ?, 'completed', 5000, 0, 5000, 'credit', 'pending', NOW())
    ")->execute([$shiftId, $uid, $cid]);
    $saleId = (int)$pdo->lastInsertId();

    $after = customerOutstandingBalance($pdo, $cid);
    (abs(($after - $before) - 5000.0) < 0.01)
        ? pass('open unpaid credit sale (5,000) correctly added to outstanding balance')
        : fail("outstanding balance did not increase by 5000: before=$before after=$after");

    // A partial payment of 2,000 should reduce outstanding by exactly that.
    $pdo->prepare("INSERT INTO pos_sale_payments (sale_id, amount, payment_method) VALUES (?, 2000, 'cash')")->execute([$saleId]);
    $pdo->prepare("UPDATE pos_sales SET payment_status='partial' WHERE sale_id=?")->execute([$saleId]);
    $afterPartial = customerOutstandingBalance($pdo, $cid);
    (abs(($afterPartial - $before) - 3000.0) < 0.01)
        ? pass('a 2,000 part-payment reduces outstanding to exactly 3,000 above baseline')
        : fail("partial payment not reflected correctly: $afterPartial vs baseline $before");

    // Fully paid -> excluded entirely.
    $pdo->prepare("UPDATE pos_sales SET payment_status='paid' WHERE sale_id=?")->execute([$saleId]);
    $afterPaid = customerOutstandingBalance($pdo, $cid);
    (abs($afterPaid - $before) < 0.01)
        ? pass('a fully-paid sale is excluded from outstanding balance entirely')
        : fail("paid sale still counted: $afterPaid vs baseline $before");

    // Voided -> excluded entirely, even if payment_status was left stale.
    $pdo->prepare("UPDATE pos_sales SET payment_status='pending', sale_status='voided' WHERE sale_id=?")->execute([$saleId]);
    $afterVoided = customerOutstandingBalance($pdo, $cid);
    (abs($afterVoided - $before) < 0.01)
        ? pass('a voided sale is excluded from outstanding balance regardless of payment_status')
        : fail("voided sale still counted: $afterVoided vs baseline $before");

    $pdo->rollBack();
}

section('4. Runtime — assertPosCreditLimitPermitted()');
if (!$custRow) {
    pass('no active customer fixture — skipped (n/a)');
} else {
    $pdo->beginTransaction();
    $cid = (int)$custRow['customer_id'];
    $pdo->prepare("UPDATE customers SET credit_limit = 10000 WHERE customer_id = ?")->execute([$cid]);

    $threw = false;
    try { assertPosCreditLimitPermitted($pdo, $cid, 5000.0, false, 'Test Customer'); } catch (Throwable $e) { $threw = true; }
    (!$threw) ? pass('new balance (5,000) under the 10,000 limit -> no throw') : fail('under-limit sale incorrectly blocked');

    $threw = false; $msg = '';
    try { assertPosCreditLimitPermitted($pdo, $cid, 15000.0, false, 'Test Customer'); } catch (PosCreditLimitExceededException $e) { $threw = true; $msg = $e->getMessage(); }
    ($threw) ? pass("over-limit sale (15,000 > 10,000) without override -> throws PosCreditLimitExceededException ($msg)") : fail('over-limit sale NOT blocked');

    $threw = false;
    try { assertPosCreditLimitPermitted($pdo, $cid, 15000.0, true, 'Test Customer'); } catch (Throwable $e) { $threw = true; }
    (!$threw) ? pass('over-limit sale WITH override permitted -> no throw') : fail('override did not bypass the block');

    // credit_limit = 0 (the column's own default) -> any real balance blocks.
    $pdo->prepare("UPDATE customers SET credit_limit = 0 WHERE customer_id = ?")->execute([$cid]);
    $threw = false;
    try { assertPosCreditLimitPermitted($pdo, $cid, 100.0, false, 'Test Customer'); } catch (PosCreditLimitExceededException $e) { $threw = true; }
    ($threw) ? pass('credit_limit=0 ("no credit allowed") blocks even a small balance (100)') : fail('zero credit limit did not block');

    // newBalanceDue = 0 -> a "credit" sale fully paid via deposit, never blocks.
    $threw = false;
    try { assertPosCreditLimitPermitted($pdo, $cid, 0.0, false, 'Test Customer'); } catch (Throwable $e) { $threw = true; }
    (!$threw) ? pass('newBalanceDue=0 (fully paid via deposit) never blocks, even with credit_limit=0') : fail('zero-balance-due sale incorrectly blocked');

    $pdo->rollBack();
}
