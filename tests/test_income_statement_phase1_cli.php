<?php
/**
 * Income Statement — Phase 1 (accrual integrity) CLI test.
 *   php tests/test_income_statement_phase1_cli.php
 *
 * Proves:
 *   #1 Revenue & product-COGS recognise only APPROVED states (approved/paid/
 *      partial) — a draft (pending/reviewed) invoice is EXCLUDED.
 *   #2 The view surfaces the draft-journals and unpaid-payroll warnings.
 *   #3 The API docblock reflects the accrual/recognised-status rule.
 *
 * Runtime test seeds one pending + one approved invoice in an isolated future
 * window, asserts only the approved one counts, then deletes both.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role'] = 'admin';
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.5; }
function callIS($from, $to) {
    global $root, $pdo;   // API reads $pdo before its own `global` (line 66) — bind it here
    $_GET = ['start_date' => $from, 'end_date' => $to];
    $lvl = error_reporting(error_reporting() & ~E_WARNING);
    ob_start(); require "$root/api/account/get_income_statement.php"; $raw = ob_get_clean();
    error_reporting($lvl);
    return json_decode($raw, true);
}

// ── Static source checks (#2, #3) ──────────────────────────────────────────
$api  = file_get_contents("$root/api/account/get_income_statement.php");
$page = file_get_contents("$root/app/bms/invoice/income_statement.php");
ok(strpos($api, "status IN ('approved','paid','partial')") !== false, "#1 API filters invoices to recognised statuses");
ok(substr_count($api, "status IN ('approved','paid','partial')") >= 2, "#1 filter applied to BOTH revenue and product-COGS");
ok(strpos($page, 'id="draftJournalsNotice"') !== false && strpos($page, "meta.draft_count") !== false, "#2 view surfaces draft-journals warning");
ok(strpos($page, 'id="unpaidPayrollNotice"') !== false && strpos($page, "meta.unpaid_payroll_count") !== false, "#2 view surfaces unpaid-payroll warning");
ok(strpos($api, 'recognised once an invoice is') !== false || strpos($api, 'approved/paid/partial') !== false, "#3 docblock states the accrual recognised-status rule");

// ── Runtime: draft invoice excluded, approved included ─────────────────────
$ids = [];
try {
    $cust = (int)$pdo->query("SELECT customer_id FROM customers LIMIT 1")->fetchColumn();
    // Isolated window far from real data so the delta is purely our fixtures.
    $from = '2031-03-01'; $to = '2031-03-31'; $d = '2031-03-15';
    $base = callIS($from, $to);
    ok(!empty($base['success']), "API responds for the isolated window");
    $rev0 = (float)($base['data']['totals']['total_revenue'] ?? 0);

    $mk = function (string $status, float $grand, float $tax) use ($pdo, $cust, $d, &$ids) {
        $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, subtotal, tax_amount, grand_total, paid_amount, balance_due, status, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())")
            ->execute(['__IS_P1_' . $status . '_' . uniqid(), $cust, $d, $grand - $tax, $tax, $grand, $grand, $status]);
        $ids[] = (int)$pdo->lastInsertId();
    };
    // Pending (draft) 1,000,000 net + 180,000 tax → must be EXCLUDED
    $mk('pending', 1180000, 180000);
    $afterPending = callIS($from, $to);
    ok(approx($afterPending['data']['totals']['total_revenue'], $rev0), "pending invoice EXCLUDED from revenue (accrual: not yet recognised)");

    // Approved 500,000 net + 90,000 tax → must ADD 500,000 net revenue
    $mk('approved', 590000, 90000);
    $afterApproved = callIS($from, $to);
    ok(approx($afterApproved['data']['totals']['total_revenue'], $rev0 + 500000), "approved invoice INCLUDED (revenue +500,000 net)");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    foreach ($ids as $id) { try { $pdo->prepare("DELETE FROM invoices WHERE invoice_id=?")->execute([$id]); } catch (Throwable $e) {} }
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
