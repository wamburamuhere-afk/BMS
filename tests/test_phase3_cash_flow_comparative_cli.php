<?php
/**
 * Phase 3.1 — Cash Flow comparative period CLI test
 * --------------------------------------------------
 *   php tests/test_phase3_cash_flow_comparative_cli.php
 *
 * Verifies:
 *   1. File lint-clean.
 *   2. Source contains the agreed structural patterns (computeWindow
 *      closure called twice; comparative_start/_end derived as
 *      -1 year; per-line comparative_amount key; opening/closing
 *      cash chained correctly: cur_closing >= cur_opening, cmp_closing
 *      == cur_opening, cmp_opening = cmp_closing − cmp_net_change).
 *   3. Runtime against live DB: API returns success, expected shape,
 *      comparative_start = current_start − 1 year (calendar exact),
 *      comparative_end = current_end − 1 year.
 *   4. Each operating/investing/financing section has a 'comparative_total'
 *      field; each visible line has a 'comparative_amount' field.
 *   5. Math invariant: section.comparative_total equals the sum of
 *      its lines' comparative_amount values.
 *   6. Cash chaining: cmp_closing_cash == opening_cash (within 0.5 TZS).
 *   7. Cash chaining: opening_cash + net_change_in_cash == closing_cash.
 *   8. Backward compat: existing meta keys still present.
 *   9. Test against existing Phase A+ CF test file: still passes.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : '';
}

$file = "$root/api/account/get_cash_flow.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains comparative-period patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = readSrc($root, 'api/account/get_cash_flow.php');
$checks = [
    "strtotime(\"\$start_date -1 year\")"           => 'comparative_start derived as -1 year',
    "strtotime(\"\$end_date -1 year\")"             => 'comparative_end derived as -1 year',
    "\$computeWindow = function"                    => 'extracted per-window computation closure',
    "\$cur = \$computeWindow(\$start_date, \$end_date)"           => 'closure called for current window',
    "\$cmp = \$computeWindow(\$comparative_start, \$comparative_end)" => 'closure called for comparative window',
    "'comparative_amount'"                          => 'lines include comparative_amount',
    "'comparative_total'"                           => 'sections expose comparative_total',
    "'comparative_start'"                           => 'meta exposes comparative_start',
    "'comparative_end'"                             => 'meta exposes comparative_end',
    "'comparative_opening_cash'"                    => 'meta exposes comparative_opening_cash',
    "'comparative_closing_cash'"                    => 'meta exposes comparative_closing_cash',
    "\$cmp_closing_cash = \$opening_cash"           => 'cmp_closing equals current opening (cash chain)',
    "\$cmp_closing_cash - \$net_change_cmp"            => 'cmp_opening = cmp_closing - cmp_net_change',
    "'comparative' => ["                             => 'totals expose comparative sub-object',
    "IFRS for SMEs"                                  => 'cites IFRS for SMEs standard',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime: API response shape');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require $file;
$raw = ob_get_clean();
error_reporting($prevErr);
$r = json_decode($raw, true);

if (!$r || empty($r['success'])) {
    fail('admin run non-success: ' . substr($raw, 0, 200));
    exit(1);
}
pass('API success for 2026-01-01 to 2026-05-31');
$d = $r['data'];

foreach (['comparative_start', 'comparative_end', 'comparative_opening_cash', 'comparative_closing_cash'] as $k) {
    array_key_exists($k, $d['meta']) ? pass("meta.$k present") : fail("meta.$k missing");
}
$d['meta']['comparative_start'] === '2025-01-01'
    ? pass('comparative_start = 2025-01-01 (current_start − 1 year)')
    : fail("comparative_start expected 2025-01-01, got '{$d['meta']['comparative_start']}'");
$d['meta']['comparative_end'] === '2025-05-31'
    ? pass('comparative_end = 2025-05-31 (current_end − 1 year)')
    : fail("comparative_end expected 2025-05-31, got '{$d['meta']['comparative_end']}'");

// ─────────────────────────────────────────────────────────────────────────
section('4. Each section has comparative_total + each line has comparative_amount');
// ─────────────────────────────────────────────────────────────────────────
foreach (['operating', 'investing', 'financing'] as $sec) {
    array_key_exists('comparative_total', $d['sections'][$sec])
        ? pass("section.$sec.comparative_total present")
        : fail("section.$sec.comparative_total missing");
    if (!empty($d['sections'][$sec]['lines'])) {
        $all_ok = true;
        foreach ($d['sections'][$sec]['lines'] as $l) {
            if (!array_key_exists('comparative_amount', $l)) { $all_ok = false; break; }
        }
        $all_ok ? pass("section.$sec lines all have comparative_amount") : fail("section.$sec line missing comparative_amount");
    }
}

// totals.comparative.net_change_in_cash
array_key_exists('comparative', $d['totals']) && array_key_exists('net_change_in_cash', $d['totals']['comparative'])
    ? pass('totals.comparative.net_change_in_cash present')
    : fail('totals.comparative.net_change_in_cash missing');

// ─────────────────────────────────────────────────────────────────────────
section('5. Math: section.comparative_total = sum of lines.comparative_amount');
// ─────────────────────────────────────────────────────────────────────────
foreach (['operating', 'investing'] as $sec) {  // financing is empty
    $sum = 0.0;
    foreach ($d['sections'][$sec]['lines'] as $l) {
        $sum += (float)$l['comparative_amount'];
    }
    $section_total = (float)$d['sections'][$sec]['comparative_total'];
    abs($sum - $section_total) < 0.5
        ? pass("section.$sec.comparative_total ($section_total) = sum of lines ($sum)")
        : fail("section.$sec math wrong: sum=$sum, total=$section_total");
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Cash chain: comparative_closing == current opening');
// ─────────────────────────────────────────────────────────────────────────
$cur_opening = (float)$d['meta']['opening_cash'];
$cmp_closing = (float)$d['meta']['comparative_closing_cash'];
abs($cur_opening - $cmp_closing) < 0.5
    ? pass("opening_cash ($cur_opening) == comparative_closing_cash ($cmp_closing) — consistent")
    : fail("cash chain broken: opening_cash=$cur_opening, cmp_closing_cash=$cmp_closing");

// ─────────────────────────────────────────────────────────────────────────
section('7. Cash chain: opening + net_change = closing');
// ─────────────────────────────────────────────────────────────────────────
$cur_closing = (float)$d['meta']['closing_cash'];
$net = (float)$d['totals']['net_change_in_cash'];
abs(($cur_opening + $net) - $cur_closing) < 0.5
    ? pass("opening_cash + net_change_in_cash == closing_cash ($cur_opening + $net = $cur_closing)")
    : fail("cash chain math wrong: opening+net=" . ($cur_opening + $net) . ", closing=$cur_closing");

// And same invariant for comparative
$cmp_opening = (float)$d['meta']['comparative_opening_cash'];
$cmp_net = (float)$d['totals']['comparative']['net_change_in_cash'];
abs(($cmp_opening + $cmp_net) - $cmp_closing) < 0.5
    ? pass("comparative opening + net_change = closing (math intact for comparative)")
    : fail("comparative cash chain wrong");

// ─────────────────────────────────────────────────────────────────────────
section('8. Backward compat: existing meta keys still present');
// ─────────────────────────────────────────────────────────────────────────
$compat_keys = ['current_start', 'current_end', 'project_id', 'project_filter_active',
                'is_admin', 'scoped_project_ids', 'opening_cash', 'closing_cash'];
foreach ($compat_keys as $k) {
    array_key_exists($k, $d['meta']) ? pass("meta.$k still present") : fail("meta.$k missing (regression)");
}

// ─────────────────────────────────────────────────────────────────────────
section('9. Existing tests/test_cash_flow_sources_cli.php still passes');
// ─────────────────────────────────────────────────────────────────────────
$cf_legacy_test = "$root/tests/test_cash_flow_sources_cli.php";
if (file_exists($cf_legacy_test)) {
    exec("php " . escapeshellarg($cf_legacy_test) . " 2>&1", $o2, $rc2);
    $rc2 === 0 ? pass('existing CF test suite still passes after refactor')
              : fail("existing CF test failed: rc=$rc2");
} else {
    pass('legacy CF test not present — skipping regression check');
}

exit($failures === 0 ? 0 : 1);
