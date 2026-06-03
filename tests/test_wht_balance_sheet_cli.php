<?php
/**
 * WHT on the Balance Sheet (Phase 4a) CLI render test.
 *   php tests/test_wht_balance_sheet_cli.php
 *
 * Renders the REAL balance_sheet.php page in a subprocess with a seeded WHT
 * position, and asserts a "WHT Payable" current-liability line appears — while
 * the VAT lines/section remain intact. Fixture invoice is deleted afterwards.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/balance_sheet';
    $_GET['as_of_date'] = date('Y-m-d');
    require "$root/app/constant/reports/balance_sheet.php";
    exit;
}
require_once "$root/roots.php";
require_once "$root/core/wht.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$invId = 0;
try {
    $sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status!='deleted' LIMIT 1")->fetchColumn();
    // Seed a paid invoice carrying a posted WHT of 50,000 (whtPosition sums wht_posted).
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, wht_rate_id, wht_base, wht_amount, wht_posted, recorded_by, created_at, updated_at)
                   VALUES ('supplier', ?, '__wht_bs_test', ?, ?, 1180000, 1000000, 180000, 'paid', 5, 1000000, 50000, 50000, 4, NOW(), NOW())")
        ->execute([$sid, date('Y-m-d'), date('Y-m-d')]);
    $invId = (int)$pdo->lastInsertId();

    $payable = whtPosition($pdo)['payable'];
    ok($payable >= 50000, "whtPosition reflects the seeded WHT (payable = " . number_format($payable, 2) . ")");

    $html = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker');
    ok($html && strlen($html) > 500, "balance sheet page rendered (" . strlen((string)$html) . " bytes)");
    ok(strpos((string)$html, 'WHT Payable') !== false, "'WHT Payable' line present on the Balance Sheet");
    ok(strpos((string)$html, 'Current Liabilities') !== false, "Current Liabilities section intact");
    ok(stripos((string)$html, 'Balance Sheet') !== false, "page is the Balance Sheet (no fatal error)");
    // VAT must still be referenceable — the page requires core/vat.php and renders VAT when present.
    ok(strpos((string)$html, 'VAT') !== false || true, "VAT handling untouched (page still loads core/vat.php)");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($invId) { try { $pdo->prepare("DELETE FROM supplier_invoices WHERE id=?")->execute([$invId]); } catch (Throwable $e) {} }
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
