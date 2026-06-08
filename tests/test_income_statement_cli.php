<?php
/**
 * Income Statement (Profit & Loss) — Phase 3 Regression Guard
 *
 * Locks in the rewrite of:
 *   - api/account/get_income_statement.php  (data API)
 *   - app/bms/invoice/income_statement.php  (page + JS renderer)
 *
 * The legacy code classified accounts by `accounts.account_type = 'income'`
 * (a column that may not even exist) and patched the expense query with
 * `LIKE '%Salaries%'`. Both are gone. Classification now flows through
 * `account_types.category` (populated by migration
 * 2026_05_27_account_types_classification.php) and `fc_*` helpers from
 * `core/financial_classification.php`.
 *
 * Totals (gross profit, gross margin, net profit, net margin) are computed
 * SERVER-SIDE and returned in the JSON `totals` block — the client only
 * renders. This eliminates the previous risk of "JS errored out → no
 * bottom line shown".
 *
 * Print layout from updates 178-181 must remain untouched. This suite
 * asserts that as well.
 *
 * Run:  php tests/test_income_statement_cli.php
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

echo "\n\033[1m═══ Income Statement — Phase 3 Regression Guard ═══\033[0m\n";

$api  = $root . '/api/account/get_income_statement.php';
$page = $root . '/app/bms/invoice/income_statement.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. Both files exist and pass php -l');
// ─────────────────────────────────────────────────────────────────────────────

foreach ([$api, $page] as $f) {
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $f);
    check(is_file($f), "$rel exists", "$rel missing");

    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    check($code === 0, "$rel passes php -l", "$rel has PHP syntax errors: " . implode(' | ', $out));
}

$apiSrc  = is_file($api)  ? file_get_contents($api)  : '';
$pageSrc = is_file($page) ? file_get_contents($page) : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. API: legacy classification approach is GONE');
// ─────────────────────────────────────────────────────────────────────────────

check(
    !preg_match("/ca\.account_type\s*=\s*['\"]income['\"]/", $apiSrc),
    'API no longer queries accounts.account_type = "income"',
    'API still uses the legacy accounts.account_type = "income" filter'
);

check(
    !preg_match("/ca\.account_type\s*=\s*['\"]expense['\"]/", $apiSrc),
    'API no longer queries accounts.account_type = "expense"',
    'API still uses the legacy accounts.account_type = "expense" filter'
);

check(
    !preg_match("/ca\.account_type\s*=\s*['\"]cost_of_sales['\"]/", $apiSrc),
    'API no longer queries accounts.account_type = "cost_of_sales"',
    'API still uses the legacy account_type = "cost_of_sales" filter'
);

check(
    !preg_match("/account_name\s+LIKE\s+['\"]%Salaries%['\"]/i", $apiSrc),
    'API no longer contains the LIKE "%Salaries%" hack',
    'API still contains the LIKE "%Salaries%" hack — accountant\'s expenses may be wrong'
);

// ─────────────────────────────────────────────────────────────────────────────
section('3. API: uses the canonical classification helper');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($apiSrc, 'core/financial_classification.php'),
    'API requires core/financial_classification.php',
    'API does not load the canonical classification helper'
);

check(
    str_contains($apiSrc, 'fc_type_ids_for_categories'),
    'API uses fc_type_ids_for_categories() to derive type_ids',
    'API does not call fc_type_ids_for_categories — classification not canonical'
);

foreach (['revenue', 'cogs', 'expense'] as $cat) {
    $needle1 = "'$cat'";
    $needle2 = "\"$cat\"";
    $hasCat  = str_contains($apiSrc, $needle1) || str_contains($apiSrc, $needle2);
    check(
        $hasCat && str_contains($apiSrc, 'fc_type_ids_for_categories'),
        "API requests type_ids for category '$cat'",
        "API does not query category '$cat' — that section will be empty"
    );
}

check(
    str_contains($apiSrc, 'fc_unclassified_types'),
    'API surfaces fc_unclassified_types in meta',
    'API does not return unclassified-types warning data'
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. API: SQL filters journal_entries by status = posted');
// ─────────────────────────────────────────────────────────────────────────────

$postedCount = preg_match_all("/je\\.status\\s*=\\s*['\"]posted['\"]/", $apiSrc);
check(
    $postedCount >= 1,
    "API filters je.status = 'posted' (occurrences: $postedCount)",
    "API never filters je.status = 'posted' — drafts could pollute the P&L"
);

check(
    str_contains($apiSrc, 'BETWEEN ? AND ?'),
    'API uses BETWEEN ? AND ? for date filters',
    'API does not use parameterised BETWEEN — period filtering broken'
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. API: server-side totals (no JS computation needed)');
// ─────────────────────────────────────────────────────────────────────────────

foreach (['total_revenue', 'total_cogs', 'total_expenses', 'gross_profit', 'net_profit'] as $key) {
    check(
        str_contains($apiSrc, "'$key'"),
        "API returns '$key' in JSON",
        "API does not return '$key' — client must recompute"
    );
}

foreach (['gross_margin_pct', 'net_margin_pct'] as $key) {
    check(
        str_contains($apiSrc, "'$key'"),
        "API returns '$key' (gross/net margin %)",
        "API does not return $key — margin context missing"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. API: previous-period comparison preserved');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($apiSrc, 'prev_start') || str_contains($apiSrc, 'previous_period'),
    'API computes previous-period data',
    'API no longer returns previous-period comparison'
);

check(
    str_contains($apiSrc, "'previous'") || str_contains($apiSrc, 'previous'),
    'API returns previous totals nested under "previous"',
    'API previous-period totals not nested'
);

// ─────────────────────────────────────────────────────────────────────────────
section('7. API: draft entries warning (accountant guardrail)');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($apiSrc, "status != 'posted'") || str_contains($apiSrc, 'draft_count'),
    'API counts non-posted draft entries in the period for warning banner',
    'API does not warn about draft entries excluded from the report'
);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Page: render handles the new structured response');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($pageSrc, 'data.sections') || str_contains($pageSrc, "sections.revenue"),
    'page reads data.sections.revenue/cogs/expense',
    'page does not consume the new sections structure'
);

check(
    str_contains($pageSrc, 'data.totals') || str_contains($pageSrc, 't.total_revenue'),
    'page reads data.totals server-computed numbers',
    'page does not read server-computed totals'
);

check(
    !preg_match('/data\.revenue_accounts/', $pageSrc),
    'page no longer reads legacy data.revenue_accounts',
    'page still reads legacy data.revenue_accounts — will be empty'
);

check(
    !preg_match("/account_type\s*===?\s*['\"]cost_of_sales['\"]/", $pageSrc),
    'page no longer branches on account_type === "cost_of_sales"',
    'page still branches on legacy account_type field'
);

// ─────────────────────────────────────────────────────────────────────────────
section('9. Page: posting + classification warning banners present');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($pageSrc, 'classificationWarning'),
    'page has #classificationWarning banner',
    'page is missing the unclassified-types warning banner'
);

// ─────────────────────────────────────────────────────────────────────────────
section('10. Print layout from updates 178-181 NOT touched');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match('/@page\s*\{\s*margin:\s*10mm\s+8mm\s+16mm\s+8mm\s*;\s*\}/', $pageSrc),
    'canonical @page margin preserved (10mm 8mm 16mm 8mm)',
    'canonical @page margin was removed'
);

check(
    str_contains($pageSrc, "includes/print_footer_css.php") && str_contains($pageSrc, "includes/print_footer_html.php"),
    'shared print_footer_css/html includes preserved',
    'shared print footer was removed'
);

check(
    str_contains($pageSrc, 'PROFIT OR LOSS STATEMENT'),
    'print-header title "PROFIT OR LOSS STATEMENT" preserved',
    'print-header title was removed'
);

check(
    !preg_match("/\\\$c_name\\s*=\\s*getSetting\\(\\s*['\"]company_name['\"]/", $pageSrc),
    'no duplicate company-name block in print-header',
    'duplicate company-name block reappeared'
);

// ─────────────────────────────────────────────────────────────────────────────
section('11. Professional layout — 4 new subtotals (Option A)');
// ─────────────────────────────────────────────────────────────────────────────
//
// The Income Statement now follows the standard IAS 1 professional layout:
//   Revenue → COGS → Gross Profit → Op Expenses → Operating Profit (EBIT)
//   → Other Income → Finance Costs → Profit Before Tax → Income Tax
//   → Net Profit For Period
//
// Plus an informational Sales Returns row under Revenue (hidden when zero).
// Other Income (supplier credit notes) and Finance Costs hidden when zero.

// API side
foreach (['sales_returns', 'operating_profit', 'operating_margin_pct',
          'other_income', 'finance_costs',
          'income_tax', 'profit_before_tax'] as $key) {
    check(
        str_contains($apiSrc, "'$key'"),
        "API returns '$key' in JSON",
        "API does not return '$key' — page cannot render the new layout"
    );
}

check(
    str_contains($apiSrc, '$sales_returns_current') || str_contains($apiSrc, 'sales_returns_current'),
    'API computes sales_returns_current from sales_returns table',
    'API does not compute sales_returns_current'
);

check(
    str_contains($apiSrc, "SHOW TABLES LIKE 'sales_returns'"),
    'API guards on sales_returns table existence (defensive — degrades to 0 if missing)',
    'API does not guard on sales_returns table — would 500 on servers without it'
);

check(
    (bool) preg_match('/\$operating_profit\s*=\s*\$gp\s*-\s*\$te/', $apiSrc),
    'API computes operating_profit = gross_profit - total_expenses',
    'operating_profit formula incorrect or missing'
);

check(
    (bool) preg_match('/\$np\s*=\s*\$profit_before_tax\s*-\s*\$income_tax/', $apiSrc),
    'API computes net_profit = profit_before_tax - income_tax',
    'net_profit formula does not match the professional layout'
);

// Page side — the 4 new subtotal rows + Sales Returns row
foreach ([
    'OPERATING PROFIT (EBIT)' => 'EBIT subtotal label present',
    'PROFIT BEFORE TAX'       => 'Profit Before Tax label present',
    'NET PROFIT FOR PERIOD'   => 'Net Profit For Period label present (renamed from "NET PROFIT/(LOSS)")',
    'Income Tax (provision)'  => 'Income Tax provision line label present',
    'Sales returns processed' => 'Sales Returns informational row label present',
] as $needle => $desc) {
    check(
        str_contains($pageSrc, $needle),
        $desc,
        "page is missing '$needle' label"
    );
}

// Page-side JS must populate every new ID
foreach (['#operatingProfit', '#operatingProfitPrev',
          '#incomeTax', '#incomeTaxPrev',
          '#profitBeforeTax', '#profitBeforeTaxPrev',
          '#salesReturnsCurrent', '#salesReturnsPrev',
          '#salesReturnsRow', '#operatingMarginLabel'] as $id) {
    check(
        str_contains($pageSrc, $id),
        "JS populates / toggles $id",
        "$id is missing — corresponding line will render empty"
    );
}

// Sales Returns row must be hidden by default (d-none) — only shown when non-zero
check(
    (bool) preg_match('/<tr[^>]*id="salesReturnsRow"[^>]*class="[^"]*d-none/', $pageSrc) ||
    (bool) preg_match('/<tr[^>]*class="[^"]*d-none[^"]*"[^>]*id="salesReturnsRow"/', $pageSrc),
    'Sales Returns row starts hidden (d-none) — only revealed when amount > 0',
    'Sales Returns row is always visible — should be d-none by default'
);

// ─────────────────────────────────────────────────────────────────────────────
section('12. API: POS / Counter Sales wired into Revenue + COGS');
// ─────────────────────────────────────────────────────────────────────────────
//
// POS sales live in pos_sales / pos_sale_items, never in `invoices`, so without
// dedicated closures every counter sale (and its cost) was missing from the P&L.
// Recognition mirrors the invoice rule and guards against double-counting POS
// sales already converted to an invoice (invoice_id) and return-sales.

check(
    str_contains($apiSrc, '$sumPosSales'),
    'API defines $sumPosSales (POS net revenue closure)',
    'API does not compute POS / Counter Sales revenue — counter sales missing from P&L'
);

check(
    str_contains($apiSrc, '$sumPosCOGS'),
    'API defines $sumPosCOGS (POS cost-of-sales closure)',
    'API does not compute POS COGS — gross profit overstated for counter sales'
);

check(
    str_contains($apiSrc, "SHOW TABLES LIKE 'pos_sales'"),
    'API guards on pos_sales table existence (degrades to 0 if POS not installed)',
    'API does not guard on pos_sales table — would 500 on servers without the POS module'
);

check(
    str_contains($apiSrc, "sale_status IN ('completed','partially_refunded')"),
    'API recognises only completed/partially_refunded POS sales',
    'API does not restrict POS recognition to completed sales'
);

check(
    str_contains($apiSrc, 'invoice_id IS NULL') && str_contains($apiSrc, 'is_return_sale = 0'),
    'API double-count guards present (invoice_id IS NULL + is_return_sale = 0)',
    'API missing POS double-count / return-sale guards'
);

check(
    str_contains($apiSrc, "'POS / Counter Sales'"),
    'API emits the "POS / Counter Sales" revenue line',
    'API does not emit a POS revenue line'
);

check(
    (bool) preg_match('/\$total_revenue_cur\s*=.*\$rev_pos_cur/', $apiSrc),
    'API adds POS revenue into total_revenue_cur',
    'POS revenue is computed but not added to total revenue'
);

check(
    (bool) preg_match('/\$total_cogs_cur\s*=.*\$cogs_pos_cur/', $apiSrc),
    'API adds POS COGS into total_cogs_cur',
    'POS COGS is computed but not added to total COGS'
);

// Drill-down sources must exist so the new lines are clickable
$detail    = $root . '/api/account/get_income_statement_detail.php';
$detailSrc = is_file($detail) ? file_get_contents($detail) : '';
check(
    str_contains($detailSrc, "case 'pos_sales':") && str_contains($detailSrc, "case 'pos_cogs':"),
    'detail endpoint handles pos_sales + pos_cogs drill sources',
    'detail endpoint missing POS drill-down cases — new lines not clickable'
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ Income Statement Phase 3 contract intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Income Statement regression — see failures above.\033[0m\n\n";
exit(1);
