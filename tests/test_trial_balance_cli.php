<?php
/**
 * Trial Balance — Professional Layout Regression Guard
 *
 * Locks in the Phase 2 rewrite of app/constant/accounts/trial_balance.php:
 *
 *   1. Uses classification metadata from account_types (category,
 *      normal_side) — populated by migration
 *      2026_05_27_account_types_classification.php
 *   2. Uses fc_balance() from core/financial_classification.php
 *   3. Sectioned layout (Assets → Liabilities → Equity → Revenue → Expenses
 *      → COGS) with subtotals per section
 *   4. Balance-check banner (success / danger) at the top
 *   5. Contra-balance flagging
 *   6. Unclassified-types warning banner
 *   7. Filters journal entries by status = 'posted' (in JOIN, not WHERE,
 *      so accounts with zero entries still appear if active)
 *
 * Does NOT touch the canonical print header/footer/@page work from
 * updates 178-180. Asserts those remain in place.
 *
 * Run:  php tests/test_trial_balance_cli.php
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

echo "\n\033[1m═══ Trial Balance — Phase 2 Regression Guard ═══\033[0m\n";

$file = $root . '/app/constant/accounts/trial_balance.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. File exists and is syntactically valid PHP');
// ─────────────────────────────────────────────────────────────────────────────

check(is_file($file), 'trial_balance.php exists', 'trial_balance.php missing');
$out = []; $code = 0;
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
check($code === 0, 'passes php -l', 'has PHP syntax errors: ' . implode(' | ', $out));

$src = is_file($file) ? file_get_contents($file) : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. Uses the new classification helper');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, "core/financial_classification.php"),
    'requires core/financial_classification.php',
    'does not require core/financial_classification.php — classification helper not loaded'
);

check(
    str_contains($src, 'fc_balance(') || str_contains($src, 'fc_natural_sign('),
    'uses fc_balance() or fc_natural_sign() from the helper',
    'never calls the canonical fc_balance/fc_natural_sign helper'
);

check(
    str_contains($src, 'fc_unclassified_types('),
    'calls fc_unclassified_types() to surface unclassified types',
    'does not surface unclassified account_types — accountant gets no warning'
);

// ─────────────────────────────────────────────────────────────────────────────
section('3. SQL pulls classification metadata');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match('/at\.category/', $src),
    'SQL selects at.category',
    'SQL does not select category from account_types — sections cannot be built'
);

check(
    (bool) preg_match('/at\.normal_side/', $src),
    'SQL selects at.normal_side',
    'SQL does not select normal_side — natural-side presentation impossible'
);

check(
    (bool) preg_match('/JOIN\s+account_types\s+at\s+ON\s+a\.account_type_id\s*=\s*at\.type_id/i', $src),
    'SQL joins account_types via account_type_id',
    'SQL no longer joins account_types correctly'
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Filters journal_entries by status = posted');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match("/je\.status\s*=\s*'posted'/", $src),
    "filters je.status = 'posted'",
    "filters je.status = 'posted' is missing — drafts would pollute the TB"
);

check(
    (bool) preg_match("/AND\s+je\.entry_date\s*<=\s*\?/i", $src),
    "filters je.entry_date <= ? (in JOIN, not WHERE)",
    "as-of date filter on je.entry_date missing"
);

// Old logic used "(je.status = 'posted' OR je.status IS NULL)" which is
// brittle. New code moves the filter into the JOIN so dormant accounts
// still pass through without polluting the totals.
check(
    !preg_match("/je\.status\s*=\s*'posted'\s+OR\s+je\.status\s+IS\s+NULL/", $src),
    'no longer uses brittle (je.status = posted OR je.status IS NULL) WHERE clause',
    'still uses the legacy (status = posted OR IS NULL) WHERE — should be in JOIN now'
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. Sectioned layout with section labels for all 6 categories');
// ─────────────────────────────────────────────────────────────────────────────

foreach (['ASSETS', 'LIABILITIES', 'EQUITY', 'REVENUE', 'EXPENSES', 'COST OF GOODS SOLD'] as $label) {
    check(
        str_contains($src, "'$label'"),
        "section label '$label' defined",
        "section label '$label' missing — that section won't appear in the output"
    );
}

check(
    (bool) preg_match('/\$SECTION_ORDER\s*=\s*\[\s*[\'"]asset[\'"]\s*,\s*[\'"]liability[\'"]\s*,\s*[\'"]equity[\'"]\s*,\s*[\'"]revenue[\'"]\s*,\s*[\'"]expense[\'"]\s*,\s*[\'"]cogs[\'"]\s*\]/', $src),
    'SECTION_ORDER follows accountant convention (asset → liability → equity → revenue → expense → cogs)',
    'SECTION_ORDER is missing or in the wrong sequence'
);

check(
    (bool) preg_match('/subtotal_debit/', $src) && (bool) preg_match('/subtotal_credit/', $src),
    'computes per-section subtotal_debit and subtotal_credit',
    'per-section subtotals are missing'
);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Balance-check banner present (success + failure variants)');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, 'TRIAL BALANCE IS BALANCED'),
    'success banner text present',
    'success "BALANCED" banner missing'
);

check(
    str_contains($src, 'TRIAL BALANCE DOES NOT BALANCE'),
    'failure banner text present',
    'failure "DOES NOT BALANCE" banner missing'
);

check(
    str_contains($src, '$is_balanced'),
    '$is_balanced variable computed',
    '$is_balanced flag never computed'
);

// ─────────────────────────────────────────────────────────────────────────────
section('7. Contra-balance + unclassified-account flags');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, 'is_contra'),
    'is_contra flag computed per row',
    'is_contra flag missing — contra-balances cannot be highlighted'
);

check(
    str_contains($src, 'contra_count'),
    '$contra_count counter present (drives "N accounts show contra-balances" banner)',
    'contra-balance counter missing'
);

check(
    str_contains($src, 'unclassified_rows') || str_contains($src, 'UNCLASSIFIED'),
    'unclassified rows handled separately',
    'unclassified rows handling missing — uncategorised accounts would silently vanish'
);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Print layout from updates 178-180 is NOT touched');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match('/@page\s*\{\s*margin:\s*10mm\s+8mm\s+16mm\s+8mm\s*;\s*\}/', $src),
    'canonical @page margin preserved (10mm 8mm 16mm 8mm)',
    'canonical @page margin was removed — print spec broken'
);

check(
    str_contains($src, "includes/print_footer_css.php") && str_contains($src, "includes/print_footer_html.php"),
    'shared print_footer_css/html includes preserved',
    'shared print footer includes were removed'
);

check(
    str_contains($src, 'TRIAL BALANCE REPORT'),
    'print-header report title preserved',
    'print-header report title was removed'
);

check(
    (bool) preg_match('/<div\s+class="d-none\s+d-print-block">[\s\S]*?print_footer_html\.php/i', $src),
    'print_footer_html.php still wrapped in d-none d-print-block',
    'print footer is no longer wrapped properly'
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
    echo "\033[32m✅ Trial Balance professional layout intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Trial Balance regression — see failures above.\033[0m\n\n";
exit(1);
