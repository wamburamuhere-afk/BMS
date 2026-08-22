<?php
/**
 * GRN list print — hidden column values, footer overlap, oval badges,
 * inconsistent column font sizes
 *   php tests/test_grn_print_layout_cli.php
 *
 * User-reported (round 1): printing app/bms/grn/grn.php in portrait cut
 * off/hid some column values, the shared fixed-position print footer
 * overlapped content at the bottom of the page, and the Status column's
 * rounded "oval" badge shape was unwanted in print.
 *
 * User-reported (round 2, with a real print/PDF screenshot): landscape
 * print STILL cut a row off under the footer even after round 1's fix (a
 * larger @page bottom margin on the generic site-wide .bms-print-footer),
 * and column values rendered at visibly different font sizes from each
 * other.
 *
 * User-reported (round 3, pointing at app/constant/reports/ledger_report.php
 * as the reference to copy): use ledger_report.php's exact font-size scheme
 * (header smaller than body, money column smallest + nowrap) and fix the
 * footer overlap "how it was fixed in ledger_report.php" — i.e. switch to
 * the canonical shared footer mechanism instead of further inflating the
 * generic one — and remove the oval around the Project column's value too.
 *
 * Root cause of the persistent footer overlap: grn.php was reserving space
 * for the generic, site-wide `.bms-print-footer` (from footer.php /
 * responsive.css, rendered on every app page) via an ever-larger @page
 * bottom margin — a losing game across orientations. Every other report
 * page in this codebase (ledger_report.php, income_statement.php,
 * trial_balance.php, ...) instead hides that generic footer during print
 * and uses the dedicated, purpose-built includes/print_footer_css.php +
 * print_footer_html.php pair (i_e_print.md §1-3: canonical
 * `@page { margin: 10mm 8mm 16mm 8mm; }`, no per-orientation override
 * needed once the correctly-sized footer is used).
 *
 * Font sizing now mirrors ledger_report.php's #ledgerTable exactly:
 * header 7pt, body 7.5pt (cell itself AND everything nested inside it, so
 * Bootstrap's .small/<small>/inline font-size styles can't produce a
 * different-looking column), money column (Total Value) 7pt + nowrap so a
 * large TZS figure never wraps or overflows into the next column.
 *
 * Root causes (round 1, unchanged from earlier rounds):
 * 1. Every column (including the hidden Actions column) was forced to an
 *    identical 8.33% width regardless of content.
 * 2. The site-wide `p, .card, section { page-break-inside: avoid }` rule
 *    treats the whole GRN table card as one unsplittable block, which for a
 *    list many rows tall pushes it entirely onto a later page (blank page 1).
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render. The fix was verified
 * live in a real browser session (dev.bms.local): injected the page's own
 * @media print rules as active on-screen styles (a native print dialog
 * can't be screenshotted), confirmed the generic .bms-print-footer computes
 * to display:none while the canonical .print-footer computes to
 * display:flex/position:fixed (no duplicate, no reliance on the unreliable
 * one), confirmed every body column computes to 10px (7.5pt) except Total
 * Value at 9.33333px (7pt, nowrap), every header cell at 9.33333px (7pt),
 * and confirmed via computed style that both the Status and Project badges
 * render with color:rgb(0,0,0), background:transparent, border:none,
 * border-radius:0 — no oval shape on either.
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

echo "\n\033[1m═══ GRN list print — canonical footer, ledger-matched fonts, no oval badges ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/bms/grn/grn.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('grn.php — no syntax errors')
    : bad('grn.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('Footer clearance — canonical shared footer (same mechanism as ledger_report.php)');
str_contains($src, '.bms-print-footer { display: none !important; }')
    ? ok('the generic site-wide print footer is hidden during print (no duplicate)')
    : bad('.bms-print-footer is not hidden — a duplicate/unreliable footer may render again');
str_contains($src, "require_once ROOT_DIR . '/includes/print_footer_css.php';")
    ? ok('the canonical print_footer_css.php is included')
    : bad('print_footer_css.php is not included — footer positioning/sizing reverts to none');
str_contains($src, "require_once ROOT_DIR . '/includes/print_footer_html.php';")
    ? ok('the canonical print_footer_html.php is included')
    : bad('print_footer_html.php is not included — no footer content will render at all');
(preg_match('/@page\s*\{\s*margin:\s*10mm 8mm 16mm 8mm;\s*\}/', $src) === 1)
    ? ok('GRN print carries the canonical 16mm-bottom @page margin (i_e_print.md §1, matches ledger_report.php exactly)')
    : bad('the canonical @page margin is missing or does not match the standard');
(!preg_match('/@media print and \(orientation:\s*landscape\)/', $src))
    ? ok('no per-orientation @page hack remains — the canonical footer needs none, in either orientation')
    : bad('a leftover per-orientation @page override was found — should no longer be necessary');

head('Blank-first-page guard — table card opts out of the unsplittable .card rule');
str_contains($src, 'id="grnTableCard"')
    ? ok('table wrapper card carries id="grnTableCard"')
    : bad('grnTableCard id missing from the markup');
(preg_match('/#grnTableCard\s*\{[^}]*page-break-inside:\s*auto\s*!important/s', $src) === 1)
    ? ok('#grnTableCard overrides page-break-inside back to auto')
    : bad('#grnTableCard does not override page-break-inside — a long list could get pushed entirely to page 2');

head('Equal 8.33% column-width bug is gone; replaced by content-proportional widths');
(!str_contains($src, 'width: 8.33% !important'))
    ? ok('the old equal-8.33%-per-column rule is removed')
    : bad('found the old width: 8.33% rule — the column-squeeze bug may have regressed');
str_contains($src, 'width: auto !important')
    ? ok('inline/JS column widths are reset before applying real percentages')
    : bad('missing the width:auto reset step ahead of the percentage widths');
foreach (['enable_projects', 'nth-child(1)', 'nth-child(4)', 'nth-child(9)'] as $needle) {
    str_contains($src, $needle)
        ? ok("contains \"$needle\" (proportional per-column width branch present)")
        : bad("missing \"$needle\" — proportional width scheme may be incomplete");
}
// Both branches (with/without projects) must each sum their visible-column widths to 100%.
foreach (['enabled' => true, 'disabled' => false] as $label => $isEnabled) {
    $marker = $isEnabled ? '<?php if ($enable_projects): ?>' : '<?php else: ?>';
    $endMarker = $isEnabled ? '<?php else: ?>' : '<?php endif; ?>';
    $start = strpos($src, $marker);
    $end = $start !== false ? strpos($src, $endMarker, $start) : false;
    if ($start === false || $end === false) { bad("could not locate the Project-$label width branch"); continue; }
    $branch = substr($src, $start, $end - $start);
    preg_match_all('/width:\s*(\d+)%\s*!important/', $branch, $m);
    $sum = array_sum(array_map('intval', $m[1] ?? []));
    ($sum === 100)
        ? ok("Project-$label branch: visible-column widths sum to 100% (got $sum%)")
        : bad("Project-$label branch: visible-column widths sum to $sum%, expected 100%");
}

head('Actions column dropped from print (freed width redistributed, not left blank)');
// The table markup + column definitions moved into a shared partial/module
// (includes/tables/grn_table.php + assets/js/tables/bms-grn-table.js) so the
// GRN tab on Supplier Details can reuse them instead of a second copy. Same
// facts, now assembled from a column-attrs array / declared in the JS module
// rather than typed inline into grn.php.
$grnTablePartial = file_get_contents($root . '/includes/tables/grn_table.php') ?: '';
$grnTableModule  = file_get_contents($root . '/assets/js/tables/bms-grn-table.js') ?: '';
str_contains($grnTablePartial, "'actions'        => ['label' => 'Actions',     'attrs' => 'class=\"text-center d-print-none\"']")
    ? ok('Actions <th> carries d-print-none')
    : bad('Actions header no longer marked d-print-none');
(preg_match("/data: null, orderable: false, className: 'd-print-none'/", $grnTableModule) === 1)
    ? ok("Actions column's DataTable definition carries className: 'd-print-none'")
    : bad("Actions column's DataTable definition is missing className: 'd-print-none'");

head('Header wrap + cell-bleed backstop (values can no longer overflow into a neighbouring column)');
str_contains($src, '#grnTable thead tr th') && str_contains($src, 'white-space: normal !important')
    ? ok('header labels wrap instead of clipping once columns get a narrower printed width')
    : bad('header white-space:normal override missing');
str_contains($src, '#grnTable td *, #grnTable th *')
    ? ok('inline max-width/white-space reset applied to nested cell content')
    : bad('nested-content bleed backstop missing');

head('Status/Project badges print as plain text — no oval/pill shape on either');
str_contains($src, 'grn-status-badge')
    ? ok('status render() tags the badge with a dedicated grn-status-badge class')
    : bad('grn-status-badge class missing from the status column render()');
str_contains($src, 'grn-project-badge')
    ? ok('project render() tags the badge with a dedicated grn-project-badge class')
    : bad('grn-project-badge class missing from the project column render()');
(preg_match('/#grnTable \.grn-status-badge,\s*#grnTable \.grn-project-badge\s*\{[^}]*background:\s*transparent[^}]*border:\s*none[^}]*border-radius:\s*0[^}]*padding:\s*0/s', $src) === 1)
    ? ok('both #grnTable .grn-status-badge and .grn-project-badge strip background/border/border-radius/padding in print')
    : bad('the combined status/project badge print override is missing or incomplete — an oval shape may still render on one or both');

head('Font sizing mirrors ledger_report.php\'s #ledgerTable exactly (header smaller than body, money column smallest)');
(preg_match('/#grnTable thead tr th\s*\{[^}]*font-size:\s*7pt\s*!important/s', $src) === 1)
    ? ok('#grnTable thead tr th is 7pt (matches #ledgerTable thead th exactly)')
    : bad('header font-size no longer matches ledger_report.php\'s 7pt reference');
(preg_match('/#grnTable tbody td\s*\{\s*font-size:\s*7\.5pt\s*!important;\s*\}/s', $src) === 1)
    ? ok('#grnTable tbody td (the cell itself, not just nested content) is 7.5pt (matches #ledgerTable tbody/tfoot td exactly)')
    : bad('base body cell font-size missing — a cell with no nested wrapper (e.g. S/NO) would fall back to some other ambient size');
(preg_match('/#grnTable td \*, #grnTable th \*\s*\{[^}]*font-size:\s*7\.5pt\s*!important/s', $src) === 1)
    ? ok('every cell\'s nested content (badges/strong/code/small/div/a) is also forced to 7.5pt')
    : bad('nested-content font-size override missing or no longer 7.5pt — badges/.small text could render a different size again');
(preg_match('/#grnTable td:nth-child\(9\), #grnTable td:nth-child\(9\) \*\s*\{\s*white-space:\s*nowrap\s*!important;\s*font-size:\s*7pt\s*!important;/s', $src) === 1)
    ? ok('Total Value (Project-enabled, column 9) gets nowrap + 7pt, same treatment ledger_report.php gives its money columns')
    : bad('Total Value column no longer gets the nowrap+7pt money-column treatment (Project-enabled branch)');
(preg_match('/#grnTable td:nth-child\(8\), #grnTable td:nth-child\(8\) \*\s*\{\s*white-space:\s*nowrap\s*!important;\s*font-size:\s*7pt\s*!important;/s', $src) === 1)
    ? ok('Total Value (Project-disabled, column 8) gets nowrap + 7pt, same treatment ledger_report.php gives its money columns')
    : bad('Total Value column no longer gets the nowrap+7pt money-column treatment (Project-disabled branch)');
