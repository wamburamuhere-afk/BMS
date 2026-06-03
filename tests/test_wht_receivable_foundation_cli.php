<?php
/**
 * WHT Receivable foundation (Plan B / Phase B1) schema CLI test.
 *   php tests/test_wht_receivable_foundation_cli.php
 *
 * Run AFTER migrations/2026_06_03_wht_receivable_foundation.php. Asserts the
 * additive sales-side schema and proves the purchase-side (WHT Payable) and VAT
 * are untouched.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

try {
    // ── WHT Receivable account + setting ────────────────────────────────────
    $r = $pdo->query("SELECT account_id, account_type, cash_flow_category FROM accounts WHERE account_name='WHT Receivable' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($r && $r['account_type'] === 'asset', "WHT Receivable account exists and is an ASSET");
    ok($r && $r['cash_flow_category'] !== 'cash', "WHT Receivable kept out of the cash/bank picker");
    $set = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_wht_receivable_account_id'")->fetchColumn();
    ok($set && (int)$set === (int)($r['account_id'] ?? -1), "default_wht_receivable_account_id points to it");

    // ── Tracking columns ────────────────────────────────────────────────────
    foreach (['wht_rate_id', 'wht_base', 'wht_amount', 'wht_posted'] as $c) {
        ok((bool)$pdo->query("SHOW COLUMNS FROM payments LIKE '$c'")->fetch(), "payments.$c exists");
    }
    ok((bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'default_wht_rate_id'")->fetch(), "customers.default_wht_rate_id exists");

    // ── Purchase-side WHT + VAT MUST be untouched ───────────────────────────
    $payAcc = $pdo->query("SELECT a.account_name FROM system_settings s JOIN accounts a ON a.account_id = s.setting_value WHERE s.setting_key='default_wht_payable_account_id'")->fetchColumn();
    ok($payAcc === 'WHT Payable', "WHT Payable setting still maps to WHT Payable (purchase side intact)");
    $outAcc = $pdo->query("SELECT a.account_name FROM system_settings s JOIN accounts a ON a.account_id = s.setting_value WHERE s.setting_key='default_output_vat_account_id'")->fetchColumn();
    ok($outAcc === 'Output VAT Payable', "VAT output setting still maps to Output VAT Payable (frozen)");
    ok((bool)$pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'wht_posted'")->fetch(), "purchase-side supplier_invoices.wht_posted intact");

} catch (Throwable $e) { ok(false, "exception: " . $e->getMessage()); }

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
