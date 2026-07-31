<?php
/**
 * RFQ list print — canonical shared footer, ledger_report.php font scheme,
 * blank-first-page fix, no oval Project badge
 *   php tests/test_rfq_print_layout_cli.php
 *
 * User request (with a real print/PDF screenshot of app/bms/purchase/
 * rfq.php showing page 1 entirely blank — the table only starting on page
 * 2): use app/constant/reports/ledger_report.php's font size/style when
 * printing, and make sure the page starts on the first page (not blank).
 *
 * Found along the way: rfq.php had its OWN local `.print-footer` HTML
 * block and CSS (a duplicate of the shared mechanism, "matches tenders"
 * per its own comment) instead of the canonical includes/print_footer_css.php
 * + print_footer_html.php pair every other report page in this codebase
 * uses (ledger_report.php, grn.php, delivery_notes.php, income_statement.php,
 * ...) — plus a non-canonical uniform 0.5in @page margin instead of the
 * documented i_e_print.md §1 value, a flat 8.5pt th+td font size (instead
 * of ledger's smaller-header/larger-body split), and no page-break-inside
 * override for #rfqReportContainer (the table's wrapping .card) — root
 * cause of the reported blank first page, same class of bug already fixed
 * on grn.php/delivery_notes.php/sales_orders.php etc: the global
 * responsive.css rule `p, .card, section { page-break-inside: avoid }`
 * treats a many-rows-tall card as one unsplittable block.
 *
 * Fix:
 * 1. Local print-footer HTML/CSS removed entirely; replaced with the
 *    canonical includes/print_footer_css.php + print_footer_html.php pair,
 *    with the generic .bms-print-footer explicitly hidden so there's no
 *    duplicate.
 * 2. @page margin changed to the canonical 10mm 8mm 16mm 8mm (i_e_print.md
 *    §1, same value as ledger_report.php/grn.php/delivery_notes.php).
 * 3. #rfqReportContainer gets page-break-inside:auto so a long RFQ list
 *    flows across pages instead of being pushed entirely past a blank
 *    page 1.
 * 4. Font sizing mirrors ledger_report.php's #ledgerTable / grn.php's
 *    #grnTable exactly: header 7pt, body 7.5pt (forced onto the cell
 *    itself and everything nested inside it).
 * 5. Project column's badge gets a dedicated `rfq-project-badge` class,
 *    stripped of background/border/border-radius/padding in print — same
 *    treatment already applied on grn.php/delivery_notes.php.
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render. The fix was verified
 * live in a real browser session (dev.bms.local): injected the page's own
 * @media print rules as active on-screen styles (a native print dialog
 * can't be screenshotted), confirmed .bms-print-footer computes to
 * display:none while the canonical .print-footer computes to
 * display:flex/position:fixed with the expected "Printed by ..." text,
 * confirmed #rfqReportContainer's page-break-inside computes to auto,
 * confirmed every one of the 8 visible columns computes to the exact same
 * 10px (7.5pt) with the header row at 9.33333px (7pt), and confirmed via
 * computed style that the Project badge renders with
 * background:transparent, border:none, border-radius:0 — no oval shape.
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

echo "\n\033[1m═══ RFQ list print — canonical footer, blank-page fix, ledger fonts, no oval ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/bms/purchase/rfq.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('rfq.php — no syntax errors')
    : bad('rfq.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('Canonical shared footer (no more local duplicate)');
(!str_contains($src, 'PRINT FOOTER (fixed, matches tenders)'))
    ? ok('the old local print-footer HTML block is removed')
    : bad('the old local print-footer HTML block is still present — duplicate footer risk');
(!preg_match('/\.print-footer\s*\{\s*position:fixed/', $src))
    ? ok('the old local .print-footer CSS block is removed')
    : bad('the old local .print-footer CSS is still present — would fight the canonical one');
str_contains($src, "require_once ROOT_DIR . '/includes/print_footer_css.php';")
    ? ok('the canonical print_footer_css.php is included')
    : bad('print_footer_css.php is not included');
str_contains($src, "require_once ROOT_DIR . '/includes/print_footer_html.php';")
    ? ok('the canonical print_footer_html.php is included')
    : bad('print_footer_html.php is not included — no footer content will render at all');
str_contains($src, '.bms-print-footer { display: none !important; }')
    ? ok('the generic site-wide print footer is hidden during print (no duplicate)')
    : bad('.bms-print-footer is not hidden — a duplicate footer may render again');

head('Canonical @page margin (i_e_print.md §1)');
(preg_match('/@page\s*\{\s*margin:\s*10mm 8mm 16mm 8mm;\s*\}/', $src) === 1)
    ? ok('RFQ print carries the canonical 16mm-bottom @page margin')
    : bad('the canonical @page margin is missing or does not match the standard');
(!str_contains($src, '0.5in 0.5in 0.5in 0.5in'))
    ? ok('the old non-canonical uniform 0.5in @page margin is gone')
    : bad('the old 0.5in-all-round @page margin is still present');

head('Blank-first-page guard — table card opts out of the unsplittable .card rule');
(preg_match('/#rfqReportContainer\s*\{[^}]*page-break-inside:\s*auto\s*!important/s', $src) === 1)
    ? ok('#rfqReportContainer overrides page-break-inside back to auto')
    : bad('#rfqReportContainer does not override page-break-inside — page 1 could stay blank again');

head('Font sizing mirrors ledger_report.php\'s #ledgerTable / grn.php\'s #grnTable exactly');
(preg_match('/#rfqTable th\{[^}]*font-size:7pt!important/s', $src) === 1)
    ? ok('#rfqTable th is 7pt (matches the ledger_report.php header reference)')
    : bad('header font-size no longer matches the 7pt reference');
(preg_match('/#rfqTable td\{[^}]*font-size:7\.5pt!important/s', $src) === 1)
    ? ok('#rfqTable td (the cell itself) is 7.5pt (matches the reference exactly)')
    : bad('base body cell font-size missing or no longer 7.5pt');
str_contains($src, '#rfqTable td *, #rfqTable th * { font-size: 7.5pt !important;')
    ? ok('every cell\'s nested content (badges/spans) is also forced to 7.5pt')
    : bad('nested-content font-size override missing — the Project badge/spans could render a different size again');
(!str_contains($src, 'font-size:8.5pt'))
    ? ok('the old flat 8.5pt th+td rule (pre-fix) is gone')
    : bad('the old flat 8.5pt th+td rule is still present — may conflict with the new tiered scheme');

head('Project badge prints as plain text — no oval/pill shape');
str_contains($src, 'rfq-project-badge')
    ? ok('project render() tags the badge with a dedicated rfq-project-badge class')
    : bad('rfq-project-badge class missing from the project column render()');
(preg_match('/#rfqTable \.rfq-project-badge\s*\{[^}]*background:\s*transparent[^}]*border:\s*none[^}]*border-radius:\s*0[^}]*padding:\s*0/s', $src) === 1)
    ? ok('#rfqTable .rfq-project-badge strips background/border/border-radius/padding in print')
    : bad('rfq-project-badge print override incomplete — the oval shape may still render');
