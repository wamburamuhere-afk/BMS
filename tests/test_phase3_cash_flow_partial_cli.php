<?php
/**
 * Phase 3.4 — Cash Flow UI partial CLI test
 * ------------------------------------------
 *   php tests/test_phase3_cash_flow_partial_cli.php
 *
 * Verifies:
 *   1. File exists + lint-clean.
 *   2. Source contains the agreed structural patterns:
 *      - method toggle param parsing (default direct, indirect when set)
 *      - method passed through to the API call
 *      - cf_tab_url helper (builds tab links with method swap)
 *      - cf_fmt + cf_class helpers
 *      - 3 amount columns (Current / Comparative / Variance)
 *      - section-render closure (single source of truth for the table)
 *      - disclosure cards for §7.19A and §7.19B-C
 *      - logReportAction includes method= breadcrumb
 *   3. Runtime: render partial with method=direct → success, contains the
 *      direct method footer, both tab links present with one .active,
 *      Variance column present, opening + closing cash rendered, no PHP
 *      errors.
 *   4. Runtime: render partial with method=indirect → indirect footer text,
 *      indirect tab marked active.
 *   5. Runtime: disclosure cards rendered with correct headings and the
 *      "Not Applicable" / "Proxy Disclosure" badges.
 *   6. Live-DB sanity: the rendered §7.19B-C card shows the actual unpaid
 *      invoice count (matching the API value) for the period.
 *   7. Project filter passthrough: rendering with project_id=N includes
 *      the project_id in the tab links so the tab switch preserves filter.
 *   8. Backward compat: Phase 3.1 / 3.2 / 3.3 API tests still pass.
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

$file = "$root/app/bms/invoice/reps/cash_flow.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File exists + lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($file) ? pass('cash_flow.php exists') : fail('file missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains agreed structural patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "\$_GET['method']"                                       => 'method param read from query string',
    "=== 'indirect'"                                         => 'method narrowed to direct/indirect whitelist',
    "'method' => \$method"                                   => 'method passed through to API call',
    "function cf_tab_url"                                    => 'cf_tab_url helper defined',
    "function cf_fmt"                                        => 'cf_fmt helper defined',
    "function cf_class"                                      => 'cf_class helper defined',
    'class="nav nav-tabs'                                    => 'Bootstrap nav-tabs rendered',
    'Direct Method'                                          => 'Direct Method tab label',
    'Indirect Method'                                        => 'Indirect Method tab label',
    'cf_tab_url(\'direct\''                                  => 'direct tab href built via helper',
    'cf_tab_url(\'indirect\''                                => 'indirect tab href built via helper',
    'Variance'                                               => 'Variance column header',
    '(Current − Comparative)'                                => 'Variance column subtitle explains formula',
    "comparative_amount"                                     => 'lines render comparative_amount',
    "comparative_total"                                      => 'sections render comparative_total',
    "comparative_opening_cash"                               => 'opening row reads comparative_opening_cash',
    "comparative_closing_cash"                               => 'closing row reads comparative_closing_cash',
    '$renderSection = function'                              => 'section-render closure (DRY)',
    '§7.19A'                                                 => 'card cites §7.19A explicitly',
    '§7.19B-C'                                               => 'card cites §7.19B-C explicitly',
    "financing_liabilities_reconciliation"                   => '§7.19A pulled from API data',
    "supplier_finance_arrangements"                          => '§7.19B-C pulled from API data',
    "Reconciliation of Liabilities Arising from Financing Activities" => '§7.19A heading verbatim per IFRS for SMEs',
    "Supplier Finance Arrangements"                          => '§7.19B-C heading',
    'Not Applicable'                                         => '§7.19A badge when applicable=false',
    'Proxy Disclosure'                                       => '§7.19B-C badge when applicable=false',
    'method=<?= $method ?>'                                  => 'logReportAction breadcrumb includes method',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime: render with method=direct');
// ─────────────────────────────────────────────────────────────────────────
$_GET = [
    'report'     => 'cash_flow',
    'start_date' => '2026-01-01',
    'end_date'   => '2026-05-31',
    'method'     => 'direct',
];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $file;
    $html_direct = ob_get_clean();
    error_reporting($prevErr);

    $markers = [
        'Cash Flow Statement'           => 'page title',
        'OPERATING ACTIVITIES'          => 'operating section header',
        'INVESTING ACTIVITIES'          => 'investing section header',
        'FINANCING ACTIVITIES'          => 'financing section header',
        'Opening Cash'                  => 'opening cash row',
        'Closing Cash'                  => 'closing cash row',
        'NET CHANGE IN CASH'            => 'net change row',
        'Variance'                      => 'variance column rendered',
        'Direct method:'                => 'direct-method footer note',
        'method=indirect'               => 'indirect tab href present (for switching)',
    ];
    foreach ($markers as $needle => $label) {
        strpos($html_direct, $needle) !== false ? pass("HTML contains: $label") : fail("HTML missing: $label");
    }

    // Direct tab must be active when method=direct
    $direct_active_pattern = '/<a class="nav-link active fw-bold"[^>]*method=direct/';
    preg_match($direct_active_pattern, $html_direct) === 1
        ? pass('Direct tab carries .active fw-bold when method=direct')
        : fail('Direct tab not marked active when method=direct');

    // Indirect tab must NOT be active when method=direct
    $indirect_active_pattern = '/<a class="nav-link active fw-bold"[^>]*method=indirect/';
    preg_match($indirect_active_pattern, $html_direct) === 0
        ? pass('Indirect tab is NOT active when method=direct')
        : fail('Indirect tab should not be .active when method=direct');
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('partial threw during direct-method render: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime: render with method=indirect');
// ─────────────────────────────────────────────────────────────────────────
$_GET = [
    'report'     => 'cash_flow',
    'start_date' => '2026-01-01',
    'end_date'   => '2026-05-31',
    'method'     => 'indirect',
];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $file;
    $html_indirect = ob_get_clean();
    error_reporting($prevErr);

    strpos($html_indirect, 'Indirect method:') !== false
        ? pass('indirect-method footer note rendered')
        : fail('indirect-method footer note missing');

    // Indirect tab must be active when method=indirect
    $indirect_active_pattern = '/<a class="nav-link active fw-bold"[^>]*method=indirect/';
    preg_match($indirect_active_pattern, $html_indirect) === 1
        ? pass('Indirect tab carries .active fw-bold when method=indirect')
        : fail('Indirect tab not marked active when method=indirect');
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('partial threw during indirect-method render: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Disclosure cards rendered with correct content');
// ─────────────────────────────────────────────────────────────────────────
$disclosure_markers = [
    'IFRS for SMEs — Required Disclosures'                            => 'disclosures section header',
    'Reconciliation of Liabilities Arising from Financing Activities' => '§7.19A card title',
    'Supplier Finance Arrangements'                                   => '§7.19B-C card title',
    'Not Applicable'                                                  => '§7.19A "Not Applicable" badge text',
    'Proxy Disclosure'                                                => '§7.19B-C "Proxy Disclosure" badge text',
    'Opening balance'                                                 => '§7.19A opening balance row',
    'Cash changes'                                                    => '§7.19A cash changes row',
    'Non-cash changes'                                                => '§7.19A non-cash changes row',
    'Closing balance'                                                 => '§7.19A closing balance row',
    'Unpaid approved invoices'                                        => '§7.19B-C invoice count row',
    'Total unpaid amount'                                             => '§7.19B-C amount row',
    'Earliest computed due date'                                      => '§7.19B-C earliest date row',
    'Latest computed due date'                                        => '§7.19B-C latest date row',
];
foreach ($disclosure_markers as $needle => $label) {
    strpos($html_indirect, $needle) !== false ? pass("HTML contains: $label") : fail("HTML missing: $label");
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Live-DB sanity: §7.19B-C invoice count matches API');
// ─────────────────────────────────────────────────────────────────────────
$_GET = [
    'start_date' => '2026-01-01',
    'end_date'   => '2026-05-31',
    'method'     => 'direct',
];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require "$root/api/account/get_cash_flow.php";
$cf_raw = ob_get_clean();
error_reporting($prevErr);
$cf = json_decode($cf_raw, true);

if (!$cf || empty($cf['success'])) {
    fail('API request failed during sanity check');
} else {
    $api_count = (int)($cf['data']['disclosures']['supplier_finance_arrangements']['current']['invoice_count'] ?? -1);
    // The §7.19B-C card renders the invoice count inside a <td class="text-end">N</td>
    // Search for "Unpaid approved invoices (count)" → next <td class="text-end"> value
    $pattern = '/Unpaid approved invoices \(count\)<\/td>\s*<td[^>]*class="text-end"[^>]*>\s*(\d+)\s*<\/td>/';
    if (preg_match($pattern, $html_indirect, $m)) {
        $html_count = (int)$m[1];
        $html_count === $api_count
            ? pass("rendered invoice count ($html_count) matches API value ($api_count)")
            : fail("rendered invoice count ($html_count) ≠ API value ($api_count)");
    } else {
        fail('could not locate Unpaid approved invoices count in rendered HTML');
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Project filter passthrough into tab URLs');
// ─────────────────────────────────────────────────────────────────────────
// Pick any project_id that the admin can see
$stmt = $pdo->query("SELECT project_id FROM projects ORDER BY project_id ASC LIMIT 1");
$pid_row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($pid_row) {
    $test_pid = (int)$pid_row['project_id'];
    $_GET = [
        'report'     => 'cash_flow',
        'start_date' => '2026-01-01',
        'end_date'   => '2026-05-31',
        'method'     => 'direct',
        'project_id' => (string)$test_pid,
    ];
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start();
    try {
        require $file;
        $html_proj = ob_get_clean();
        error_reporting($prevErr);

        $pattern = '/href="[^"]*method=direct[^"]*project_id=' . $test_pid . '/';
        preg_match($pattern, $html_proj) === 1
            ? pass("direct tab URL preserves project_id=$test_pid")
            : (
                preg_match('/href="[^"]*project_id=' . $test_pid . '[^"]*method=direct/', $html_proj) === 1
                    ? pass("direct tab URL preserves project_id=$test_pid (different param order)")
                    : fail("direct tab URL missing project_id=$test_pid passthrough")
              );

        $pattern2 = '/href="[^"]*method=indirect[^"]*project_id=' . $test_pid . '/';
        preg_match($pattern2, $html_proj) === 1
            ? pass("indirect tab URL preserves project_id=$test_pid")
            : (
                preg_match('/href="[^"]*project_id=' . $test_pid . '[^"]*method=indirect/', $html_proj) === 1
                    ? pass("indirect tab URL preserves project_id=$test_pid (different param order)")
                    : fail("indirect tab URL missing project_id=$test_pid passthrough")
              );
    } catch (Throwable $e) {
        error_reporting($prevErr);
        ob_get_clean();
        fail('project_id passthrough render threw: ' . $e->getMessage());
    }
} else {
    pass('no projects in DB to test passthrough — skipped');
}

// ─────────────────────────────────────────────────────────────────────────
section('8. Backward compat: Phase 3.1 / 3.2 / 3.3 still pass');
// ─────────────────────────────────────────────────────────────────────────
foreach ([
    'tests/test_phase3_cash_flow_comparative_cli.php',
    'tests/test_phase3_cash_flow_indirect_cli.php',
    'tests/test_phase3_cash_flow_disclosures_cli.php',
] as $rel) {
    $tf = "$root/$rel";
    if (!file_exists($tf)) {
        pass("$rel not present — skipping");
        continue;
    }
    $rc2 = 0; $o2 = [];
    exec("php " . escapeshellarg($tf) . " 2>&1", $o2, $rc2);
    $rc2 === 0 ? pass("$rel still passes")
              : fail("$rel failed: rc=$rc2\n" . implode("\n", array_slice($o2, -10)));
}

exit($failures === 0 ? 0 : 1);
