<?php
/**
 * Financial Reports — Print Standard Compliance Guard
 *
 * Locks in the normalization applied to the five Reports → Financial Reports
 * pages so they all follow the I/E Print Standard (`i_e_print.md`):
 *
 *   1) No duplicate company logo + name block inside the print-header.
 *      The shared app header already prints the company logo/name once at
 *      the top; the report's own print-header must NOT re-emit them.
 *
 *   2) Canonical @page margin (i_e_print.md §1):
 *          @page { margin: 10mm 8mm 16mm 8mm; }
 *      i.e. top 1.0 cm, right 0.8 cm, bottom 1.6 cm, left 0.8 cm.
 *
 *   3) Shared print footer (i_e_print.md §3 + §9 rules 2-3):
 *          includes/print_footer_css.php   in the page styles
 *          includes/print_footer_html.php  inside <div class="d-none d-print-block">
 *
 * Each file must still keep its report title heading (PROFIT & LOSS,
 * BALANCE SHEET, etc.) so the suite verifies that titles are preserved.
 *
 * Run:  php tests/test_financial_reports_print_standard_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ Financial Reports — Print Standard Guard ═══\033[0m\n";

// File → expected report title (must still appear after normalization)
$reports = [
    'app/bms/invoice/income_statement.php'       => 'PROFIT & LOSS STATEMENT',
    'app/constant/reports/balance_sheet.php'     => 'BALANCE SHEET REPORT',
    'app/constant/reports/cash_flow.php'         => 'CASH FLOW STATEMENT',
    'app/constant/accounts/trial_balance.php'    => 'TRIAL BALANCE REPORT',
    'app/constant/reports/ledger_report.php'     => 'GENERAL LEDGER REPORT',
];

// ─────────────────────────────────────────────────────────────────────────────
section('1. All five files exist and pass PHP syntax check');
// ─────────────────────────────────────────────────────────────────────────────

foreach ($reports as $rel => $_) {
    $f = $root . '/' . $rel;
    check(is_file($f), "$rel exists", "$rel is missing");

    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    check($code === 0, "$rel passes php -l", "$rel has PHP syntax errors: " . implode(' | ', $out));
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. No duplicated company-logo/name inside the print-header block');
// ─────────────────────────────────────────────────────────────────────────────
//
// Bug class this guards against: the print version used to emit the company
// logo + name a SECOND time inside the report, on top of the one already
// included by the shared app header. The duplicate must not return.

foreach ($reports as $rel => $_) {
    $src = file_get_contents($root . '/' . $rel) ?: '';

    check(
        !preg_match("/\\\$c_name\\s*=\\s*getSetting\\(\\s*['\"]company_name['\"]/", $src),
        "$rel — no \$c_name = getSetting('company_name') in print-header",
        "$rel — \$c_name assignment from getSetting('company_name') reappeared (duplicate header)"
    );
    check(
        !preg_match("/\\\$c_logo\\s*=\\s*getSetting\\(\\s*['\"]company_logo['\"]/", $src),
        "$rel — no \$c_logo = getSetting('company_logo') in print-header",
        "$rel — \$c_logo assignment from getSetting('company_logo') reappeared"
    );
    check(
        !preg_match('/safe_output\s*\(\s*\$c_name\s*\)/', $src),
        "$rel — no <?= safe_output(\$c_name) ?> output",
        "$rel — safe_output(\$c_name) output reappeared in the print-header"
    );
    check(
        !preg_match('/<img\s+[^>]*\$c_logo/', $src),
        "$rel — no <img ... \$c_logo ...> in print-header",
        "$rel — duplicate logo <img> reappeared"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Canonical @page margin (10mm 8mm 16mm 8mm) — i_e_print.md §1');
// ─────────────────────────────────────────────────────────────────────────────

foreach ($reports as $rel => $_) {
    $src = file_get_contents($root . '/' . $rel) ?: '';
    check(
        (bool) preg_match('/@page\s*\{\s*margin:\s*10mm\s+8mm\s+16mm\s+8mm\s*;\s*\}/', $src),
        "$rel — declares canonical @page { margin: 10mm 8mm 16mm 8mm; }",
        "$rel — missing canonical @page margin (must be 10mm 8mm 16mm 8mm per i_e_print.md §1)"
    );
    check(
        !preg_match('/@page\s*\{\s*margin:\s*1\.5cm\s*;?\s*\}/', $src),
        "$rel — legacy 1.5cm @page margin is gone",
        "$rel — legacy '@page { margin: 1.5cm; }' still present, conflicts with canonical"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. Shared print footer wired in — i_e_print.md §3, §9 rules 2-3');
// ─────────────────────────────────────────────────────────────────────────────

foreach ($reports as $rel => $_) {
    $src = file_get_contents($root . '/' . $rel) ?: '';

    check(
        str_contains($src, "includes/print_footer_css.php"),
        "$rel — includes shared print_footer_css.php",
        "$rel — does NOT include includes/print_footer_css.php"
    );
    check(
        str_contains($src, "includes/print_footer_html.php"),
        "$rel — includes shared print_footer_html.php",
        "$rel — does NOT include includes/print_footer_html.php"
    );

    // The HTML include must be wrapped in d-none d-print-block so the footer
    // stays hidden on the screen UI (where the regular app footer takes over)
    // and only appears on the printed page.
    $hasWrap = (bool) preg_match(
        '/<div\s+class="[^"]*\bd-none\b[^"]*\bd-print-block\b[^"]*"[^>]*>[\s\S]*?print_footer_html\.php/i',
        $src
    );
    check(
        $hasWrap,
        "$rel — print_footer_html.php is wrapped in <div class=\"d-none d-print-block\">",
        "$rel — print_footer_html.php is NOT inside a d-none d-print-block wrapper (would show on screen)"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Report titles preserved — we didn\'t accidentally delete them');
// ─────────────────────────────────────────────────────────────────────────────

foreach ($reports as $rel => $title) {
    $src = file_get_contents($root . '/' . $rel) ?: '';
    check(
        str_contains($src, $title),
        "$rel — still contains its report title \"$title\"",
        "$rel — the report title \"$title\" has been removed (header surgery went too far)"
    );

    // Print-header wrapper must remain.
    check(
        (bool) preg_match('/<div\s+class="[^"]*\bprint-header\b/', $src),
        "$rel — keeps the <div class=\"print-header...\"> wrapper",
        "$rel — the print-header wrapper was removed (only its inner duplicate content should be removed)"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Trial Balance — divider correctly placed inside print-header');
// ─────────────────────────────────────────────────────────────────────────────
//
// Bug class this guards against: the blue divider was originally OUTSIDE the
// `<div class="print-header d-none d-print-block">` wrapper, which (a) made
// the line visible on the screen UI and (b) combined with the
// `.print-header { border-bottom: 3px solid #0d6efd; }` rule in the @media
// print block to produce a doubled blue line on the printed page — the
// "double header" the user reported.

$trialBalance = $root . '/app/constant/accounts/trial_balance.php';
$tbSrc        = is_file($trialBalance) ? file_get_contents($trialBalance) : '';

// The divider must live INSIDE the print-header wrapper. This regex looks
// for the print-header opening div and asserts that the border-bottom div
// appears before the wrapper closes.
$insideWrapper = (bool) preg_match(
    '/<div\s+class="[^"]*\bprint-header\b[^"]*"[^>]*>[\s\S]*?border-bottom:\s*3px\s+solid\s+#0d6efd[\s\S]*?<\/div>\s*<\/div>/i',
    $tbSrc
);
check(
    $insideWrapper,
    'trial_balance.php — blue divider lives INSIDE the print-header wrapper',
    'trial_balance.php — blue divider is OUTSIDE the print-header wrapper (visible on screen + doubles on print)'
);

// The @media print rule must not add a SECOND border-bottom to .print-header
// itself. Otherwise the divider stacks with the explicit divider div above
// to produce a visible doubled line.
check(
    !preg_match('/\.print-header\s*\{[^}]*border-bottom[^}]*\}/i', $tbSrc),
    'trial_balance.php — .print-header CSS rule has no border-bottom (avoid stacking)',
    'trial_balance.php — .print-header still has border-bottom in its CSS, will stack with the inline divider'
);

// ─────────────────────────────────────────────────────────────────────────────
section('7. General Ledger — no orphan Web/Email/TIN/VRN paragraph block');
// ─────────────────────────────────────────────────────────────────────────────
//
// Bug class this guards against: the print-header carried two <p> tags
// emitting Web/Email and TIN/VRN derived from $c_web/$c_email/$c_tin/$c_vrn
// variables that were never defined anywhere in the file. The conditionals
// produced no text but the <p> tags still consumed vertical space, looking
// like blank header rows above the actual title — the "not well arranged"
// state the user reported.

$ledger = $root . '/app/constant/reports/ledger_report.php';
$lgSrc  = is_file($ledger) ? file_get_contents($ledger) : '';

check(
    !preg_match('/\$c_web|\$c_email|\$c_tin|\$c_vrn/', $lgSrc),
    'ledger_report.php — no undefined $c_web/$c_email/$c_tin/$c_vrn references remain',
    'ledger_report.php — still references undefined $c_web/$c_email/$c_tin/$c_vrn (orphan empty paragraphs above title)'
);

check(
    !str_contains($lgSrc, '"Web: "') && !str_contains($lgSrc, '"Email: "')
 && !str_contains($lgSrc, '"TIN: "') && !str_contains($lgSrc, '"VRN: "'),
    'ledger_report.php — no Web/Email/TIN/VRN paragraph builders in print-header',
    'ledger_report.php — Web/Email or TIN/VRN paragraph still present in print-header'
);

// The print-header must now go DIRECTLY from the d-print-block wrapper opening
// into the title <div class="mt-3 text-center">, with nothing in between.
$directTitle = (bool) preg_match(
    '/<div\s+class="[^"]*\bprint-header\b[^"]*"[^>]*>\s*<div\s+class="\s*mt-3\s+text-center\s*"/i',
    $lgSrc
);
check(
    $directTitle,
    'ledger_report.php — print-header opens directly into the title block (matches income_statement / balance_sheet)',
    'ledger_report.php — print-header has content between the wrapper and the title block (orphan rows persist)'
);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Trial Balance — compact print layout (fit table on page 1)');
// ─────────────────────────────────────────────────────────────────────────────
//
// Bug class this guards against: trial_balance.php previously wasted ~75-100px
// of vertical space at the top of every printed page, causing the account
// table to overflow to page 2 even when it would otherwise fit on page 1.
// Tightened in update 180 to match income_statement.php's compact structure.

$tbSrc = file_get_contents($root . '/app/constant/accounts/trial_balance.php') ?: '';

check(
    !preg_match('/<div\s+class="[^"]*\bbg-light-subtle\b/', $tbSrc),
    'trial_balance.php — main container no longer carries bg-light-subtle',
    'trial_balance.php — bg-light-subtle is back; creates visible background "block" on print'
);

check(
    !preg_match('/^\s*\.print-header\s*\{\s*display:\s*none\s*;?\s*\}/m', $tbSrc),
    'trial_balance.php — no standalone .print-header { display: none; } outside @media print',
    'trial_balance.php — redundant standalone .print-header { display: none; } reappeared'
);

check(
    !preg_match('/\.card\s*\{[^}]*margin-bottom:\s*20px[^}]*!important/i', $tbSrc),
    'trial_balance.php — @media print no longer adds 20px margin-bottom to every .card',
    'trial_balance.php — 20px card margin-bottom is back; wastes vertical space on print'
);

// Print-header outer wrapper margin must be mb-2 (tightened) not mb-4 (loose).
check(
    (bool) preg_match('/<div\s+class="print-header\s+d-none\s+d-print-block\s+text-center\s+mb-2"/', $tbSrc),
    'trial_balance.php — print-header uses tight margin mb-2',
    'trial_balance.php — print-header uses loose margin (mb-4 or other); regressed from update 180'
);

// Print Summary Cards wrapper margin must be mb-2 (tightened) not mb-4 (loose).
$summaryCardsTight = (bool) preg_match(
    '/<!--\s*Print Summary Cards\s*-->\s*<div\s+class="d-none\s+d-print-block\s+mb-2"/i',
    $tbSrc
);
check(
    $summaryCardsTight,
    'trial_balance.php — Print Summary Cards wrapper uses tight margin mb-2',
    'trial_balance.php — Print Summary Cards wrapper uses loose margin (mb-4 or other)'
);

// Inner card padding tightened from 10px to 6px (saves ~25px height across 3 cards).
$cardsAtSixPx = preg_match_all('/padding:\s*6px;\s*border-radius:\s*0;\s*text-align:\s*center/i', $tbSrc);
check(
    $cardsAtSixPx >= 3,
    "trial_balance.php — all 3 Print Summary Cards use 6px padding (found $cardsAtSixPx)",
    "trial_balance.php — not all 3 Print Summary Cards use 6px padding (found $cardsAtSixPx)"
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ Financial reports print standard intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Financial reports print standard regression — see failures above.\033[0m\n\n";
exit(1);
