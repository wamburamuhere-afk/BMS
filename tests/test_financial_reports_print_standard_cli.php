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
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ Financial reports print standard intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Financial reports print standard regression — see failures above.\033[0m\n\n";
exit(1);
