<?php
/**
 * Balance Sheet — Phase 4 Regression Guard
 *
 * Locks in the rewrite of app/constant/reports/balance_sheet.php:
 *
 *   - Uses at.category from account_types (NOT LOWER(at.type_name) IN (...))
 *   - Uses fc_balance() / fc_type_ids_for_categories() / fc_unclassified_types()
 *     from core/financial_classification.php
 *   - Computes Retained Earnings = Σ(Revenue) − Σ(COGS) − Σ(Expenses)
 *     via the canonical fc_balance() per category (no LOWER LIKE
 *     'revenue/expense/cogs' filters)
 *   - Shows Retained Earnings as an EXPLICIT visible line in the Equity
 *     section (not a hidden adder)
 *   - Shows a visible BALANCE / DOES NOT BALANCE banner (success + failure
 *     variants — was previously only a danger banner hidden via d-print-none)
 *   - Surfaces unclassified-types warning
 *
 * Print layout (header / footer / @page from updates 178-181) NOT touched.
 *
 * Run:  php tests/test_balance_sheet_cli.php
 *   Exit 0 = all pass
 *   Exit 1 = failures (push blocked)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ Balance Sheet — Phase 4 Regression Guard ═══\033[0m\n";

$file = $root . '/app/constant/reports/balance_sheet.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. File exists and is syntactically valid PHP');
// ─────────────────────────────────────────────────────────────────────────────

check(is_file($file), 'balance_sheet.php exists', 'balance_sheet.php missing');
$out = []; $code = 0;
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
check($code === 0, 'passes php -l', 'has PHP syntax errors: ' . implode(' | ', $out));

$src = is_file($file) ? file_get_contents($file) : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. Uses the canonical classification helper');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, 'core/financial_classification.php'),
    'requires core/financial_classification.php',
    'classification helper not loaded'
);

check(
    str_contains($src, 'fc_balance('),
    'uses fc_balance() for natural-side calculations',
    'no fc_balance() calls — balances not computed via canonical helper'
);

check(
    str_contains($src, 'fc_type_ids_for_categories('),
    'uses fc_type_ids_for_categories() to resolve P&L type_ids',
    'no fc_type_ids_for_categories() — Retained Earnings calc uses legacy classification'
);

check(
    str_contains($src, 'fc_unclassified_types('),
    'surfaces unclassified_types for warning banner',
    'no unclassified-types warning data fetched'
);

// ─────────────────────────────────────────────────────────────────────────────
section('3. SQL uses at.category, not type_name LIKE list');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match("/at\\.category\\s+IN\\s*\\(\\s*['\"]asset['\"]\\s*,\\s*['\"]liability['\"]\\s*,\\s*['\"]equity['\"]\\s*\\)/", $src),
    "main SQL uses at.category IN ('asset','liability','equity')",
    "main SQL no longer filters by canonical category list"
);

check(
    !preg_match("/LOWER\\(at\\.type_name\\)\\s+IN\\s*\\(\\s*['\"]asset['\"]\\s*,\\s*['\"]liability['\"]/", $src),
    'legacy LOWER(at.type_name) IN (...) filter is gone',
    'legacy type_name LIKE-list filter still present — should use category'
);

check(
    !preg_match("/LOWER\\(at\\.type_name\\)\\s+IN\\s*\\(\\s*['\"]income['\"]/", $src),
    'legacy income-side LOWER(type_name) IN list is gone (Retained Earnings)',
    'Retained Earnings still uses LOWER(type_name) IN classification'
);

check(
    (bool) preg_match('/at\.category/', $src),
    'SQL selects at.category',
    'SQL does not select at.category'
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. journal_entries filtered by status = posted (in JOIN)');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match("/je\\.status\\s*=\\s*['\"]posted['\"]/", $src),
    "filters je.status = 'posted'",
    "je.status = 'posted' filter missing"
);

check(
    (bool) preg_match("/AND\\s+je\\.entry_date\\s*<=\\s*\\?/i", $src),
    'filters je.entry_date <= ? in JOIN clause',
    'no je.entry_date <= ? filter — as-of date ignored'
);

check(
    !preg_match("/je\\.status\\s*=\\s*['\"]posted['\"]\\s+OR\\s+je\\.status\\s+IS\\s+NULL/", $src),
    'no longer uses brittle (je.status = posted OR IS NULL) WHERE',
    'still uses the legacy (status = posted OR IS NULL) WHERE pattern'
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. Retained Earnings computed correctly and shown explicitly');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, '$net_income'),
    '$net_income variable computed',
    '$net_income not computed'
);

// Net profit identity: Revenue − COGS − Expenses
$hasRevenue = str_contains($src, "cat_totals['revenue']");
$hasCogs    = str_contains($src, "cat_totals['cogs']");
$hasExpense = str_contains($src, "cat_totals['expense']");
check(
    $hasRevenue && $hasCogs && $hasExpense,
    'Retained Earnings identity: Revenue − COGS − Expenses',
    'Retained Earnings identity not coded as Revenue − COGS − Expenses'
);

check(
    str_contains($src, 'Retained Earnings'),
    'Retained Earnings appears in the rendered output',
    'Retained Earnings label is missing from the report'
);

check(
    str_contains($src, 'retained-earnings-row'),
    'Retained Earnings has a dedicated CSS class (visible styling)',
    'Retained Earnings is not styled as a distinct line'
);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Balance-check banner (success + failure variants)');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, 'BALANCE SHEET BALANCES'),
    'success banner text present',
    'success "BALANCES" banner missing'
);

check(
    str_contains($src, 'BALANCE SHEET DOES NOT BALANCE'),
    'failure banner text present',
    'failure "DOES NOT BALANCE" banner missing'
);

check(
    str_contains($src, '$bs_balanced'),
    '$bs_balanced flag computed',
    '$bs_balanced never computed'
);

check(
    str_contains($src, '$bs_difference'),
    '$bs_difference computed and displayed when off',
    '$bs_difference not computed'
);

// Banner should NOT be d-print-none — accountant wants to see it on the printed copy
check(
    (bool) preg_match('/alert-success[^>]*>[\s\S]{0,400}BALANCE SHEET BALANCES/', $src) ||
    !preg_match('/BALANCE SHEET BALANCES[\s\S]{0,200}d-print-none/', $src),
    'balance-check banner is NOT hidden on print',
    'balance-check banner is hidden on print — accountant cannot see it on printed copy'
);

// ─────────────────────────────────────────────────────────────────────────────
section('7. Print layout from updates 178-181 NOT touched');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match('/@page\s*\{\s*margin:\s*10mm\s+8mm\s+16mm\s+8mm\s*;\s*\}/', $src),
    'canonical @page margin preserved (10mm 8mm 16mm 8mm)',
    'canonical @page margin was removed'
);

check(
    str_contains($src, "includes/print_footer_css.php") && str_contains($src, "includes/print_footer_html.php"),
    'shared print_footer_css/html includes preserved',
    'shared print footer was removed'
);

check(
    str_contains($src, 'BALANCE SHEET REPORT'),
    'print-header title "BALANCE SHEET REPORT" preserved',
    'print-header title was removed'
);

check(
    !preg_match("/\\\$c_name\\s*=\\s*getSetting\\(\\s*['\"]company_name['\"]/", $src),
    'no duplicate company-name block in print-header',
    'duplicate company-name block reappeared in print-header'
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ Balance Sheet Phase 4 contract intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Balance Sheet regression — see failures above.\033[0m\n\n";
exit(1);
