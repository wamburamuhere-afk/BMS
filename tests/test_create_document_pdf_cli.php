<?php
/**
 * Create Document — server-rendered PDF regression test.
 *
 * Guards Phase B of the Create Document professional-output plan: the
 * saved letter PDF must be generated server-side (TCPDF, see
 * core/document_letter_pdf.php + core/document_letter_render.php) as real
 * vector text — not a client-side html2canvas/html2pdf raster screenshot.
 *
 * Run:  php tests/test_create_document_pdf_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root    = dirname(__DIR__);
$isLive  = is_file("$root/includes/config.php");

// Bootstrap BEFORE any output — session_start() (inside roots.php) warns
// "headers already sent" in CLI too if anything has already been echoed.
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

section('1. Static — html2pdf/html2canvas fully removed from the editor');

$editorSrc = file_get_contents("$root/app/constant/document/create_document.php");
check(
    strpos($editorSrc, 'html2pdf') === false && strpos($editorSrc, 'html2canvas') === false,
    'create_document.php no longer references html2pdf/html2canvas',
    'create_document.php still references html2pdf/html2canvas'
);
check(
    strpos($editorSrc, "fd.append('pdf_file'") === false,
    'client no longer uploads a pdf_file blob',
    'client still appends a pdf_file blob to the save request'
);

$apiSrc = file_get_contents("$root/api/document/save_created_document.php");
check(
    strpos($apiSrc, "_FILES['pdf_file']") === false,
    'save_created_document.php no longer reads $_FILES[\'pdf_file\']',
    'save_created_document.php still reads an uploaded pdf_file'
);
check(
    strpos($apiSrc, 'generateLetterPdf(') !== false,
    'save_created_document.php calls generateLetterPdf() to build the PDF itself',
    'save_created_document.php does not call generateLetterPdf()'
);

section('2. php -l on every touched/new file');

foreach ([
    'core/document_letter_render.php',
    'core/document_letter_pdf.php',
    'api/document/save_created_document.php',
    'app/constant/document/create_document.php',
] as $rel) {
    $out = [];
    $rc  = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

section('3. Live — a generated letter PDF is real vector text, not a raster image');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;

    // Minimal fake session — generateLetterPdf() only needs a user_id for
    // the signature-preview lookup and printed-by footer line; both already
    // handle a missing/empty session gracefully (?? fallbacks throughout).
    $_SESSION['user_id']    = 0;
    $_SESSION['first_name'] = 'CLI';
    $_SESSION['last_name']  = 'Test';
    $_SESSION['user_role']  = 'Tester';

    $marker     = 'REGTEST-' . bin2hex(random_bytes(4));
    $recipient  = 'The Manager, Regression Test Ltd';
    $bodyPhrase = "This paragraph exists only to prove real text made it into the PDF: $marker.";

    $tmpTarget = sys_get_temp_dir() . '/bms_letter_test_' . bin2hex(random_bytes(6)) . '.pdf';

    try {
        $size = generateLetterPdf($pdo, [
            'document_code'     => 'TEST-' . $marker,
            'letter_date'       => date('Y-m-d'),
            'use_letterhead'    => true,
            'recipient'         => $recipient,
            'recipient_address' => 'P.O. Box 1, Dar es Salaam',
            'subject'           => 'Regression Test Letter',
            'content'           => '<p>Dear Sir/Madam,</p><p>' . $bodyPhrase . '</p><p>Yours faithfully,</p>',
            'signature_align'   => 'left',
        ], $tmpTarget);

        check($size > 0 && is_file($tmpTarget), 'generateLetterPdf() wrote a non-empty file', 'no file / zero size written');

        $raw = file_get_contents($tmpTarget);
        check(substr($raw, 0, 5) === '%PDF-', 'output starts with the %PDF- magic header', 'missing %PDF- header — not a valid PDF');

        // Decompress every FlateDecode stream and search for our known text
        // fragments. A raster/image-based PDF (the old html2canvas path)
        // would NEVER contain the literal characters of the letter body —
        // they'd only exist as pixels inside a compressed JPEG/PNG blob.
        // Finding them as real decompressed text is direct proof this is a
        // genuine vector-text PDF.
        $foundMarker  = false;
        $foundSubject = false;
        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $m)) {
            foreach ($m[1] as $streamData) {
                $decoded = @gzuncompress($streamData);
                if ($decoded === false) { continue; }
                if (strpos($decoded, $marker) !== false) { $foundMarker = true; }
                if (strpos($decoded, 'Regression Test Letter') !== false) { $foundSubject = true; }
            }
        }
        check($foundMarker, "body text marker ($marker) found as real decompressed text in the PDF", 'body text marker NOT found in any decompressed stream — looks rasterized, not vector text');
        check($foundSubject, 'subject line found as real decompressed text in the PDF', 'subject line not found as real text');

        @unlink($tmpTarget);
    } catch (Throwable $e) {
        fail('generateLetterPdf() threw: ' . $e->getMessage());
        if (is_file($tmpTarget)) @unlink($tmpTarget);
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
