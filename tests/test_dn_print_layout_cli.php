<?php
/**
 * Delivery Notes list print — Type column removed, Project oval removed,
 * font sizing matches ledger_report.php / grn.php
 *   php tests/test_dn_print_layout_cli.php
 *
 * User-reported (with a real print/PDF screenshot of app/bms/grn/
 * delivery_notes.php): asked for the same font-size fix already applied to
 * grn.php (mirroring app/constant/reports/ledger_report.php's #ledgerTable
 * scheme), the "Type" (INBOUND/OUTBOUND) column dropped from print
 * entirely, and the Project column's oval badge shape removed — same
 * treatment already applied to Status/Project on grn.php.
 *
 * Fix:
 * 1. Type column (#dnTable thead th, and its DataTable column definition)
 *    marked .d-print-none, matching the existing convention already used
 *    for the Actions column elsewhere in this codebase.
 * 2. Project's badge span gets a dedicated `dn-project-badge` class; a
 *    print-only rule strips its background/border/border-radius/padding so
 *    it prints as plain bold text instead of a pill.
 * 3. Font sizing mirrors ledger_report.php's #ledgerTable / grn.php's
 *    #grnTable exactly: header 7pt, body 7.5pt forced onto the cell itself
 *    AND everything nested inside it (so Bootstrap's .small/<small>/badges/
 *    inline font-size styles can't make one column read a different size
 *    than another).
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render. The fix was verified
 * live in a real browser session (dev.bms.local): injected the page's own
 * @media print rules as active on-screen styles (a native print dialog
 * can't be screenshotted), confirmed the Type column's header computes to
 * display:none, confirmed every one of the 10 remaining visible columns
 * computes to the exact same 10px (7.5pt) with the header row at 9.33333px
 * (7pt), and confirmed via computed style that the Project badge renders
 * with background:transparent, border:none, border-radius:0 — no oval
 * shape, same as Status/Project already fixed on grn.php.
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

echo "\n\033[1m═══ Delivery Notes list print — Type dropped, no Project oval, ledger-matched fonts ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/bms/grn/delivery_notes.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('delivery_notes.php — no syntax errors')
    : bad('delivery_notes.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('Type column dropped from print entirely');
str_contains($src, '<th style="width:90px;" class="d-print-none">Type</th>')
    ? ok('Type <th> carries d-print-none')
    : bad('Type header no longer marked d-print-none');
(preg_match("/data: 'dn_type',\s*className: 'text-center d-print-none',/", $src) === 1)
    ? ok("Type column's DataTable definition carries className: 'text-center d-print-none'")
    : bad("Type column's DataTable definition is missing d-print-none");

head('Project badge prints as plain text — no oval/pill shape');
str_contains($src, 'dn-project-badge')
    ? ok('project render() tags the badge with a dedicated dn-project-badge class')
    : bad('dn-project-badge class missing from the project column render()');
(preg_match('/#dnTable \.dn-project-badge\s*\{[^}]*background:\s*transparent[^}]*border:\s*none[^}]*border-radius:\s*0[^}]*padding:\s*0/s', $src) === 1)
    ? ok('#dnTable .dn-project-badge strips background/border/border-radius/padding in print')
    : bad('dn-project-badge print override incomplete — the oval shape may still render');

head('Font sizing mirrors ledger_report.php\'s #ledgerTable / grn.php\'s #grnTable exactly');
(preg_match('/#dnTable thead tr th\s*\{[^}]*font-size:\s*7pt\s*!important/s', $src) === 1)
    ? ok('#dnTable thead tr th is 7pt (matches #ledgerTable/#grnTable header exactly)')
    : bad('header font-size no longer matches the 7pt reference');
(preg_match('/#dnTable tbody td\s*\{\s*font-size:\s*7\.5pt\s*!important;\s*\}/s', $src) === 1)
    ? ok('#dnTable tbody td (the cell itself) is 7.5pt (matches the reference exactly)')
    : bad('base body cell font-size missing — a cell with no nested wrapper would fall back to some other ambient size');
(preg_match('/#dnTable td \*, #dnTable th \*\s*\{[^}]*font-size:\s*7\.5pt\s*!important/s', $src) === 1)
    ? ok('every cell\'s nested content (badges/strong/small/span) is also forced to 7.5pt')
    : bad('nested-content font-size override missing — badges/.small text could render a different size again');
(!preg_match('/#dnTable th, #dnTable td \{\s*font-size:\s*8pt/', $src))
    ? ok('the old flat 8pt th+td rule (pre-fix) is gone')
    : bad('the old flat 8pt th+td rule is still present — may conflict with the new tiered scheme');

head('Existing footer/page-break/width fixes untouched');
str_contains($src, '@page { margin: 10mm 8mm 16mm 8mm; size: auto; }')
    ? ok('the existing @page bottom margin is unchanged')
    : bad('the existing @page margin was unexpectedly modified');
str_contains($src, '#dnTableCard') && str_contains($src, 'page-break-inside: auto !important')
    ? ok('#dnTableCard page-break-inside override is unchanged')
    : bad('#dnTableCard page-break-inside override missing — blank-page regression risk');
