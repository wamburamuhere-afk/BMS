<?php
/**
 * Income Statement — operational-source rewrite — CLI test
 * ---------------------------------------------------------
 *   php tests/test_income_statement_sources_cli.php
 *
 * Verifies:
 *   1. Files exist (API + projects endpoint + page UI).
 *   2. API source-code contains the agreed data sources and rules
 *      (paid status, project filter behaviour, Path B COGS split,
 *      manual-journal exclusion under project filter, payroll_id
 *      guard against double-counting).
 *   3. Page UI contains the new Project dropdown and the four banners.
 *   4. Runtime: API responds with the expected shape against the live DB,
 *      for "All Projects" and for a specific project_id. No double-counting:
 *      Revenue + COGS + OpEx totals roll up to Net Profit correctly.
 *
 * Exit 0 = all pass. Exit 1 = failures found.
 */

// ── Session + DB setup MUST happen before any output ─────────────────────
// (the API calls header() and session_start(); both require no prior output).
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/actions/check_auth.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';

$failures = 0;
$passes   = 0;

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : '';
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Required files exist + lint clean');
// ─────────────────────────────────────────────────────────────────────────
$files = [
    'api/account/get_income_statement.php',
    'api/account/get_projects_for_filter.php',
    'app/bms/invoice/income_statement.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0;
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    if ($rc === 0) pass($f); else fail("php -l failed: $f — " . implode(' | ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. API source contains agreed data-source patterns');
// ─────────────────────────────────────────────────────────────────────────
$apiSrc = readSrc($root, 'api/account/get_income_statement.php');

$apiChecks = [
    "FROM invoices"                                       => 'queries invoices table',
    "WHERE status = 'paid'"                               => "filters invoices by status='paid'",
    "payment_date BETWEEN"                                => 'uses payment_date for invoice window',
    "grand_total - tax_amount"                            => 'nets tax from revenue (per accounting)',
    "FROM interim_payment_certificates"                   => 'queries IPCs table',
    "status = 'Paid'"                                     => "filters IPCs by status='Paid'",
    "invoice_id IS NULL"                                  => 'IPC double-count guard (invoice_id IS NULL)',
    "FROM sales_returns"                                  => 'queries sales_returns table',
    "sr.status = 'refunded'"                              => "filters sales returns by status='refunded'",
    "Less: Sales Returns"                                 => 'sales returns shown as contra-revenue line',
    "ii.quantity * COALESCE(p.cost_price"                 => 'Path B: product-cost COGS uses cost_price',
    "ii.product_id IS NOT NULL"                           => 'product-cost COGS skips items without product_id',
    "e.project_id IS NOT NULL"                            => 'Path B: project-direct expenses go to COGS',
    "e.project_id IS NULL"                                => 'general expenses must be untagged',
    "e.payroll_id IS NULL"                                => 'payroll guard prevents double-count',
    "FROM payroll"                                        => 'queries payroll table',
    "WHERE payment_status = 'paid'"                       => "filters payroll by payment_status='paid' (NOT status — that column is workflow only)",
    "AND payment_date BETWEEN"                            => 'compensation uses payment_date window',
    "SUM(net_salary)"                                     => 'compensation uses net_salary',
    "if (\$project_id !== null) return 0.0"               => 'compensation hidden when project filter applied',
    "project_filter_active"                               => 'API surfaces project_filter_active flag',
    "unpaid_payroll_count"                                => 'API surfaces unpaid payroll count',
    "Manual: "                                            => 'manual journal lines prefixed clearly',
    "\$journalLines"                                      => 'manual journal lines fetched per section',
    "if (\$project_id !== null || empty(\$type_ids)) return []" => 'manual journals EXCLUDED when project filter active',
];
foreach ($apiChecks as $needle => $label) {
    if (strpos($apiSrc, $needle) !== false) {
        pass($label);
    } else {
        fail("$label — missing `" . substr($needle, 0, 50) . "`");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Page UI contains Project dropdown and banners');
// ─────────────────────────────────────────────────────────────────────────
$pageSrc = readSrc($root, 'app/bms/invoice/income_statement.php');
$pageChecks = [
    'id="project_id"'                                      => 'Project dropdown present',
    'All Projects (Consolidated)'                          => 'consolidated default option label',
    'get_projects_for_filter.php'                          => 'loads projects from new endpoint',
    'id="unpaidPayrollWarning"'                            => 'unpaid-payroll banner element',
    'id="projectFilterNotice"'                             => 'project-filter notice banner',
    'project_filter_active'                                => 'JS toggles project filter notice',
    'project_id: projectId'                                => 'AJAX request passes project_id',
];
foreach ($pageChecks as $needle => $label) {
    if (strpos($pageSrc, $needle) !== false) {
        pass($label);
    } else {
        fail("$label — missing `" . substr($needle, 0, 50) . "`");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime: API responds correctly against live DB');
// ─────────────────────────────────────────────────────────────────────────

// Simulate calling the API for All Projects, full 2026.
// Suppress the header() warnings (we've already echoed pass/fail lines).
$_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
$prevErrLevel = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
require "$root/api/account/get_income_statement.php";
$raw = ob_get_clean();
error_reporting($prevErrLevel);
$resp = json_decode($raw, true);

if (!$resp || empty($resp['success'])) {
    fail('API returned non-success for "All Projects": ' . substr($raw, 0, 200));
} else {
    pass('API returns success=true for All Projects 2026');

    $d = $resp['data'];

    // Shape checks
    foreach (['meta','sections','totals'] as $k) {
        isset($d[$k]) ? pass("response.data.$k present") : fail("response.data.$k missing");
    }
    foreach (['revenue','cogs','expense'] as $sec) {
        if (isset($d['sections'][$sec])
            && isset($d['sections'][$sec]['lines'])
            && is_array($d['sections'][$sec]['lines'])
            && isset($d['sections'][$sec]['total_current'])
            && isset($d['sections'][$sec]['total_previous'])) {
            pass("section.$sec has lines + totals shape");
        } else {
            fail("section.$sec malformed");
        }
    }

    // Math sanity: Gross Profit = Revenue − COGS, Net Profit = Gross Profit − Expenses − Tax
    $t  = $d['totals'];
    $gp_check = abs($t['gross_profit']  - ($t['total_revenue'] - $t['total_cogs'])) < 0.5;
    $op_check = abs($t['operating_profit'] - ($t['gross_profit'] - $t['total_expenses'])) < 0.5;
    $np_check = abs($t['net_profit'] - ($t['operating_profit'] - $t['income_tax'])) < 0.5;
    $gp_check ? pass('totals.gross_profit = revenue − cogs')      : fail('gross_profit math wrong');
    $op_check ? pass('totals.operating_profit = gross − expenses') : fail('operating_profit math wrong');
    $np_check ? pass('totals.net_profit = operating − tax')        : fail('net_profit math wrong');

    // Meta flags
    if ($d['meta']['project_filter_active'] === false) pass('project_filter_active=false in consolidated mode');
    else fail('project_filter_active should be false');
}

// ── Project-filter mode: pick a real project_id and check the flag changes
$pidRow = $pdo->query("SELECT project_id FROM projects WHERE (status != 'archived' OR status IS NULL) ORDER BY project_id LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC);
if ($pidRow) {
    $pid = (int)$pidRow['project_id'];

    $_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'project_id' => $pid];
    $prevErrLevel = error_reporting(error_reporting() & ~E_WARNING);
    ob_start();
    require "$root/api/account/get_income_statement.php";
    $raw = ob_get_clean();
    error_reporting($prevErrLevel);
    $resp = json_decode($raw, true);

    if (!$resp || empty($resp['success'])) {
        fail("API non-success for project_id=$pid: " . substr($raw, 0, 200));
    } else {
        pass("API success for project_id=$pid");
        $m = $resp['data']['meta'];
        if ($m['project_filter_active'] === true && (int)$m['project_id'] === $pid) {
            pass('meta correctly reflects active project filter');
        } else {
            fail('meta project_filter_active / project_id mismatch');
        }

        // When project filter is on, manual journal lines must NOT appear
        $allLines = array_merge(
            $resp['data']['sections']['revenue']['lines'],
            $resp['data']['sections']['cogs']['lines'],
            $resp['data']['sections']['expense']['lines']
        );
        $manualCount = 0;
        foreach ($allLines as $l) {
            if (strpos($l['account_name'] ?? '', 'Manual:') === 0) $manualCount++;
        }
        if ($manualCount === 0) pass('manual journal lines excluded under project filter');
        else fail("manual journal lines present under project filter: $manualCount");

        // Compensation must be 0 under project filter (payroll has no project_id)
        $compFound = false;
        foreach ($resp['data']['sections']['expense']['lines'] as $l) {
            if (($l['account_name'] ?? '') === 'Salaries & Wages') { $compFound = true; break; }
        }
        if (!$compFound) pass('Salaries & Wages line absent under project filter');
        else fail('Salaries & Wages should be hidden under project filter');
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
exit($failures === 0 ? 0 : 1);
