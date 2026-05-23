<?php
/**
 * CSRF_TOKEN Redeclaration Guard — Bug-class Regression Suite
 *
 * `header.php` declares `const CSRF_TOKEN = '...'` globally so every page's
 * AJAX call can attach a CSRF token. Any page that ALSO declares
 * `const CSRF_TOKEN = ...` inside its own <script> block throws:
 *
 *     Uncaught SyntaxError: Identifier 'CSRF_TOKEN' has already been declared
 *
 * That SyntaxError aborts the ENTIRE <script> block on that page — every
 * onclick, every form submit, every Bootstrap modal stops working silently
 * (e.g. the "Record Invoice" button on received_invoices.php).
 *
 * This suite scans every PHP file under `app/` and FAILS the push gate if
 * any file redeclares the constant. It also keeps a positive sanity-check
 * that received_invoices.php's "Record Invoice" button remains correctly
 * wired (that page is where the bug was first reported).
 *
 * Run:  php tests/test_csrf_token_redeclaration_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;
$skips    = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function skip(string $m): void    { global $skips;    $skips++;    echo "  \033[33m⊘\033[0m  $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ CSRF_TOKEN Redeclaration Guard ═══\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. header.php is the SOLE canonical declarer of const CSRF_TOKEN');
// ─────────────────────────────────────────────────────────────────────────────
$headerPath = "$root/header.php";
if (!file_exists($headerPath)) {
    fail('header.php is missing — cannot verify canonical declaration');
} else {
    $header = file_get_contents($headerPath);
    $headerCount = preg_match_all('/^\s*const\s+CSRF_TOKEN\s*=/m', $header);
    check($headerCount === 1,
        'header.php declares const CSRF_TOKEN exactly once (canonical source)',
        "header.php declares const CSRF_TOKEN $headerCount time(s) — must be exactly 1");
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. No page under app/ redeclares const CSRF_TOKEN');
// ─────────────────────────────────────────────────────────────────────────────
$offenders = [];
$appDir = "$root/app";
if (is_dir($appDir)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($rii as $f) {
        if ($f->getExtension() !== 'php') continue;
        $path = $f->getPathname();
        $content = file_get_contents($path);
        if (preg_match('/^\s*const\s+CSRF_TOKEN\s*=/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
            $rel = str_replace([$root . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
            $offenders[] = "$rel:$line";
        }
    }
}
if (empty($offenders)) {
    pass('No page under app/ redeclares const CSRF_TOKEN — every page script runs cleanly');
} else {
    foreach ($offenders as $o) {
        fail("$o redeclares const CSRF_TOKEN — header.php already declares it; this throws a JS SyntaxError and aborts the page's script");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. received_invoices.php — Record Invoice button + openAddModal() intact');
// ─────────────────────────────────────────────────────────────────────────────
$RI_REL  = 'app/bms/invoice/received_invoices.php';
$RI_PATH = "$root/$RI_REL";
$ri = file_exists($RI_PATH) ? file_get_contents($RI_PATH) : '';
check(str_contains($ri, 'onclick="openAddModal()"'),
    'received_invoices.php: Record Invoice button calls openAddModal()',
    'received_invoices.php: Record Invoice button is no longer wired');
check(preg_match('/function\s+openAddModal\s*\(/', $ri) === 1,
    'received_invoices.php: openAddModal() defined exactly once',
    'received_invoices.php: openAddModal() missing or duplicated');
check(str_contains($ri, "new bootstrap.Modal(document.getElementById('invoiceModal')).show()"),
    'received_invoices.php: openAddModal() opens the #invoiceModal',
    'received_invoices.php: openAddModal() does not open the #invoiceModal');
check(str_contains($ri, 'CSRF_TOKEN'),
    'received_invoices.php: still uses CSRF_TOKEN (now sourced from header.php)',
    'received_invoices.php: no longer references CSRF_TOKEN — AJAX calls would fail CSRF checks');

// ─────────────────────────────────────────────────────────────────────────────
section('4. PHP syntax (php -l) on the files this guard protects');
// ─────────────────────────────────────────────────────────────────────────────
$linted = [
    'app/bms/invoice/received_invoices.php',
    'app/bms/customer/customer_details.php',
    'app/constant/settings/backup_restore.php',
    'header.php',
];
foreach ($linted as $rel) {
    $path = "$root/$rel";
    if (!file_exists($path)) { fail("Missing file: $rel"); continue; }
    if (!function_exists('shell_exec')) { skip("shell_exec disabled — cannot lint $rel"); continue; }
    $out = (string) shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    check(!preg_match('/(Parse|Fatal) error/i', $out),
        "Syntax OK: $rel",
        "Syntax error in $rel —\n     " . trim($out));
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m════════════════════════════════════════\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes test(s) passed";
    echo $skips ? " ($skips skipped) — safe to push.\033[0m\n" : " — safe to push.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ $failures test(s) FAILED  |  $passes passed  |  $skips skipped\033[0m\n";
echo "\033[31mFix the errors above — DO NOT push.\033[0m\n";
echo "\033[1m════════════════════════════════════════\033[0m\n\n";
exit(1);
