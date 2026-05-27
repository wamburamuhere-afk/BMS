<?php
/**
 * Cash Flow Statement — Phase 5 Regression Guard
 *
 * Locks in the rewrite of app/constant/reports/cash_flow.php:
 *
 *   - Net Income identity matches the Balance Sheet's Retained Earnings:
 *     Revenue − COGS − Expenses (via fc_balance() per category)
 *   - Cash accounts identified by at.cash_flow_category = 'cash'
 *     (NOT account_name LIKE '%cash%' / '%bank%' / '%petty%')
 *   - Operating / Investing / Financing buckets routed by
 *     at.cash_flow_category (NOT account-name LIKE heuristics like
 *     '%vehicle%' or '%loan%')
 *   - Depreciation add-back line present
 *   - Reconciliation banner: computed ending cash vs actual cash balance
 *     on end_date (success + failure variants)
 *
 * Print layout (header / footer / @page from updates 178-181) NOT touched.
 *
 * Run:  php tests/test_cash_flow_cli.php
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

echo "\n\033[1m═══ Cash Flow — Phase 5 Regression Guard ═══\033[0m\n";

$file = $root . '/app/constant/reports/cash_flow.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. File exists and is syntactically valid PHP');
// ─────────────────────────────────────────────────────────────────────────────

check(is_file($file), 'cash_flow.php exists', 'cash_flow.php missing');
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
    str_contains($src, 'fc_type_ids_for_categories('),
    'uses fc_type_ids_for_categories() for P&L category lookup',
    'no fc_type_ids_for_categories() — Net Income calc uses legacy classification'
);

check(
    str_contains($src, 'fc_balance('),
    'uses fc_balance() for natural-side balance calculations',
    'no fc_balance() calls — balances computed inline (brittle)'
);

check(
    str_contains($src, 'fc_type_ids_for_cash_flow_category(') && str_contains($src, "'cash'"),
    "uses fc_type_ids_for_cash_flow_category('cash') for cash accounts",
    'no cash-flow-category lookup — cash accounts still identified by name LIKE'
);

check(
    str_contains($src, 'fc_unclassified_types('),
    'surfaces fc_unclassified_types() for warning banner',
    'no unclassified-types warning data fetched'
);

// ─────────────────────────────────────────────────────────────────────────────
section('3. Legacy account-name LIKE heuristics are GONE');
// ─────────────────────────────────────────────────────────────────────────────

check(
    !preg_match("/strpos\\(\\\$name,\\s*['\"]cash['\"]\\)/", $src),
    'no strpos($name, "cash") heuristic',
    'still uses strpos($name, "cash") — replace with cash_flow_category'
);

check(
    !preg_match("/strpos\\(\\\$name,\\s*['\"]bank['\"]\\)/", $src),
    'no strpos($name, "bank") heuristic',
    'still uses strpos($name, "bank") heuristic'
);

check(
    !preg_match("/strpos\\(\\\$name,\\s*['\"]petty['\"]\\)/", $src),
    'no strpos($name, "petty") heuristic',
    'still uses strpos($name, "petty") heuristic'
);

check(
    !preg_match("/strpos\\(\\\$name,\\s*['\"](fixed|equipment|property|vehicle|computer|machinery|land|building)['\"]\\)/", $src),
    'no asset-name LIKE heuristics (fixed/equipment/property/vehicle/etc.)',
    'still uses asset-name LIKE heuristics — replace with cash_flow_category = "investing"'
);

check(
    !preg_match("/strpos\\(\\\$name,\\s*['\"]loan['\"]\\)/", $src),
    'no strpos($name, "loan") heuristic',
    'still uses strpos($name, "loan") — replace with cash_flow_category = "financing"'
);

check(
    !preg_match("/strpos\\(\\\$name,\\s*['\"](payable|creditor)['\"]\\)/", $src),
    'no payable/creditor name heuristics',
    'still uses payable/creditor name heuristics'
);

check(
    !preg_match("/LOWER\\(at\\.type_name\\)\\s+IN\\s*\\(\\s*['\"]income['\"]/", $src),
    'Net Income calc no longer uses LOWER(type_name) IN (income, revenue, expense)',
    'Net Income calc still uses legacy LOWER(type_name) IN list'
);

check(
    !preg_match("/LOWER\\(a\\.account_name\\)\\s+LIKE\\s+['\"]%cash%['\"]/i", $src),
    'Opening cash query no longer uses account_name LIKE "%cash%"',
    'Opening cash query still uses account_name LIKE — replace with cash_flow_category'
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. cash_flow_category drives section routing');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, "at.cash_flow_category") || str_contains($src, "cf_category"),
    'SQL selects at.cash_flow_category as cf_category',
    'cash_flow_category not selected — section routing impossible'
);

check(
    (bool) preg_match("/\\\$cf\\s*===?\\s*['\"]cash['\"]/", $src) ||
    (bool) preg_match("/cf_category[\\s\\S]{0,50}['\"]cash['\"]/", $src),
    "code branches on cf === 'cash'",
    "code does not branch on cf_category = 'cash'"
);

check(
    (bool) preg_match("/\\\$cf\\s*===?\\s*['\"]investing['\"]/", $src),
    "code branches on cf === 'investing'",
    "code does not branch on cf_category = 'investing'"
);

check(
    (bool) preg_match("/\\\$cf\\s*===?\\s*['\"]financing['\"]/", $src),
    "code branches on cf === 'financing'",
    "code does not branch on cf_category = 'financing'"
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. Net Income identity matches Balance Sheet (Revenue − COGS − Expenses)');
// ─────────────────────────────────────────────────────────────────────────────

$hasRev = str_contains($src, "cat_totals['revenue']");
$hasCog = str_contains($src, "cat_totals['cogs']");
$hasExp = str_contains($src, "cat_totals['expense']");
check(
    $hasRev && $hasCog && $hasExp,
    'Net Income identity uses Revenue − COGS − Expenses (matches Balance Sheet)',
    'Net Income identity does not match Balance Sheet — reports could disagree'
);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Depreciation add-back present');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, '$depreciation_addback') || str_contains($src, 'depreciation_addback'),
    '$depreciation_addback computed',
    'depreciation add-back missing — indirect method incomplete'
);

check(
    (bool) preg_match("/LOWER\\(at\\.type_name\\)\\s+LIKE\\s+['\"]%depreciation%['\"]/i", $src),
    'depreciation query matches type_name LIKE "%depreciation%"',
    'depreciation query does not look up depreciation accounts properly'
);

check(
    str_contains($src, 'Depreciation') && str_contains($src, 'non-cash'),
    'depreciation row visible in report with "non-cash" caption',
    'depreciation row not rendered in the operating section'
);

// ─────────────────────────────────────────────────────────────────────────────
section('7. Reconciliation banner (computed vs actual ending cash)');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, '$cash_reconciles'),
    '$cash_reconciles boolean computed',
    '$cash_reconciles flag never computed'
);

check(
    str_contains($src, '$cash_end_actual'),
    '$cash_end_actual queried from journal entries',
    '$cash_end_actual not queried — reconciliation impossible'
);

check(
    str_contains($src, '$cash_end_computed'),
    '$cash_end_computed = opening + net change tracked',
    '$cash_end_computed not tracked'
);

check(
    str_contains($src, 'CASH FLOW RECONCILES'),
    'reconciliation success banner present',
    '"CASH FLOW RECONCILES" success banner missing'
);

check(
    str_contains($src, 'CASH FLOW DOES NOT RECONCILE'),
    'reconciliation failure banner present',
    '"DOES NOT RECONCILE" failure banner missing'
);

// ─────────────────────────────────────────────────────────────────────────────
section('8. journal_entries filtered by status = posted');
// ─────────────────────────────────────────────────────────────────────────────

$postedCount = preg_match_all("/je\\.status\\s*=\\s*['\"]posted['\"]/", $src);
check(
    $postedCount >= 2,
    "filters je.status = 'posted' (occurrences: $postedCount)",
    "je.status = 'posted' filter missing or rare"
);

// ─────────────────────────────────────────────────────────────────────────────
section('9. Print layout from updates 178-181 NOT touched');
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
    str_contains($src, 'CASH FLOW STATEMENT'),
    'print-header title "CASH FLOW STATEMENT" preserved',
    'print-header title was removed'
);

check(
    !preg_match("/\\\$c_name\\s*=\\s*getSetting\\(\\s*['\"]company_name['\"]/", $src),
    'no duplicate company-name block in print-header',
    'duplicate company-name block reappeared'
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ Cash Flow Phase 5 contract intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Cash Flow regression — see failures above.\033[0m\n\n";
exit(1);
