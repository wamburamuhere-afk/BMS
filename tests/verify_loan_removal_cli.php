<?php
/**
 * verify_loan_removal_cli.php
 * ----------------------------
 * Before/after safety harness for removing the Loans lending module
 * (hide & unwire; data kept dormant). Run it BEFORE the removal to capture a
 * baseline, then AFTER to confirm nothing else broke and the loan DATA is
 * still intact (dormant, not dropped).
 *
 *   php tests/verify_loan_removal_cli.php
 *
 * It checks the things a loan removal could plausibly damage:
 *   - the financial ledger / reports (should be untouched — loans never posted)
 *   - the "Bank Loans" chart-of-accounts liability (2-2100) — a DIFFERENT concept
 *     that must survive
 *   - payroll candidate query (should be untouched)
 *   - loan tables + row counts (must be UNCHANGED after — data preserved)
 *   - roots.php parses and no route points at a missing file
 */
$root = dirname(__DIR__);
require_once $root . '/roots.php';
if (file_exists($root.'/core/financial_reports.php')) require_once $root.'/core/financial_reports.php';
global $pdo;

echo "\n===== LOAN-REMOVAL SAFETY BASELINE =====\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n";

// 1. Loan data footprint (must be identical before vs after — we keep the data)
echo "\n-- Loan data (must be UNCHANGED after removal — data is kept dormant) --\n";
$loanTables = ['loans','loan_applications','loan_products','loan_types','loan_repayments',
    'loan_repayment_schedule','loan_disbursements','loan_collateral','loan_documents',
    'loan_rejections','loan_collection_assignments','loan_risk_factors'];
$total = 0;
foreach ($loanTables as $t) {
    try { $c = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
    catch (Throwable $e) { $c = -1; }
    $total += max(0, $c);
    printf("  %-30s %s\n", $t, $c === -1 ? 'TABLE MISSING' : "$c rows");
}
echo "  TOTAL loan rows preserved: $total\n";

// 2. Financial integrity — loans never posted to the ledger; reports must be unaffected
echo "\n-- Financial ledger (should be unaffected — loans never posted) --\n";
$loanJE = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type LIKE 'loan%'")->fetchColumn();
echo "  journal_entries tied to a loan entity: $loanJE " . ($loanJE === 0 ? '(OK — none)' : '(!! unexpected)') . "\n";
if (function_exists('glTrialBalance')) {
    try {
        $tb = glTrialBalance($pdo, date('Y-m-d'), null, true, '');
        $dr = 0; $cr = 0;
        foreach ($tb as $row) { $dr += (float)($row['debit'] ?? 0); $cr += (float)($row['credit'] ?? 0); }
        printf("  Trial Balance runs: Dr %s = Cr %s %s\n", number_format($dr,2), number_format($cr,2),
            abs($dr-$cr) < 0.01 ? '(balanced)' : '(!! imbalance)');
    } catch (Throwable $e) { echo "  Trial Balance ERROR: " . $e->getMessage() . "\n"; }
} else {
    echo "  glTrialBalance() not loaded in this context (checked separately)\n";
}

// 3. The "Bank Loans" account is standard accounting — must survive
echo "\n-- Bank Loans account (2-2100) — must SURVIVE (different concept) --\n";
$bl = $pdo->query("SELECT account_code, account_name FROM accounts WHERE account_name LIKE '%loan%'")->fetchAll(PDO::FETCH_ASSOC);
if (!$bl) echo "  (!! none found — expected 2-2100 Bank Loans)\n";
foreach ($bl as $a) echo "  KEEP: {$a['account_code']} — {$a['account_name']}\n";

// 4. Payroll candidate query still resolves (unrelated to loans, sanity check)
echo "\n-- Payroll sanity (unrelated to loans) --\n";
$emp = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
echo "  active employees resolvable: $emp\n";

// 5. Routing integrity — no route may point at a file that doesn't exist
echo "\n-- Routing integrity (no route may point to a missing file) --\n";
$broken = 0; $loanRoutes = 0;
if (isset($GLOBALS['routes']) && is_array($GLOBALS['routes'])) {
    foreach ($GLOBALS['routes'] as $k => $v) {
        if (stripos($k, 'loan') !== false) $loanRoutes++;
        if (is_string($v) && substr($v, -4) === '.php' && !file_exists($v)) {
            $broken++;
            if ($broken <= 15) echo "  BROKEN ROUTE: '$k' -> $v (missing)\n";
        }
    }
    echo "  loan-named routes present: $loanRoutes\n";
    echo "  routes pointing at a MISSING file: $broken\n";
} else {
    echo "  (route table not exposed as \$routes global — checked via grep separately)\n";
}

echo "\n===== END BASELINE =====\n";
