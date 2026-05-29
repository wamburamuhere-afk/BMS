<?php
/**
 * Phase 3.2 — Cash Flow Indirect-method CLI test
 * -----------------------------------------------
 *   php tests/test_phase3_cash_flow_indirect_cli.php
 *
 * Verifies:
 *   1. File lint-clean.
 *   2. Source contains the agreed structural patterns (method
 *      parameter, indirect computation closure, peer-API fetch
 *      helper, working-capital snapshot helper, Phase 4 deferral
 *      note for depreciation).
 *   3. Default method = direct (backward compatible).
 *   4. Specifying method=indirect returns method='indirect' in meta.
 *   5. Indirect operating section contains the standard line set:
 *      Profit before tax, Depreciation, Δ AR, Δ Inventory, Δ AP,
 *      Δ Tax Payable, plus 2 sub-headers.
 *   6. Depreciation line is ALWAYS shown with amount=0 (per "always
 *      show even when zero" preference) and carries a note.
 *   7. Investing + financing sections are identical between methods
 *      (method-independent).
 *   8. Reconciliation difference is exposed in meta when method=indirect
 *      and is NULL when method=direct.
 *   9. Math: indirect operating total =
 *      profit_before_tax + depreciation
 *        - (Δ AR + Δ Inventory) + (Δ AP + Δ Tax Payable)
 *  10. Sum of line amounts equals section total.
 *  11. Non-admin out-of-scope project_id → 403.
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

function callCF(string $root, array $params): ?array {
    $saved_get = $_GET;
    $_GET = $params;
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start();
    try { require "$root/api/account/get_cash_flow.php"; } catch (Throwable $e) {}
    $raw = ob_get_clean();
    error_reporting($prevErr);
    $_GET = $saved_get;
    return json_decode($raw, true);
}

$file = "$root/api/account/get_cash_flow.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains indirect-method patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = readSrc($root, 'api/account/get_cash_flow.php');
$checks = [
    "\$method = (\$_GET['method'] ?? 'direct')"     => 'method parameter parsed',
    "'direct' (default) | 'indirect'"               => 'method options documented',
    "\$fetchPeer = function"                        => 'peer-API fetch helper present',
    "\$wcSnapshot = function"                       => 'working-capital snapshot helper present',
    "\$computeIndirectOperating = function"         => 'indirect computation closure present',
    "get_income_statement.php"                      => 'reuses IS API for profit_before_tax',
    "wc_end['ar']          - \$wc_start['ar']"      => 'computes Δ AR',
    "wc_end['inventory']   - \$wc_start['inventory']" => 'computes Δ Inventory',
    "wc_end['ap']          - \$wc_start['ap']"      => 'computes Δ AP',
    "wc_end['tax_payable'] - \$wc_start['tax_payable']" => 'computes Δ Tax Payable',
    "'Profit before tax'"                           => 'indirect Profit before tax line',
    "'  Depreciation'"                              => 'indirect Depreciation line (shown even when 0)',
    "Depreciation engine not yet posting"           => 'Phase 2 of assets deferral note on Depreciation',
    "(Increase)/decrease in Trade Receivables"      => 'Δ AR line label',
    "(Increase)/decrease in Inventory"              => 'Δ Inventory line label',
    "Increase/(decrease) in Trade Payables"         => 'Δ AP line label',
    "Increase/(decrease) in Tax Payable"            => 'Δ Tax Payable line label',
    "'method'                    => \$method"       => 'method exposed in meta',
    "operating_reconciliation_difference"           => 'reconciliation difference exposed',
    "\$net_operating_cur + \$cur['net_investing'] + \$cur['net_financing']" => 'net_change uses per-method operating total',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Default method = direct (backward compatible)');
// ─────────────────────────────────────────────────────────────────────────
$r = callCF($root, ['start_date' => '2026-01-01', 'end_date' => '2026-05-31']);
if (!empty($r['success'])) {
    $r['data']['meta']['method'] === 'direct'
        ? pass("default method = 'direct' when not specified")
        : fail("expected method='direct', got '{$r['data']['meta']['method']}'");

    $r['data']['meta']['operating_reconciliation_difference']['current'] === null
        ? pass('reconciliation difference is NULL for direct method')
        : fail('reconciliation difference should be NULL for direct method');
}

// Capture direct totals for comparison
$direct_inv_total  = isset($r['data']['sections']['investing']['total'])  ? (float)$r['data']['sections']['investing']['total']  : null;
$direct_fin_total  = isset($r['data']['sections']['financing']['total'])  ? (float)$r['data']['sections']['financing']['total']  : null;

// ─────────────────────────────────────────────────────────────────────────
section('4. method=indirect returns method=indirect in meta');
// ─────────────────────────────────────────────────────────────────────────
$r2 = callCF($root, ['start_date' => '2026-01-01', 'end_date' => '2026-05-31', 'method' => 'indirect']);
if (!empty($r2['success'])) {
    $r2['data']['meta']['method'] === 'indirect'
        ? pass("method='indirect' in meta when requested")
        : fail('method not set to indirect');
} else {
    fail('indirect run non-success');
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Indirect operating section has the standard line set');
// ─────────────────────────────────────────────────────────────────────────
$ind_lines = $r2['data']['sections']['operating']['lines'];
$line_names = array_map(fn($l) => $l['name'] ?? '', $ind_lines);

$expected_line_substrs = [
    'Profit before tax',
    'Adjustments for:',
    'Depreciation',
    'Changes in working capital:',
    '(Increase)/decrease in Trade Receivables',
    '(Increase)/decrease in Inventory',
    'Increase/(decrease) in Trade Payables',
    'Increase/(decrease) in Tax Payable',
];
foreach ($expected_line_substrs as $expected) {
    $found = false;
    foreach ($line_names as $n) {
        if (strpos($n, $expected) !== false) { $found = true; break; }
    }
    $found ? pass("indirect lines include: '$expected'") : fail("indirect lines missing: '$expected'");
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Depreciation line always shown with note (zero today)');
// ─────────────────────────────────────────────────────────────────────────
$dep_line = null;
foreach ($ind_lines as $l) {
    if (isset($l['name']) && strpos($l['name'], 'Depreciation') !== false && empty($l['is_subheader'])) {
        $dep_line = $l; break;
    }
}
if ($dep_line) {
    pass('Depreciation line present in indirect output');
    (float)$dep_line['amount'] === 0.0
        ? pass('Depreciation amount = 0 (Phase 2 of assets has not posted yet)')
        : fail("Depreciation amount unexpectedly non-zero: {$dep_line['amount']}");
    !empty($dep_line['note']) && stripos($dep_line['note'], 'Phase 2') !== false
        ? pass('Depreciation line carries a Phase-2 deferral note')
        : fail('Depreciation note missing or wrong text');
} else {
    fail('Depreciation line missing from indirect output');
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Investing + Financing sections identical between methods');
// ─────────────────────────────────────────────────────────────────────────
$ind_inv_total = (float)$r2['data']['sections']['investing']['total'];
$ind_fin_total = (float)$r2['data']['sections']['financing']['total'];

abs($direct_inv_total - $ind_inv_total) < 0.5
    ? pass("investing total identical: direct=$direct_inv_total, indirect=$ind_inv_total")
    : fail("investing differs: direct=$direct_inv_total vs indirect=$ind_inv_total");
abs($direct_fin_total - $ind_fin_total) < 0.5
    ? pass("financing total identical: direct=$direct_fin_total, indirect=$ind_fin_total")
    : fail("financing differs: direct=$direct_fin_total vs indirect=$ind_fin_total");

// ─────────────────────────────────────────────────────────────────────────
section('8. Reconciliation difference exposed when method=indirect');
// ─────────────────────────────────────────────────────────────────────────
$diff = $r2['data']['meta']['operating_reconciliation_difference'];
is_array($diff) && array_key_exists('current', $diff) && array_key_exists('comparative', $diff)
    ? pass('reconciliation difference has current + comparative keys')
    : fail('reconciliation difference structure wrong');

// The difference equals indirect_total − direct_total
$direct_op_total = (float)$r['data']['sections']['operating']['total'];
$ind_op_total    = (float)$r2['data']['sections']['operating']['total'];
$expected_diff   = $ind_op_total - $direct_op_total;
abs((float)$diff['current'] - $expected_diff) < 0.5
    ? pass("reconciliation difference = indirect − direct (math check passes)")
    : fail("reconciliation diff wrong: got {$diff['current']}, expected $expected_diff");

// ─────────────────────────────────────────────────────────────────────────
section('9. Indirect math: total = PBT + Dep − ΔAR − ΔInv + ΔAP + ΔTax');
// ─────────────────────────────────────────────────────────────────────────
$pbt = $dep = $dar = $dinv = $dap = $dtax = 0.0;
foreach ($ind_lines as $l) {
    if (!empty($l['is_subheader'])) continue;
    $name = $l['name'];
    if (strpos($name, 'Profit before tax')                        !== false) $pbt  = (float)$l['amount'];
    elseif (strpos($name, 'Depreciation')                         !== false) $dep  = (float)$l['amount'];
    elseif (strpos($name, '(Increase)/decrease in Trade Receivables') !== false) $dar  = (float)$l['amount'];
    elseif (strpos($name, '(Increase)/decrease in Inventory')     !== false) $dinv = (float)$l['amount'];
    elseif (strpos($name, 'Increase/(decrease) in Trade Payables') !== false) $dap  = (float)$l['amount'];
    elseif (strpos($name, 'Increase/(decrease) in Tax Payable')    !== false) $dtax = (float)$l['amount'];
}
$expected_total = $pbt + $dep + $dar + $dinv + $dap + $dtax;
abs($expected_total - $ind_op_total) < 0.5
    ? pass("operating total = sum of all 6 line amounts (PBT + Dep ± deltas)")
    : fail("operating total math wrong: expected $expected_total, got $ind_op_total");

// ─────────────────────────────────────────────────────────────────────────
section('10. Non-admin out-of-scope project_id → 403');
// ─────────────────────────────────────────────────────────────────────────
$_SESSION['is_admin'] = false;
$_SESSION['scope']    = ['projects' => []];
global $pdo;
$proj_row = $pdo->query("SELECT project_id FROM projects WHERE (status != 'archived' OR status IS NULL) ORDER BY project_id LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);
if ($proj_row) {
    $r3 = callCF($root, [
        'start_date' => '2026-01-01',
        'end_date'   => '2026-05-31',
        'method'     => 'indirect',
        'project_id' => (int)$proj_row['project_id'],
    ]);
    !empty($r3['message']) && stripos($r3['message'], 'not in your assigned scope') !== false
        ? pass('non-admin out-of-scope indirect call → 403 enforced')
        : fail('out-of-scope check failed: ' . json_encode($r3));
}
$_SESSION['is_admin'] = true;
unset($_SESSION['scope']);

exit($failures === 0 ? 0 : 1);
