<?php
/**
 * WHT Report (Phase 4b) CLI render test.
 *   php tests/test_wht_report_cli.php
 *
 * Renders the REAL wht_report.php page in a subprocess with a seeded WHT payment
 * and asserts the supplier row + WHT total appear. Fixture deleted afterwards.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/wht_report';
    $_GET['date_from'] = '2000-01-01';
    $_GET['date_to']   = date('Y-m-d');
    require "$root/app/constant/reports/wht_report.php";
    exit;
}
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$invId = 0;
try {
    $sup = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status!='deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $sid = (int)$sup['supplier_id'];
    // Seed a paid invoice with a posted WHT of 50,000, payment_date today.
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, payment_date, amount, subtotal, tax_amount, status, wht_rate_id, wht_base, wht_amount, wht_posted, recorded_by, created_at, updated_at)
                   VALUES ('supplier', ?, '__wht_rep_test', ?, ?, ?, 1180000, 1000000, 180000, 'paid', 5, 1000000, 50000, 50000, 4, NOW(), NOW())")
        ->execute([$sid, date('Y-m-d'), date('Y-m-d'), date('Y-m-d')]);
    $invId = (int)$pdo->lastInsertId();
    ok($invId > 0, "seeded a paid invoice with WHT 50,000 for '" . $sup['supplier_name'] . "'");

    $html = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker');
    ok(strlen($html) > 500, "WHT report page rendered (" . strlen($html) . " bytes)");
    ok(stripos($html, 'Withholding Tax Report') !== false, "page is the WHT Report (no fatal error)");
    ok(strpos($html, 'WHT by Supplier') !== false, "per-supplier table present");
    ok(strpos($html, '50,000.00') !== false, "seeded WHT 50,000.00 appears in the report");
    ok(strpos($html, htmlspecialchars($sup['supplier_name'], ENT_QUOTES, 'UTF-8')) !== false
       || strpos($html, $sup['supplier_name']) !== false, "supplier name listed");
    ok(strpos($html, 'GRAND TOTAL') !== false, "grand-total row present");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($invId) { try { $pdo->prepare("DELETE FROM supplier_invoices WHERE id=?")->execute([$invId]); } catch (Throwable $e) {} }
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
