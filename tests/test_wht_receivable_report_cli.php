<?php
/**
 * WHT Receivable visibility (Plan B / B4) CLI render test.
 *   php tests/test_wht_receivable_report_cli.php
 *
 * Seeds a completed customer payment carrying WHT, then renders the REAL
 * balance_sheet.php (asserts a WHT Receivable ASSET line) and the new
 * wht_receivable_report.php (per-customer credit). Fixture payment removed.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['as_of_date'] = date('Y-m-d');
    $_GET['date_from']  = '2000-01-01';
    $_GET['date_to']    = date('Y-m-d');
    require "$root/" . $argv[2];
    exit;
}
require_once "$root/roots.php";
require_once "$root/core/wht.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function render($page) { global $root; return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($page)); }

$payId = 0;
try {
    // Pick a customer with NO existing WHT, so the seeded 25,000 is that customer's
    // only WHT and appears exactly in the per-customer report (a customer that already
    // carries real WHT would aggregate to a different total and flake this assertion).
    $cust = $pdo->query("
        SELECT c.customer_id, c.customer_name
          FROM customers c
         WHERE c.status <> 'inactive'
           AND NOT EXISTS (SELECT 1 FROM payments p
                            WHERE p.customer_id = c.customer_id
                              AND p.status = 'completed' AND p.wht_amount > 0)
         LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$cust) { $cust = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status <> 'inactive' LIMIT 1")->fetch(PDO::FETCH_ASSOC); }
    $r5   = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    ok($cust && $r5, "have a customer + WHT 5% rate");

    $pn = '__WHTR-' . time();
    $pdo->prepare("INSERT INTO payments (payment_number, customer_id, payment_date, amount, currency, payment_method, status, wht_rate_id, wht_base, wht_amount, wht_posted, created_by, received_by)
                   VALUES (?, ?, ?, 525000, 'TZS', 'bank_transfer', 'completed', ?, 500000, 25000, 25000, 4, 4)")
        ->execute([$pn, (int)$cust['customer_id'], date('Y-m-d'), $r5]);
    $payId = (int)$pdo->lastInsertId();
    ok($payId > 0, "seeded a completed customer payment with WHT 25,000");
    ok(whtReceivablePosition($pdo)['receivable'] >= 25000, "whtReceivablePosition reflects the seeded WHT credit");

    // Balance Sheet — WHT Receivable should appear as an ASSET line
    $bs = render('app/constant/reports/balance_sheet.php');
    ok(strlen($bs) > 500, "balance sheet rendered (" . strlen($bs) . " bytes)");
    ok(strpos($bs, 'WHT Receivable') !== false, "'WHT Receivable' asset line present on the Balance Sheet");

    // WHT Credit report — per-customer
    $rep = render('app/constant/reports/wht_receivable_report.php');
    ok(stripos($rep, 'Withholding Tax Credit') !== false, "page is the WHT Credit report (no fatal error)");
    ok(strpos($rep, 'WHT Withheld From Us') !== false, "per-customer table present");
    ok(strpos($rep, '25,000.00') !== false, "seeded WHT 25,000.00 appears in the report");
    ok(strpos($rep, htmlspecialchars($cust['customer_name'], ENT_QUOTES, 'UTF-8')) !== false
       || strpos($rep, $cust['customer_name']) !== false, "customer name listed");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($payId) { try { $pdo->prepare("DELETE FROM payments WHERE payment_id=?")->execute([$payId]); } catch (Throwable $e) {} }
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
