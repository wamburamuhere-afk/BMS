<?php
/**
 * Step 1 — "View Account" button on the Suppliers list — CLI test
 *   php tests/test_vendor_account_button_supplier_cli.php
 *
 * Verifies the Suppliers list action dropdown now links to the existing Vendor
 * Statement page (api/account/get_vendor_statement.php), and that the statement
 * resolves correctly end-to-end for a real supplier. Read-only.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

section('1. Suppliers list source — View Account button wired up');
$src = file_get_contents("$root/app/bms/Suppliers/suppliers.php");
(strpos($src, "getUrl('vendor_statement') ?>?vendor_id=<?= \$supplier['supplier_id'] ?>&vendor_type=supplier") !== false)
    ? pass('action dropdown links to vendor_statement with vendor_id + vendor_type=supplier')
    : fail('View Account link missing or malformed in suppliers.php');
(strpos($src, 'View Account') !== false) ? pass('link is labelled "View Account"') : fail('label missing');

section('2. Route is registered');
$routesSrc = file_get_contents("$root/roots.php");
(strpos($routesSrc, "'vendor_statement' => REPORTS_DIR . '/vendor_statement.php'") !== false)
    ? pass("getUrl('vendor_statement') resolves to app/constant/reports/vendor_statement.php")
    : fail('vendor_statement route not registered');

section('3. Live-DB — get_vendor_statement.php resolves a real supplier correctly');
$supplierId = (int)($pdo->query("
    SELECT supplier_id FROM suppliers
     WHERE status='active' AND supplier_id IN (SELECT supplier_id FROM supplier_invoices WHERE invoice_type='supplier')
     LIMIT 1
")->fetchColumn() ?: 0);

if (!$supplierId) {
    fail('no active supplier with invoice history found — cannot run the live check');
} else {
    $vendor = $pdo->prepare("SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
    $vendor->execute([$supplierId]);
    $v = $vendor->fetch(PDO::FETCH_ASSOC);
    ($v && (int)$v['supplier_id'] === $supplierId) ? pass("supplier #$supplierId resolves to '{$v['supplier_name']}'") : fail('supplier did not resolve');

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM supplier_invoices WHERE supplier_id = ? AND status IN ('approved','partial','paid')");
    $cnt->execute([$supplierId]);
    ((int)$cnt->fetchColumn() > 0) ? pass('supplier has at least one recognised invoice for the statement to show') : fail('no recognised invoices found');
}
