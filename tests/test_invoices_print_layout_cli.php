<?php
/**
 * Invoices list print — canonical shared footer (no more overlapping the
 * last row), font sizing matches ledger_report.php
 *   php tests/test_invoices_print_layout_cli.php
 *
 * User request (with a real print/PDF screenshot showing app/bms/invoice/
 * invoices.php's landscape print with a row's content overlapped by the
 * shared print footer): use the same font size as ledger_report.php, and
 * stop the footer hiding rows the same way it was fixed on ledger_report.php.
 *
 * Root cause: invoices.php had NO @page bottom margin at all beyond the
 * browser default (`@page { size: auto; }` only) — the shared, fixed-
 * position .bms-print-footer had no reserved band to sit in, so it
 * overlapped whatever content happened to land at the very bottom of a
 * page. Same class of bug already fixed on grn.php/delivery_notes.php/
 * rfq.php: switch to the canonical includes/print_footer_css.php +
 * print_footer_html.php pair (i_e_print.md §1-3: @page bottom margin
 * 16mm, the correctly-sized dedicated footer), hiding the generic
 * .bms-print-footer so there's no duplicate.
 *
 * Font sizing previously used an ad-hoc 8pt table base plus a 6.5pt
 * override JUST for badges/progress bars — two different sizes on the
 * same row depending on which column you looked at. Now mirrors
 * ledger_report.php's #ledgerTable / grn.php's #grnTable exactly: header
 * 7pt, body 7.5pt forced onto the cell itself AND everything nested
 * inside it (Type/Status badges, progress bars), so every column reads
 * the same size.
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render. The fix was verified
 * live in a real browser session (dev.bms.local): injected the page's own
 * @media print rules as active on-screen styles (a native print dialog
 * can't be screenshotted), confirmed .bms-print-footer computes to
 * display:none while the canonical .print-footer computes to
 * display:flex, and confirmed every one of the 10 visible columns'
 * leaf elements (A, SPAN, STRONG, DIV — Invoice#, Type/Status badges,
 * Customer, progress bar) computes to the exact same 10px (7.5pt), with
 * the header row at 9.33333px (7pt).
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

echo "\n\033[1m═══ Invoices list print — canonical footer + ledger_report.php font scheme ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/bms/invoice/invoices.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('invoices.php — no syntax errors')
    : bad('invoices.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('Canonical shared footer (was missing entirely before this fix)');
str_contains($src, '.bms-print-footer { display: none !important; }')
    ? ok('the generic site-wide print footer is hidden during print (no duplicate)')
    : bad('.bms-print-footer is not hidden — a duplicate footer may render');
str_contains($src, "require_once ROOT_DIR . '/includes/print_footer_css.php';")
    ? ok('the canonical print_footer_css.php is included')
    : bad('print_footer_css.php is not included — footer positioning/sizing missing');
str_contains($src, "require_once ROOT_DIR . '/includes/print_footer_html.php';")
    ? ok('the canonical print_footer_html.php is included')
    : bad('print_footer_html.php is not included — no footer content will render at all');

head('Canonical @page bottom margin (was completely absent — root cause of the footer overlap)');
(preg_match('/@page\s*\{\s*size:\s*auto;\s*margin:\s*10mm 8mm 16mm 8mm;\s*\}/', $src) === 1)
    ? ok('invoices.php print carries the canonical 16mm-bottom @page margin')
    : bad('the canonical @page margin is missing or does not match the standard');
(!preg_match('/@page\s*\{\s*size:\s*auto;\s*\}/', $src))
    ? ok('the old margin-less @page{size:auto;} rule is gone')
    : bad('the old @page{size:auto;} with no margin is still present — footer overlap could return');

head('Font sizing mirrors ledger_report.php\'s #ledgerTable / grn.php\'s #grnTable exactly');
(preg_match('/#invoicesTable th\s*\{\s*font-size:\s*7pt\s*!important;\s*\}/', $src) === 1)
    ? ok('#invoicesTable th is 7pt (matches the ledger_report.php header reference)')
    : bad('header font-size no longer matches the 7pt reference');
(preg_match('/#invoicesTable td\s*\{\s*font-size:\s*7\.5pt\s*!important;\s*\}/', $src) === 1)
    ? ok('#invoicesTable td (the cell itself) is 7.5pt (matches the reference exactly)')
    : bad('base body cell font-size missing or no longer 7.5pt');
(preg_match('/#invoicesTable td \*, #invoicesTable th \*\s*\{[^}]*font-size:\s*7\.5pt\s*!important/s', $src) === 1)
    ? ok('every cell\'s nested content (Type/Status badges, progress bars) is also forced to 7.5pt')
    : bad('nested-content font-size override missing — badges could render a different size again');
(!str_contains($src, "font-size: 8pt !important;\n    }\n    #invoicesTable th, #invoicesTable td"))
    ? ok('the old flat 8pt table-wide rule (pre-fix) is gone')
    : bad('the old flat 8pt table-wide rule is still present — may conflict with the new tiered scheme');
(!str_contains($src, "#invoicesTable td .badge, #invoicesTable td .progress { font-size: 6.5pt !important; }"))
    ? ok('the old badge/progress-only 6.5pt override (a THIRD inconsistent size) is gone')
    : bad('the old 6.5pt badge/progress override is still present — columns would read inconsistent sizes again');

head('Existing proportional column widths untouched (already correct before this fix)');
str_contains($src, 'inv_print_widths')
    ? ok('the existing content-proportional column-width scheme is unchanged')
    : bad('the existing column-width scheme was unexpectedly removed');
