<?php
/**
 * Income Statement — Phase 2 (accrual expenses + income tax) CLI test.
 *   php tests/test_income_statement_phase2_cli.php
 *
 * Proves:
 *   #5a Expenses recognise APPROVED (incurred) bills, not only paid — an
 *       approved-but-unpaid expense now flows into Operating Expenses.
 *   #4  Income tax is sourced from the configured income-tax account
 *       (default_income_tax_account_id) via posted journals; 0 when unset.
 *
 * Seeds fixtures in an isolated future window, asserts deltas, then removes
 * every fixture and restores the setting.
 */
$root = dirname(__DIR__);
// Worker mode: run the API in a FRESH process so getSetting() reloads settings
// each call (it caches per-process — in production every HTTP request is fresh).
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role'] = 'admin';
    require_once "$root/roots.php";
    $_GET = ['start_date' => $argv[2], 'end_date' => $argv[3]];
    require "$root/api/account/get_income_statement.php";
    exit;
}
require_once "$root/roots.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role'] = 'admin';
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.5; }
function callIS($from, $to) {
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker " . escapeshellarg($from) . " " . escapeshellarg($to));
    $s = strpos($o, '{');
    return $s === false ? null : json_decode(substr($o, $s), true);
}

// ── Static checks ───────────────────────────────────────────────────────────
$api = file_get_contents("$root/api/account/get_income_statement.php");
ok(strpos($api, "e.status IN ('approved','paid')") !== false, "#5a expenses query recognises approved+paid (accrual)");
ok(strpos($api, '$sumIncomeTax') !== false, "#4 income-tax sourcing closure present");
ok(strpos($api, "default_income_tax_account_id") !== false, "#4 reads default_income_tax_account_id setting");
ok(strpos($api, '$income_tax        = 0.0;') === false, "#4 income tax no longer hardcoded to 0");

$from = '2032-04-01'; $to = '2032-04-30'; $d = '2032-04-15';
$expIds = []; $jeId = 0; $origTaxSetting = null; $taxAcc = 0;
try {
    // ── #5a: approved (unpaid) expense must be INCLUDED ──────────────────────
    $base = callIS($from, $to);
    ok(!empty($base['success']), "API responds for isolated window");
    $exp0 = (float)($base['data']['totals']['total_expenses'] ?? 0);
    $catId = (int)$pdo->query("SELECT id FROM expense_categories LIMIT 1")->fetchColumn();

    $mkExp = function (string $status, float $amt) use ($pdo, $d, $catId, &$expIds) {
        $pdo->prepare("INSERT INTO expenses (category_id, amount, expense_date, status, project_id, payroll_id, created_at)
                       VALUES (?, ?, ?, ?, NULL, NULL, NOW())")
            ->execute([$catId ?: null, $amt, $d, $status]);
        $expIds[] = (int)$pdo->lastInsertId();
    };
    $mkExp('approved', 300000);   // incurred, unpaid → accrual INCLUDES
    $mkExp('pending',  999000);   // not yet incurred  → EXCLUDED
    $after = callIS($from, $to);
    ok(approx($after['data']['totals']['total_expenses'], $exp0 + 300000),
       "approved-but-unpaid expense INCLUDED (+300,000); pending excluded");

    // ── #4: income tax from configured account ───────────────────────────────
    $taxAcc = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 1")->fetchColumn();
    $origRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_income_tax_account_id'")->fetch(PDO::FETCH_ASSOC);
    $origTaxSetting = $origRow ? $origRow['setting_value'] : null;

    // Default/unset → income tax 0 (no regression)
    $pdo->prepare("DELETE FROM system_settings WHERE setting_key='default_income_tax_account_id'")->execute();
    $noTax = callIS($from, $to);
    ok(approx($noTax['data']['totals']['income_tax'], 0), "income tax = 0 when no account configured (no regression)");

    // Configure account + post a journal entry debiting it 120,000 in the window
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('default_income_tax_account_id', ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([(string)$taxAcc]);
    $contra = (int)$pdo->query("SELECT account_id FROM accounts WHERE account_id <> $taxAcc ORDER BY account_id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO journal_entries (entry_date, reference_number, description, debit_account_id, credit_account_id, amount, status, created_by, created_at)
                   VALUES (?, ?, 'IS P2 tax test', ?, ?, 120000, 'posted', 4, NOW())")
        ->execute([$d, '__IS_P2_TAX_' . uniqid(), $taxAcc, $contra]);
    $jeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO journal_entry_items (entry_id, account_id, type, amount) VALUES (?, ?, 'debit', 120000)")->execute([$jeId, $taxAcc]);
    $pdo->prepare("INSERT INTO journal_entry_items (entry_id, account_id, type, amount) VALUES (?, ?, 'credit', 120000)")->execute([$jeId, $contra]);

    $withTax = callIS($from, $to);
    ok(approx($withTax['data']['totals']['income_tax'], 120000), "income tax = 120,000 from posted journal to configured account");
    $t = $withTax['data']['totals'];
    ok(approx($t['net_profit'], $t['profit_before_tax'] - 120000), "net_profit = PBT − income tax");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($jeId) { try { $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id=?")->execute([$jeId]); $pdo->prepare("DELETE FROM journal_entries WHERE entry_id=?")->execute([$jeId]); } catch (Throwable $e) {} }
    foreach ($expIds as $id) { try { $pdo->prepare("DELETE FROM expenses WHERE expense_id=?")->execute([$id]); } catch (Throwable $e) {} }
    // Restore the income-tax setting to its original state
    try {
        if ($origTaxSetting === null) $pdo->prepare("DELETE FROM system_settings WHERE setting_key='default_income_tax_account_id'")->execute();
        else $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='default_income_tax_account_id'")->execute([$origTaxSetting]);
    } catch (Throwable $e) {}
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
