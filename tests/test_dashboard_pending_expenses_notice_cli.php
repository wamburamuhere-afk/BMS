<?php
/**
 * Dashboard Simple-Mode "pending expenses not yet on the chart" notice —
 * CLI regression suite
 *   php tests/test_dashboard_pending_expenses_notice_cli.php
 *
 * Background: in Simple POS mode, the "Bought vs Sold" chart's Expenses line
 * (core/pos_dashboard_metrics.php::posSimpleBuySellSeries()) and the
 * dashboard's own Expenses stat card both only sum expenses.status IN
 * ('approved','paid') — per .claude/reporting-source.md, only recognized
 * spend counts. A shop owner who creates an expense that stays Pending sees
 * it nowhere on the chart, with no explanation. app/dashboard.php now shows
 * a small notice with the Pending count/amount and a deep link to
 * expenses.php?status=pending, when running in Simple Mode.
 *
 *   A. STATIC   — touched files lint clean.
 *   B. WIRING   — dashboard.php computes the pending figures only inside the
 *                 Simple Mode branch, and the notice markup is gated on it.
 *   C. RUNTIME  — the exact query dashboard.php runs, executed here as a real
 *                 admin session, matches a raw unscoped COUNT/SUM (admins get
 *                 no scope restriction) and is consistent with the scoped
 *                 join glProfitLoss/posSimpleBuySellSeries would apply.
 *   D. DEEP LINK — expenses.php's ?status= query-string whitelist accepts the
 *                 5 real statuses and safely default to "All" for anything
 *                 else (defends the pre-selected <option> against injection).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
foreach (['app/dashboard.php', 'app/constant/accounts/expenses.php', 'lang/sw.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($full) . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$dash = src($root, 'app/dashboard.php');
has($dash, "\$pos_pending_expense_count  = 0;", 'dashboard.php declares pending-expense figures');
has($dash, "if (\$pos_simple_mode) {", 'computed only inside the Simple Mode branch');
has($dash, "WHERE e.status = 'pending' \$expWhScope \$expProjScope", 'query matches posSimpleBuySellSeries()\'s own status/scope gate');
has($dash, "if (\$pos_simple_mode && \$pos_pending_expense_count > 0):", 'notice markup gated on Simple Mode + a non-zero count');
has($dash, "getUrl('expenses') ?>?status=pending", 'notice deep-links to expenses.php?status=pending');

$expPage = src($root, 'app/constant/accounts/expenses.php');
has($expPage, "\$exp_status_qs = \$_GET['status'] ?? '';", 'expenses.php reads ?status= for deep-linking');
has($expPage, "in_array(\$exp_status_qs, \$exp_valid_statuses, true)", 'whitelists against the real status set (no raw echo of user input)');

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — the exact query, as a real admin session');
require_once "$root/core/permissions.php";
require_once "$root/core/warehouse_scope.php";
require_once "$root/core/pos_nav.php";

$_SESSION['user_id']  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$_SESSION['role_id']  = 1;
$_SESSION['is_admin'] = true;

if (!isAdmin()) {
    fail('Could not establish an admin session — cannot verify scope behaviour');
} else {
    pass('Admin session established (user_id=' . $_SESSION['user_id'] . ')');

    $expWhScope   = scopeFilterSqlNullable('warehouse', 'e');
    $expProjScope = scopeFilterSqlNullable('project', 'e');
    ($expWhScope === '' && $expProjScope === '')
        ? pass('Admin gets an unrestricted scope clause (both empty)')
        : fail("Admin scope not empty: wh=[$expWhScope] proj=[$expProjScope]");

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt FROM expenses e WHERE e.status = 'pending' $expWhScope $expProjScope");
    $stmt->execute();
    $scoped = $stmt->fetch(PDO::FETCH_ASSOC);

    $raw = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt FROM expenses WHERE status = 'pending'")->fetch(PDO::FETCH_ASSOC);

    ((int)$scoped['c'] === (int)$raw['c'] && abs((float)$scoped['amt'] - (float)$raw['amt']) < 0.01)
        ? pass("Admin-scoped pending count/amount ({$scoped['c']}, {$scoped['amt']}) matches raw total ({$raw['c']}, {$raw['amt']})")
        : fail("Admin-scoped ({$scoped['c']}, {$scoped['amt']}) != raw total ({$raw['c']}, {$raw['amt']})");

    is_numeric($scoped['c']) && is_numeric($scoped['amt'])
        ? pass('Query returns numeric count + amount (safe for sprintf/format_currency)')
        : fail('Query returned a non-numeric value');
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Deep-link status whitelist (pure logic, mirrors expenses.php)');
function whitelistStatus($qs) {
    $valid = ['pending', 'reviewed', 'approved', 'rejected', 'paid'];
    return in_array($qs, $valid, true) ? $qs : '';
}
$cases = [
    ['pending', 'pending'],
    ['paid', 'paid'],
    ['PENDING', ''],          // case-sensitive — must not silently "half match"
    ["1' OR '1'='1", ''],     // injection attempt — must fall through to blank
    ['', ''],
    [null, ''],
];
foreach ($cases as [$in, $expect]) {
    $got = whitelistStatus($in ?? '');
    $got === $expect
        ? pass('status=' . var_export($in, true) . ' -> ' . var_export($got, true))
        : fail('status=' . var_export($in, true) . " expected " . var_export($expect, true) . " got " . var_export($got, true));
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Translation coverage');
require_once "$root/core/i18n.php";
loadLanguage('sw');
$key = '%d expense(s) totaling %s are still Pending and not yet counted in the Expenses line below — approve or mark them Paid to include them.';
$sw = t($key);
($sw !== $key) ? pass('Notice string has a Swahili translation') : fail('Notice string falls back to raw English key under sw locale');
(t('Review now') !== 'Review now') ? pass('"Review now" has a Swahili translation') : fail('"Review now" has no Swahili translation');
