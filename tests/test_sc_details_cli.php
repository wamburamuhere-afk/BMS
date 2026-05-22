<?php
/**
 * Sub-Contractor Details CLI Test Suite
 * Run: php tests/test_sc_details_cli.php
 * Exit 0 = all pass (safe to push)
 * Exit 1 = failures found (push blocked)
 *
 * Static-analysis suite — no database required.
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $msg): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $msg\n"; }
function fail(string $msg): void  { global $failures; $failures++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $path = "$root/$rel";
    return file_exists($path) ? file_get_contents($path) : '';
}
function want(string $hay, string $needle, string $ok, string $ko): void {
    str_contains($hay, $needle) ? pass($ok) : fail($ko);
}
function wantAbsent(string $hay, string $needle, string $ok, string $ko): void {
    !str_contains($hay, $needle) ? pass($ok) : fail($ko);
}

$file  = 'app/bms/operations/sub_contractor_details.php';
$hdrFile = 'header.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. Required file exists');
// ─────────────────────────────────────────────────────────────────────────────
file_exists("$root/$file") ? pass($file) : fail("MISSING: $file");

// ─────────────────────────────────────────────────────────────────────────────
section('2. PHP syntax');
// ─────────────────────────────────────────────────────────────────────────────
foreach ([$file, $hdrFile] as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("Cannot lint — file missing: $f"); continue; }
    $out = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if (str_contains((string)$out, 'Parse error') || str_contains((string)$out, 'Fatal error')) {
        fail("Syntax error in $f:\n     $out");
    } else {
        pass("Syntax OK: $f");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Duplicate const CSRF_TOKEN guard (root cause of broken tabs)');
// ─────────────────────────────────────────────────────────────────────────────
$src = readSrc($root, $file);
$hdr = readSrc($root, $hdrFile);

// header.php must define CSRF_TOKEN
want($hdr, "const CSRF_TOKEN", 'header.php defines const CSRF_TOKEN (global source of truth)',
     'header.php does NOT define const CSRF_TOKEN — AJAX will be unauthenticated');

// The page must NOT re-declare it (duplicate const → SyntaxError → all JS breaks)
$pageScriptPart = $src;
// Count occurrences in the page file itself
$occurrences = substr_count($pageScriptPart, 'const CSRF_TOKEN');
if ($occurrences === 0) {
    pass('sub_contractor_details.php does not re-declare const CSRF_TOKEN (no duplicate — JS parses correctly)');
} else {
    fail("sub_contractor_details.php re-declares const CSRF_TOKEN ($occurrences time(s)) — causes SyntaxError that breaks ALL JS on the page (tabs stop working)");
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. Tab structure — three panes exist');
// ─────────────────────────────────────────────────────────────────────────────
want($src, 'id="pane-projects"', 'Projects pane exists (#pane-projects)', 'Missing #pane-projects');
want($src, 'id="pane-invoices"', 'Invoices pane exists (#pane-invoices)',  'Missing #pane-invoices');
want($src, 'id="pane-payments"', 'Payments pane exists (#pane-payments)', 'Missing #pane-payments');

// Invoices and Payments panes must start hidden (projects is the default)
want($src, '"pane-invoices" class="sc-tab-pane d-none"', 'Invoices pane starts hidden (d-none)',
     'Invoices pane is missing d-none — would show on page load');
want($src, '"pane-payments" class="sc-tab-pane d-none"', 'Payments pane starts hidden (d-none)',
     'Payments pane is missing d-none — would show on page load');

// ─────────────────────────────────────────────────────────────────────────────
section('5. Tab buttons wired correctly');
// ─────────────────────────────────────────────────────────────────────────────
want($src, "onclick=\"switchScTab('projects')\"", "Projects button calls switchScTab('projects')",
     "Projects button missing onclick=switchScTab('projects')");
want($src, "onclick=\"switchScTab('invoices')\"", "Invoices button calls switchScTab('invoices')",
     "Invoices button missing onclick=switchScTab('invoices')");
want($src, "onclick=\"switchScTab('payments')\"", "Payments button calls switchScTab('payments')",
     "Payments button missing onclick=switchScTab('payments')");

// ─────────────────────────────────────────────────────────────────────────────
section('6. switchScTab function defined');
// ─────────────────────────────────────────────────────────────────────────────
want($src, 'function switchScTab(tab)', 'switchScTab() function is defined',
     'switchScTab() function is missing — tab buttons will do nothing');
want($src, "classList.remove('d-none')", 'switchScTab removes d-none from active pane',
     'switchScTab does not remove d-none — panes will never become visible');

// ─────────────────────────────────────────────────────────────────────────────
section('7. DataTable IDs present');
// ─────────────────────────────────────────────────────────────────────────────
want($src, 'id="scProjectsTable"', 'scProjectsTable exists',  'Missing scProjectsTable');
want($src, 'id="scRiTable"',       'scRiTable exists',         'Missing scRiTable');
want($src, 'id="scPaymentsTable"', 'scPaymentsTable exists',  'Missing scPaymentsTable');

// ─────────────────────────────────────────────────────────────────────────────
section('8. Received Invoices AJAX wiring');
// ─────────────────────────────────────────────────────────────────────────────
want($src, 'function initRiScTable', 'initRiScTable() defined', 'Missing initRiScTable()');
want($src, 'function loadRiSc',      'loadRiSc() defined',      'Missing loadRiSc()');
want($src, "buildUrl('api/received_invoices.php')", 'API URL built with buildUrl()',
     'API URL not using buildUrl() — will break on non-root installs');

// ─────────────────────────────────────────────────────────────────────────────
section('9. safeOutput defined on page (not relying on global)');
// ─────────────────────────────────────────────────────────────────────────────
want($src, 'function safeOutput(', 'safeOutput() defined in page script',
     'safeOutput() not defined — JS template literals will throw ReferenceError');

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m════════════════════════════════════════\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes tests passed — safe to push.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $failures test(s) FAILED  |  $passes passed\033[0m\n";
    echo "\033[31mFix the errors above before pushing.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(1);
}
