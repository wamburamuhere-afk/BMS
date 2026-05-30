<?php
/**
 * General Ledger — Phase 6 Regression Guard
 *
 * Locks in the rewrite of app/constant/reports/ledger_report.php:
 *
 *   1. Opening balance double-count is GONE — the previous code added
 *      both historical posted entries AND accounts.opening_balance,
 *      double-counting whenever the column was already seeded as an
 *      opening journal entry on day-1.
 *   2. Filters je.status = 'posted' (both opening calc and period query)
 *   3. Single-account view now shows a running balance column with
 *      Dr/Cr labels via gl_balance_label()
 *   4. No-account view shows a per-account summary table (opening,
 *      debits, credits, closing) — not a dump of every transaction
 *   5. Loaded core/financial_classification.php for at.category /
 *      at.normal_side fetched alongside accounts
 *
 * Print layout (header / footer / @page from updates 178-181) NOT touched.
 *
 * Run:  php tests/test_general_ledger_cli.php
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

echo "\n\033[1m═══ General Ledger — Phase 6 Regression Guard ═══\033[0m\n";

$file = $root . '/app/constant/reports/ledger_report.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. File exists and is syntactically valid PHP');
// ─────────────────────────────────────────────────────────────────────────────

check(is_file($file), 'ledger_report.php exists', 'ledger_report.php missing');
$out = []; $code = 0;
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
check($code === 0, 'passes php -l', 'has PHP syntax errors: ' . implode(' | ', $out));

$src = is_file($file) ? file_get_contents($file) : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. Opening balance INCLUDES accounts.opening_balance (ties to TB/BS/API)');
// ─────────────────────────────────────────────────────────────────────────────

// BMS stores opening balances in the accounts.opening_balance column (not as
// opening journal entries). The GL must fold that column into its brought-
// forward figure — exactly like the GL API, the Trial Balance and the Balance
// Sheet — otherwise accounts whose balance is a pure opening (no journal
// movement) vanish and the four reports disagree.
check(
    (bool) preg_match('/a\.opening_balance/', $src),
    'incorporates accounts.opening_balance so the GL ties to TB/BS/API',
    'GL ignores accounts.opening_balance — disagrees with TB/BS/API and hides opening-only accounts'
);

// Guard against the OLD double-count pattern returning (adding the column via a
// separate $acc_info_stmt query on top of a journal-derived opening).
check(
    !preg_match('/\$opening_balance\s*\+=\s*floatval\s*\(\s*\$acc_info_stmt/', $src),
    'does not reintroduce the legacy $acc_info_stmt double-count',
    'legacy double-count pattern reappeared'
);

// ─────────────────────────────────────────────────────────────────────────────
section('3. Loads canonical classification helper');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, 'core/financial_classification.php'),
    'requires core/financial_classification.php',
    'classification helper not loaded — Dr/Cr labels cannot be computed'
);

check(
    str_contains($src, 'at.category') || str_contains($src, 'at.normal_side'),
    'SQL pulls at.category and/or at.normal_side',
    'SQL does not pull category/normal_side from account_types — Dr/Cr labels broken'
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Helper function gl_balance_label() defined');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match('/function\s+gl_balance_label\s*\(/i', $src),
    'gl_balance_label() function declared',
    'gl_balance_label() function missing — Dr/Cr suffix not rendered'
);

check(
    str_contains($src, "'Dr'") && str_contains($src, "'Cr'"),
    'gl_balance_label() returns Dr/Cr suffix',
    'Dr/Cr suffixes not present in helper'
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. Filters journal_entries by status = posted');
// ─────────────────────────────────────────────────────────────────────────────

$postedCount = preg_match_all("/je\\.status\\s*=\\s*['\"]posted['\"]/", $src);
check(
    $postedCount >= 3,
    "filters je.status = 'posted' (found $postedCount occurrences)",
    "je.status = 'posted' filter is missing or rare"
);

check(
    (bool) preg_match("/AND\\s+je\\.entry_date\\s*<\\s*\\?/", $src),
    "opening balance filters je.entry_date < ? (STRICTLY before start_date)",
    "opening balance has no je.entry_date < ? filter — wrong opening cutoff"
);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Single-account view: running balance column');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, '$running_balance'),
    '$running_balance variable computed per row',
    '$running_balance not tracked — column would always show 0'
);

check(
    str_contains($src, 'Opening Balance Brought Forward'),
    'opening-balance row labelled "Opening Balance Brought Forward"',
    'opening-balance row label missing or non-canonical'
);

check(
    (bool) preg_match('/gl_balance_label\s*\(\s*\$opening_balance/', $src),
    'opening balance rendered via gl_balance_label() (with Dr/Cr)',
    'opening balance not using gl_balance_label() — no Dr/Cr suffix'
);

check(
    (bool) preg_match('/gl_balance_label\s*\(\s*\$running_balance/', $src),
    'running balance rendered via gl_balance_label() (with Dr/Cr)',
    'running balance not using gl_balance_label()'
);

check(
    (bool) preg_match('/gl_balance_label\s*\(\s*\$closing_balance/', $src),
    'closing balance rendered via gl_balance_label() (with Dr/Cr)',
    'closing balance not using gl_balance_label()'
);

// ─────────────────────────────────────────────────────────────────────────────
section('7. No-account view: per-account summary (not transaction dump)');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($src, '$summary_rows'),
    '$summary_rows array built when no account_id given',
    'no per-account summary built — would still dump every transaction'
);

check(
    str_contains($src, 'category') && str_contains($src, 'Account'),
    'summary table shows Account + Type/Category columns',
    'summary table missing required columns'
);

check(
    (bool) preg_match("/Opening[\\s\\S]{0,50}Debits[\\s\\S]{0,50}Credits[\\s\\S]{0,50}Closing/i", $src),
    'summary table has Opening / Debits / Credits / Closing column headers',
    'summary table column structure does not match accountant convention'
);

// Drill-down link from summary row → single-account view
check(
    (bool) preg_match('/\?start_date=[^&]*&end_date=[^&]*&account_id=/', $src),
    'summary rows link to single-account view (drill-down)',
    'no drill-down link from summary table to per-account ledger'
);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Print layout from updates 178-181 NOT touched');
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
    str_contains($src, 'GENERAL LEDGER REPORT'),
    'print-header title "GENERAL LEDGER REPORT" preserved',
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
    echo "\033[32m✅ General Ledger Phase 6 contract intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ General Ledger regression — see failures above.\033[0m\n\n";
exit(1);
