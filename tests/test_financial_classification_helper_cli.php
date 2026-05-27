<?php
/**
 * core/financial_classification.php — Helper Contract Guard
 *
 * Locks in the function-signature contract for the helper that backs every
 * one of the 5 financial reports. The helper itself is pure (no DB writes,
 * no side effects), so this suite verifies:
 *
 *   1. File exists and is syntactically valid PHP
 *   2. All required helper functions are declared
 *   3. Pure functions (no DB) return the canonical accounting taxonomy
 *   4. fc_natural_sign() and fc_balance() behave per accounting rules
 *
 * No DB connection is opened — that's the whole point. The fail-fast tests
 * here run on every push and never touch live data.
 *
 * Run:  php tests/test_financial_classification_helper_cli.php
 *   Exit 0 = all pass (safe to commit / push)
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

echo "\n\033[1m═══ financial_classification.php — Helper Contract Guard ═══\033[0m\n";

$helper = $root . '/core/financial_classification.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. File exists and is syntactically valid PHP');
// ─────────────────────────────────────────────────────────────────────────────

check(is_file($helper), 'helper file exists', 'helper file missing');

$out = []; $code = 0;
exec('php -l ' . escapeshellarg($helper) . ' 2>&1', $out, $code);
check($code === 0, 'passes php -l', 'has PHP syntax errors: ' . implode(' | ', $out));

// Load the helper. It's pure and uses function_exists guards, so we can
// safely include it inside the test without touching any DB.
require_once $helper;

// ─────────────────────────────────────────────────────────────────────────────
section('2. All required helper functions declared');
// ─────────────────────────────────────────────────────────────────────────────

$required = [
    'fc_categories',
    'fc_cash_flow_categories',
    'fc_income_statement_categories',
    'fc_balance_sheet_categories',
    'fc_type_ids_for_categories',
    'fc_type_ids_for_cash_flow_category',
    'fc_all_types',
    'fc_unclassified_types',
    'fc_natural_sign',
    'fc_balance',
];

foreach ($required as $fn) {
    check(
        function_exists($fn),
        "function $fn() declared",
        "function $fn() is missing — every financial report depends on it"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Canonical taxonomy (pure functions — no DB)');
// ─────────────────────────────────────────────────────────────────────────────

$cats = fc_categories();
check(
    is_array($cats) && count($cats) === 6,
    'fc_categories() returns 6 categories',
    'fc_categories() count is wrong (expected 6, got ' . count($cats) . ')'
);
foreach (['asset', 'liability', 'equity', 'revenue', 'expense', 'cogs'] as $c) {
    check(
        in_array($c, $cats, true),
        "fc_categories() contains '$c'",
        "fc_categories() is missing canonical category '$c'"
    );
}

$cfCats = fc_cash_flow_categories();
check(
    is_array($cfCats) && count($cfCats) === 5,
    'fc_cash_flow_categories() returns 5 categories',
    'fc_cash_flow_categories() count is wrong (expected 5, got ' . count($cfCats) . ')'
);
foreach (['operating', 'investing', 'financing', 'cash', 'none'] as $c) {
    check(
        in_array($c, $cfCats, true),
        "fc_cash_flow_categories() contains '$c'",
        "fc_cash_flow_categories() is missing '$c'"
    );
}

$isCats = fc_income_statement_categories();
check(
    is_array($isCats) && count($isCats) === 3,
    'fc_income_statement_categories() returns 3 categories',
    'fc_income_statement_categories() count is wrong'
);
foreach (['revenue', 'expense', 'cogs'] as $c) {
    check(
        in_array($c, $isCats, true),
        "fc_income_statement_categories() contains '$c'",
        "fc_income_statement_categories() missing '$c'"
    );
}

$bsCats = fc_balance_sheet_categories();
check(
    is_array($bsCats) && count($bsCats) === 3,
    'fc_balance_sheet_categories() returns 3 categories',
    'fc_balance_sheet_categories() count is wrong'
);
foreach (['asset', 'liability', 'equity'] as $c) {
    check(
        in_array($c, $bsCats, true),
        "fc_balance_sheet_categories() contains '$c'",
        "fc_balance_sheet_categories() missing '$c'"
    );
}

// IS + BS categories MUST partition the full taxonomy with no overlap
$union = array_merge($isCats, $bsCats);
sort($union);
$expected = ['asset', 'cogs', 'equity', 'expense', 'liability', 'revenue'];
check(
    $union === $expected,
    'IS + BS categories partition the full 6-category taxonomy with no overlap',
    'IS + BS categories overlap or miss one — expected ' . json_encode($expected) . ', got ' . json_encode($union)
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. fc_natural_sign() — accounting direction rules');
// ─────────────────────────────────────────────────────────────────────────────

// Debit-natural: asset, expense, cogs → +1
foreach (['asset', 'expense', 'cogs'] as $c) {
    check(
        fc_natural_sign($c) === 1,
        "fc_natural_sign('$c') == +1 (debit-natural)",
        "fc_natural_sign('$c') wrong — should be +1, got " . fc_natural_sign($c)
    );
}

// Credit-natural: liability, equity, revenue → -1
foreach (['liability', 'equity', 'revenue'] as $c) {
    check(
        fc_natural_sign($c) === -1,
        "fc_natural_sign('$c') == -1 (credit-natural)",
        "fc_natural_sign('$c') wrong — should be -1, got " . fc_natural_sign($c)
    );
}

// Unknown / NULL → 0
check(
    fc_natural_sign('unknown') === 0,
    "fc_natural_sign('unknown') == 0",
    "fc_natural_sign('unknown') should return 0"
);

// Case-insensitive
check(
    fc_natural_sign('ASSET') === 1,
    "fc_natural_sign('ASSET') == +1 (case-insensitive)",
    "fc_natural_sign() should be case-insensitive"
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. fc_balance() — debit-natural and credit-natural arithmetic');
// ─────────────────────────────────────────────────────────────────────────────

// Asset with 1000 debits, 300 credits → balance = +(1000-300) = +700
check(
    abs(fc_balance('asset', 1000, 300) - 700) < 0.001,
    "fc_balance('asset', 1000, 300) == 700",
    "fc_balance('asset', 1000, 300) wrong — got " . fc_balance('asset', 1000, 300)
);

// Liability with 200 debits, 1500 credits → balance = -(200-1500) = +1300
check(
    abs(fc_balance('liability', 200, 1500) - 1300) < 0.001,
    "fc_balance('liability', 200, 1500) == 1300",
    "fc_balance('liability', 200, 1500) wrong — got " . fc_balance('liability', 200, 1500)
);

// Revenue with 0 debits, 5000 credits → balance = -(0-5000) = +5000
check(
    abs(fc_balance('revenue', 0, 5000) - 5000) < 0.001,
    "fc_balance('revenue', 0, 5000) == 5000",
    "fc_balance('revenue', 0, 5000) wrong"
);

// Expense with 800 debits, 0 credits → balance = +(800-0) = +800
check(
    abs(fc_balance('expense', 800, 0) - 800) < 0.001,
    "fc_balance('expense', 800, 0) == 800",
    "fc_balance('expense', 800, 0) wrong"
);

// Contra-balance: asset with more credits than debits returns NEGATIVE
// (the accountant should see this as an anomaly — e.g., overdrawn bank)
check(
    fc_balance('asset', 100, 500) < 0,
    "fc_balance('asset', 100, 500) is NEGATIVE (anomaly — overdrawn asset)",
    "fc_balance() doesn't flag contra-balance as negative"
);

// Equity with more debits than credits returns NEGATIVE too
check(
    fc_balance('equity', 5000, 1000) < 0,
    "fc_balance('equity', 5000, 1000) is NEGATIVE (anomaly — equity in deficit)",
    "fc_balance() doesn't flag equity deficit"
);

// Zero debits / credits → 0
check(
    fc_balance('asset', 0, 0) === 0.0,
    "fc_balance('asset', 0, 0) == 0",
    "fc_balance() with zero inputs not zero"
);

// Unknown category → 0 (defensive)
check(
    fc_balance('unknown', 1000, 500) === 0.0,
    "fc_balance('unknown', 1000, 500) == 0 (defensive)",
    "fc_balance() with unknown category should return 0"
);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Re-include safety (function_exists guard)');
// ─────────────────────────────────────────────────────────────────────────────

// The helper uses if (!function_exists('fc_categories')) to wrap everything,
// so re-including it must not cause a "Cannot redeclare" fatal.
$reInclude = require $helper;
check(
    true,
    'helper can be re-required without fatal "Cannot redeclare"',
    'helper threw on re-include'
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ financial_classification helper contract intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Helper contract regression — see failures above.\033[0m\n\n";
exit(1);
