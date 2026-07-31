<?php
/**
 * GRN list print — hidden column values, footer overlap, oval status badges,
 * inconsistent column font sizes, landscape-specific footer cut-off
 *   php tests/test_grn_print_layout_cli.php
 *
 * User-reported (round 1): printing app/bms/grn/grn.php in portrait cut
 * off/hid some column values, the shared fixed-position print footer
 * overlapped content at the bottom of the page, and the Status column's
 * rounded "oval" badge shape was unwanted in print.
 *
 * User-reported (round 2, with a real print/PDF screenshot): landscape
 * print STILL cut content off under the footer even after round 1's fix,
 * and column values rendered at visibly different font sizes from each
 * other (most columns wrap their value in Bootstrap's .small/<small>,
 * which sizes ~87.5% of its parent — while the Date column's plain <span>
 * has no such wrapper and printed at the full size — plus the Status badge
 * carried its own inline style="font-size:0.7rem" from the on-screen view).
 *
 * Root causes, matching the same class of bug already fixed on Sales
 * Orders/Quotations/Supplier/Warehouse Inventory print:
 * 1. Every column (including the hidden Actions column) was forced to an
 *    identical 8.33% width regardless of content — a long Supplier/GRN-number
 *    cell got squeezed to the same width as a short Items/S-NO cell, so
 *    content had nowhere to go but clip/overflow.
 * 2. No extra bottom @page clearance for the shared .bms-print-footer
 *    (fixed, bottom:0 on every printed page) — the standard fix already
 *    proven on Customer/Supplier/Sub-Contractor/Delivery-Notes/Quotations/
 *    Sales-Orders print is extra @page bottom margin (vs the global 15mm).
 *    Landscape's page is physically shorter than portrait's (width/height
 *    swap), so the same absolute margin leaves proportionally less room —
 *    landscape gets an additional bump on top of the base fix.
 * 3. The site-wide `p, .card, section { page-break-inside: avoid }` rule
 *    treats the whole GRN table card as one unsplittable block, which for a
 *    list many rows tall pushes it entirely onto a later page (blank page 1).
 * 4. Status rendered as a Bootstrap `.badge` pill even in print.
 * 5. Most cell values were wrapped in Bootstrap's .small/<small>/inline
 *    font-size styles, rendering at inconsistent sizes relative to the
 *    unwrapped Date column.
 *
 * Fix: explicit content-proportional percentage widths (summing to 100%)
 * for every VISIBLE column, Actions dropped from print via .d-print-none
 * (freeing its width rather than leaving it reserved/blank), extra @page
 * bottom margin (larger again specifically for landscape orientation),
 * #grnTableCard page-break-inside override, an ID-qualified
 * `#grnTable .grn-status-badge` print rule that strips background/border/
 * border-radius/padding so Status prints as plain bold text instead of a
 * pill, and a `#grnTable td *, #grnTable th *` rule that forces every cell's
 * value AND everything nested inside it to the exact same font-size
 * (matching the Date column), with headers set one point size larger and
 * bold via a specificity-boosted `#grnTable thead tr th` selector (needed to
 * beat an unrelated, equally-specific #grnTable thead th rule declared later
 * in the file for the on-screen view).
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render. The fix was also
 * verified live in a real browser session (dev.bms.local): injected the
 * page's own @media print rules as active on-screen styles (since a native
 * print dialog can't be screenshotted) and confirmed no column value is
 * clipped; confirmed via computed style that the Status badge renders with
 * color:rgb(0,0,0), background:transparent, border:none, border-radius:0 —
 * no oval shape; and confirmed every column's leaf element (CODE, SPAN, DIV,
 * STRONG — GRN#, Date, Supplier, Total Value, etc.) computes to the exact
 * same 10.6667px (8pt), with every header cell at 12px (9pt) bold.
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

echo "\n\033[1m═══ GRN list print — layout, footer clearance, and no oval status badge ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/bms/grn/grn.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('grn.php — no syntax errors')
    : bad('grn.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('Footer clearance — extra bottom @page margin, larger again for landscape');
str_contains($src, '@page { margin: 10mm 8mm 20mm 8mm; size: auto; }')
    ? ok('GRN print carries the 20mm-bottom base @page margin')
    : bad('extra bottom @page clearance missing — last row may sit under the fixed print footer again');
(preg_match('/@media print and \(orientation:\s*landscape\)\s*\{\s*@page\s*\{\s*margin:\s*10mm 8mm 24mm 8mm;/s', $src) === 1)
    ? ok('landscape gets an additional bottom-margin bump (24mm) on top of the base fix')
    : bad('landscape-specific extra footer clearance is missing — a real cut-off was reported here');

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
str_contains($src, '<th class="text-center d-print-none">Actions</th>')
    ? ok('Actions <th> carries d-print-none')
    : bad('Actions header no longer marked d-print-none');
(preg_match("/data: null,\s*orderable: false,\s*className: 'd-print-none'/", $src) === 1)
    ? ok("Actions column's DataTable definition carries className: 'd-print-none'")
    : bad("Actions column's DataTable definition is missing className: 'd-print-none'");

head('Header wrap + cell-bleed backstop (values can no longer overflow into a neighbouring column)');
str_contains($src, '#grnTable thead tr th') && str_contains($src, 'white-space: normal !important')
    ? ok('header labels wrap instead of clipping once columns get a narrower printed width')
    : bad('header white-space:normal override missing');
str_contains($src, '#grnTable td *, #grnTable th *')
    ? ok('inline max-width/white-space reset applied to nested cell content')
    : bad('nested-content bleed backstop missing');

head('Status badge prints as plain text — no oval/pill shape');
str_contains($src, 'grn-status-badge')
    ? ok('status render() tags the badge with a dedicated grn-status-badge class')
    : bad('grn-status-badge class missing from the status column render()');
(preg_match('/#grnTable\s+\.grn-status-badge\s*\{[^}]*background:\s*transparent[^}]*border:\s*none[^}]*border-radius:\s*0[^}]*padding:\s*0/s', $src) === 1)
    ? ok('#grnTable .grn-status-badge strips background/border/border-radius/padding in print')
    : bad('grn-status-badge print override incomplete — the oval shape may still render');

head('Every column\'s value renders at the same font-size (matching Date), headers +1pt bold');
(preg_match('/#grnTable td \*, #grnTable th \*\s*\{[^}]*font-size:\s*8pt\s*!important/s', $src) === 1)
    ? ok('every cell AND its nested content (badges/strong/code/small/div) is forced to 8pt in print')
    : bad('nested-content font-size override missing — badges/.small text will render smaller/larger than Date again');
(preg_match('/#grnTable thead tr th\s*\{[^}]*font-size:\s*9pt\s*!important[^}]*font-weight:\s*700\s*!important/s', $src) === 1)
    ? ok('#grnTable thead tr th is 9pt (Date\'s 8pt + 1) and bold')
    : bad('header font-size/weight override missing or not exactly 1pt above body text');
(preg_match('/#grnTable \.grn-status-badge\s*\{[^}]*font-size:\s*8pt\s*!important/s', $src) === 1)
    ? ok('#grnTable .grn-status-badge explicitly matches the 8pt body size (overrides its own inline font-size:0.7rem)')
    : bad('grn-status-badge no longer explicitly matches the body font-size');
