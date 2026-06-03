<?php
/**
 * WHT foundation (Phase 1) schema CLI test.
 *   php tests/test_wht_foundation_cli.php
 *
 * Run AFTER migrations/2026_06_03_wht_payable_foundation.php. Asserts the
 * migration produced a correct, ADDITIVE schema — tax_kind classification,
 * WHT 5% seed, WHT Payable account + setting, and the wht_* tracking columns —
 * and proves VAT was NOT touched (the Balance Sheet 18% line stays frozen).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

try {
    // ── 1. tax_kind column + classification ─────────────────────────────────
    ok((bool)$pdo->query("SHOW COLUMNS FROM tax_rates LIKE 'tax_kind'")->fetch(), "tax_rates.tax_kind exists");
    $kind = fn($w) => $pdo->query("SELECT tax_kind FROM tax_rates WHERE $w LIMIT 1")->fetchColumn();
    ok($kind("rate_percentage=18.00") === 'vat',                              "VAT 18% classified 'vat'");
    ok($kind("rate_name LIKE '%No Tax%'") === 'none',                          "No Tax classified 'none'");
    ok($kind("rate_percentage=2.00 AND rate_name LIKE '%ithholding%'") === 'wht', "WHT 2% classified 'wht'");
    ok($kind("rate_percentage=5.00 AND tax_kind='wht'") === 'wht',             "WHT 5% seeded and classified 'wht'");

    // ── 2. WHT Payable account + setting ────────────────────────────────────
    $wht = $pdo->query("SELECT account_id, account_type, cash_flow_category FROM accounts WHERE account_name='WHT Payable' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($wht && $wht['account_type'] === 'liability', "WHT Payable account exists and is a liability");
    ok($wht && $wht['cash_flow_category'] !== 'cash', "WHT Payable kept out of the Paid-From cash/bank picker");
    $setId = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_wht_payable_account_id'")->fetchColumn();
    ok($setId && (int)$setId === (int)($wht['account_id'] ?? -1), "default_wht_payable_account_id points to it");

    // ── 3. Tracking columns ─────────────────────────────────────────────────
    foreach (['supplier_invoices', 'supplier_payments'] as $t) {
        foreach (['wht_rate_id', 'wht_base', 'wht_amount', 'wht_posted'] as $c) {
            ok((bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch(), "$t.$c exists");
        }
    }
    ok((bool)$pdo->query("SHOW COLUMNS FROM suppliers LIKE 'default_wht_rate_id'")->fetch(), "suppliers.default_wht_rate_id exists");

    // ── 4. VAT MUST BE UNTOUCHED (the whole point) ──────────────────────────
    $outAcc = $pdo->query("SELECT a.account_name FROM system_settings s JOIN accounts a ON a.account_id = s.setting_value WHERE s.setting_key='default_output_vat_account_id'")->fetchColumn();
    $inAcc  = $pdo->query("SELECT a.account_name FROM system_settings s JOIN accounts a ON a.account_id = s.setting_value WHERE s.setting_key='default_input_vat_account_id'")->fetchColumn();
    ok($outAcc === 'Output VAT Payable',    "VAT output setting still maps to Output VAT Payable (frozen)");
    ok($inAcc  === 'Input VAT Recoverable', "VAT input setting still maps to Input VAT Recoverable (frozen)");
    ok((bool)$pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'input_vat_posted'")->fetch(), "VAT column input_vat_posted intact");
    ok((bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'output_vat_posted'")->fetch(),         "VAT column output_vat_posted intact");

} catch (Throwable $e) { ok(false, "exception: " . $e->getMessage()); }

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
