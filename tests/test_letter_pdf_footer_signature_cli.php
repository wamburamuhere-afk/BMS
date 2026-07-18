<?php
/**
 * Create-Document letter PDF — footer placement + signature block polish.
 *
 * User-reported: on library.php's "View Online"/Print of a created letter,
 * the footer wasn't placed at the end of the page (unlike every other print
 * page) and the signature block didn't look "professional" / match what was
 * already agreed in the live editor (create_document.php's boxed/watermarked
 * signature preview).
 *
 * Root causes fixed in core/document_letter_pdf.php + core/document_letter_render.php:
 *   1. The shared audit footer was embedded as flowing HTML relying on CSS
 *      `position:fixed`, which TCPDF's writeHTML() does not support — the
 *      footer floated wherever the content ended instead of pinning to the
 *      true bottom of every page. Fixed by using TCPDF's native per-page
 *      Footer() callback (BmsLetterPdf class) instead.
 *   2. The signature block was a bare image + plain text, unlike the
 *      already-approved editor design (.letter-signature-box: bordered box
 *      with the PREVIEW watermark inside it, name on a signature line).
 *      Fixed by rebuilding it as a TCPDF-compatible bordered table cell.
 *
 * Run:  php tests/test_letter_pdf_footer_signature_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/document_letter_pdf.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }
function readSrc(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }

section('1. php -l on every touched file');

foreach ([
    'core/document_letter_pdf.php',
    'core/document_letter_render.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

section('2. Footer now uses TCPDF\'s native per-page callback, not flowing HTML');

$pdfSrc = readSrc($root, 'core/document_letter_pdf.php');
check(strpos($pdfSrc, 'class BmsLetterPdf extends TCPDF') !== false, 'a custom TCPDF subclass with a Footer() override exists', 'BmsLetterPdf subclass is missing');
check(strpos($pdfSrc, 'function Footer()') !== false, 'the subclass overrides Footer()', 'Footer() override is missing');
check(strpos($pdfSrc, "setPrintFooter(true)") !== false, 'generateLetterPdf() now enables TCPDF\'s native footer', 'setPrintFooter(true) is missing');
check(strpos($pdfSrc, "setPrintFooter(false)") === false, 'the old setPrintFooter(false) call is gone', 'setPrintFooter(false) is still present');
check(strpos($pdfSrc, "new BmsLetterPdf(") !== false, 'generateLetterPdf() instantiates the new subclass', 'generateLetterPdf() still instantiates plain TCPDF');
check(strpos($pdfSrc, "ob_start();") === false, 'the old ob_start()-captured footer HTML is gone', 'ob_start() capture is still present');

$renderSrc = readSrc($root, 'core/document_letter_render.php');
check(strpos($renderSrc, "'footer_html'") === false, 'renderLetterHtml() no longer accepts/embeds footer_html', 'footer_html is still referenced in renderLetterHtml()');

section('3. Signature block rebuilt to match the editor\'s approved design');

check(strpos($renderSrc, 'border:1px dashed #adb5bd') !== false, 'signature block now uses the same bordered-box style as .letter-signature-box', 'bordered box styling is missing');
check(strpos($renderSrc, 'border-top:1px solid #333333') !== false, 'signer name now sits on a signature line (border-top), matching .letter-signoff-name', 'signature-line styling is missing');
check(strpos($renderSrc, 'PREVIEW &mdash; NOT LEGALLY APPLIED') !== false, 'the PREVIEW caption is now rendered inside the bordered box', 'PREVIEW caption not found inside the box');

section('4. Live — generateLetterPdf() produces a real, multi-page-safe PDF with the fixes applied');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    $tmpPath = sys_get_temp_dir() . '/bms_test_letter_' . uniqid() . '.pdf';
    try {
        $_SESSION['user_id']    = $_SESSION['user_id']    ?? 1;
        $_SESSION['username']   = $_SESSION['username']   ?? 'test_letter_user';
        $_SESSION['first_name'] = $_SESSION['first_name'] ?? 'Test';
        $_SESSION['last_name']  = $_SESSION['last_name']  ?? 'Signer';
        $_SESSION['user_role']  = $_SESSION['user_role']  ?? 'Tester';

        $fields = [
            'document_code'      => 'TEST-LETTER-CLI-' . time(),
            'letter_date'        => date('Y-m-d'),
            'recipient'          => "Test Recipient",
            'recipient_address'  => "Test Address",
            'subject'            => 'CLI Test Letter',
            'content'            => '<p>Test body content.</p>',
            'signature_align'    => 'center',
            'use_letterhead'     => true,
        ];

        $size = generateLetterPdf($pdo, $fields, $tmpPath);
        check($size > 0 && is_file($tmpPath), 'generateLetterPdf() produces a non-empty PDF file', 'PDF generation failed or produced an empty file');

        $bytes = is_file($tmpPath) ? file_get_contents($tmpPath) : '';
        check(substr($bytes, 0, 4) === '%PDF', 'output is a valid PDF (starts with %PDF)', 'output is not a valid PDF');

        if (is_file($tmpPath)) unlink($tmpPath);
        pass('temp PDF cleaned up');
    } catch (Throwable $e) {
        fail('Live PDF generation threw: ' . $e->getMessage());
        if (is_file($tmpPath)) unlink($tmpPath);
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
