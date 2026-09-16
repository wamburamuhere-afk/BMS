<?php
/**
 * Dashboard "Monthly Expenses" card (Simple POS) — CLI regression suite
 *   php tests/test_dashboard_monthly_expenses_card_cli.php
 *
 * Background: the user asked for a dashboard card showing this month's
 * recognized expense spend, clickable straight through to expenses.php
 * pre-filtered to that month. Half the plumbing already existed unused
 * (get_business_stats()'s $dashboard_stats['expenses'] was computed but
 * never rendered) — this adds a dedicated, always-current-calendar-month
 * figure (independent of whatever time_range the rest of the dashboard is
 * filtered to) plus the click-through, gated to Simple POS like the
 * Credit/Madeni card next to it.
 *
 *   A. STATIC   — touched files lint clean.
 *   B. WIRING   — dashboard.php computes the figures only in the Simple Mode
 *                 branch and always uses the current calendar month (not
 *                 $start_date/$end_date); card markup gated correctly; link
 *                 carries date_from/date_to.
 *   C. RUNTIME  — the exact query, run as a real admin session, matches a
 *                 raw unscoped COUNT/SUM for the current month exactly.
 *   D. DEEP LINK — expenses.php's date_from/date_to whitelist accepts real
 *                 Y-m-d dates only (rejects impossible calendar dates,
 *                 wrong formats, and injection-shaped strings), and its
 *                 output actually lands in the <input value="..."> the
 *                 client-side filters_js() reads at init.
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
foreach (['app/dashboard.php', 'app/constant/accounts/expenses.php'] as $f) {
    $full = "$root/$f";
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($full) . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$dash = src($root, 'app/dashboard.php');
has($dash, "\$pos_month_start = date('Y-m-01');", 'always the current calendar month (not the dashboard time_range filter)');
has($dash, "\$pos_month_end   = date('Y-m-t');", 'month end computed correctly');
has($dash, "AND e.expense_date BETWEEN :from AND :to", 'query scoped to the computed month range');
has($dash, "WHERE e.status IN ('approved','paid')", 'same recognized-spend gate as the rest of the chart/notice');
has($dash, "if (\$pos_simple_mode && canView('expenses')):", "card gated on Simple Mode + the user's own expenses permission");
has($dash, "getUrl('expenses') ?>?date_from=<?= urlencode(\$pos_month_start) ?>&date_to=<?= urlencode(\$pos_month_end) ?>", 'click-through carries date_from/date_to');

$expPage = src($root, 'app/constant/accounts/expenses.php');
has($expPage, "\$exp_valid_ymd = function (\$v) {", 'expenses.php validates date_from/date_to as real calendar dates');
has($expPage, 'checkdate($m, $d, $y)', 'uses checkdate() — rejects impossible dates like Feb 30, not just format');
has($expPage, 'id="dateFromFilter" value="<?= htmlspecialchars($exp_date_from_qs) ?>"', 'validated value reaches the Date From input');
has($expPage, 'id="dateToFilter" value="<?= htmlspecialchars($exp_date_to_qs) ?>"', 'validated value reaches the Date To input');

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — the exact query, as a real admin session');
require_once "$root/core/permissions.php";
require_once "$root/core/warehouse_scope.php";
require_once "$root/core/pos_nav.php";

$_SESSION['user_id']  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$_SESSION['role_id']  = 1;
$_SESSION['is_admin'] = true;

$from = date('Y-m-01');
$to   = date('Y-m-t');

$expWhScope   = scopeFilterSqlNullable('warehouse', 'e');
$expProjScope = scopeFilterSqlNullable('project', 'e');
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS amt
    FROM expenses e
    WHERE e.status IN ('approved','paid')
      AND e.expense_date BETWEEN :from AND :to
      $expWhScope $expProjScope
");
$stmt->execute(['from' => $from, 'to' => $to]);
$scoped = $stmt->fetch(PDO::FETCH_ASSOC);

$raw = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS amt FROM expenses WHERE status IN ('approved','paid') AND expense_date BETWEEN ? AND ?");
$raw->execute([$from, $to]);
$rawRow = $raw->fetch(PDO::FETCH_ASSOC);

((int)$scoped['c'] === (int)$rawRow['c'] && abs((float)$scoped['amt'] - (float)$rawRow['amt']) < 0.01)
    ? pass("Admin-scoped this-month figure ({$scoped['c']}, {$scoped['amt']}) matches raw total ({$rawRow['c']}, {$rawRow['amt']})")
    : fail("Admin-scoped ({$scoped['c']}, {$scoped['amt']}) != raw total ({$rawRow['c']}, {$rawRow['amt']})");

is_numeric($scoped['c']) && is_numeric($scoped['amt'])
    ? pass('Query returns numeric count + amount (safe for number_format())')
    : fail('Query returned a non-numeric value');

// ─────────────────────────────────────────────────────────────────────────
section('4. Deep-link date whitelist (pure logic, mirrors expenses.php)');
function whitelistYmd($v) {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return '';
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : '';
}
$cases = [
    ['2026-09-01', '2026-09-01'],
    ['2026-09-30', '2026-09-30'],
    ['2026-02-30', ''],           // impossible calendar date
    ['2026-13-01', ''],           // impossible month
    ['not-a-date', ''],
    ['<script>alert(1)</script>', ''],
    ['2026-9-1', ''],             // must be zero-padded
    ['', ''],
    [null, ''],
];
foreach ($cases as [$in, $expect]) {
    $got = whitelistYmd($in ?? '');
    $got === $expect
        ? pass('date=' . var_export($in, true) . ' -> ' . var_export($got, true))
        : fail('date=' . var_export($in, true) . " expected " . var_export($expect, true) . " got " . var_export($got, true));
}

// A malicious value must never reach the HTML attribute unescaped even
// though the whitelist already blocks it — belt and braces.
$xss = '"><script>alert(1)</script>';
$safe = whitelistYmd($xss);
$rendered = 'value="' . htmlspecialchars($safe) . '"';
(strpos($rendered, '<script>') === false)
    ? pass('XSS-shaped date_from never reaches the rendered <input> unescaped')
    : fail('XSS-shaped date_from leaked into rendered HTML');
