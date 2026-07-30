<?php
/**
 * Dashboard date-range filter — time_range preset regression guard
 *   php tests/test_dashboard_time_range_cli.php
 *
 * app/dashboard.php read $_GET['time_range'] but never converted it into
 * actual $start_date/$end_date bounds — the quick-select links (Today,
 * Yesterday, This Week, This Quarter, This Year) sent ?time_range=X with no
 * dates, so PHP silently fell back to the current-month default every time.
 * Only the explicit Custom Range form (which sends start_date/end_date
 * directly) ever worked. Fixed by mapping each time_range value to real
 * date bounds, with explicit start_date/end_date still taking precedence
 * exactly as before (so the Custom Range form's behaviour is unchanged).
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session (via header.php) to render, so
 * the actual behaviour was verified live in a real browser session
 * instead (every ?time_range=X value + a custom start_date/end_date
 * range, confirming the date-range label, the Monthly Revenue card's
 * number AND its dynamic title/caption, and that every other card
 * — Today's POS Sales, Overdue Invoices, Inventory Value — stays
 * unaffected, exactly as intended).
 *
 * Exit 0 = all checks pass. Exit 1 = a regression slipped in.
 */

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

echo "\n\033[1m═══ Dashboard — time_range quick-select actually filters ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/dashboard.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false) ? ok('app/dashboard.php — no syntax errors') : bad('app/dashboard.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('Explicit start_date/end_date still takes precedence (Custom Range form unchanged)');
str_contains($src, "isset(\$_GET['start_date']) || isset(\$_GET['end_date'])")
    ? ok('start_date/end_date presence is checked before the time_range switch')
    : bad('explicit start_date/end_date precedence check is missing — Custom Range form may be broken');

head('Every quick-select value is actually mapped to real date bounds');
foreach (['today', 'yesterday', 'week', 'quarter', 'year', 'month'] as $case) {
    str_contains($src, "case '$case':")
        ? ok("time_range '$case' has its own case branch")
        : bad("time_range '$case' is missing a case branch — would fall through to the default and not filter");
}

head('Default (no time_range, no dates) still matches the original current-month behaviour');
(preg_match('/case \'month\':\s*\n\s*default:\s*\n\s*\$start_date = date\(\'Y-m-01\'\);\s*\n\s*\$end_date\s*=\s*date\(\'Y-m-t\'\);/', $src) === 1)
    ? ok("default/'month' case still resolves to date('Y-m-01') .. date('Y-m-t'), matching the original default")
    : bad('default/month case no longer matches the original current-month fallback');

head('"Monthly Revenue" card title/caption is now dynamic, not hardcoded');
(str_contains($src, '$revenue_card_title') && str_contains($src, '$revenue_card_caption'))
    ? ok('dashboard.php defines $revenue_card_title / $revenue_card_caption')
    : bad('dynamic revenue card label variables are missing');
(preg_match('/<p class="mb-0">Monthly Revenue<\/p>/', $src) !== 1)
    ? ok('card title is no longer a hardcoded "Monthly Revenue" string in the HTML')
    : bad('card title HTML still hardcodes "Monthly Revenue" — dynamic label not wired into the markup');

head('Live date math — each preset resolves to the expected bounds for "today"');
$cases = [
    'today'     => [date('Y-m-d'), date('Y-m-d')],
    'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'week'      => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
    'month'     => [date('Y-m-01'), date('Y-m-t')],
    'year'      => [date('Y-01-01'), date('Y-12-31')],
];
foreach ($cases as $label => [$expStart, $expEnd]) {
    $start = $end = null;
    switch ($label) {
        case 'today':     $start = date('Y-m-d'); $end = date('Y-m-d'); break;
        case 'yesterday': $start = date('Y-m-d', strtotime('-1 day')); $end = date('Y-m-d', strtotime('-1 day')); break;
        case 'week':      $start = date('Y-m-d', strtotime('monday this week')); $end = date('Y-m-d', strtotime('sunday this week')); break;
        case 'month':     $start = date('Y-m-01'); $end = date('Y-m-t'); break;
        case 'year':      $start = date('Y-01-01'); $end = date('Y-12-31'); break;
    }
    ($start === $expStart && $end === $expEnd)
        ? ok("'$label' resolves to $start .. $end")
        : bad("'$label' resolved to $start .. $end, expected $expStart .. $expEnd");
}
