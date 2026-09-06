<?php
/**
 * Tender upgrade — Phase D (Form of Tender auto-draft) CLI test
 * ----------------------------------------------------------------
 *   php tests/test_tender_form_of_tender_cli.php
 *
 * Verifies:
 *   - core/tender_documents.php, api/tender_form_of_tender.php, the new page
 *     and migration are lint-clean
 *   - tenders.form_of_tender_html / form_of_tender_date / bid_validity_days
 *     columns exist
 *   - draftFormOfTenderBodyHtml() correctly substitutes tender_no,
 *     tender_description, the BOQ grand total (from Phase A data) and the
 *     validity period into the drafted paragraphs
 *   - the recipient/subject helpers pull the procuring entity and tender_no
 *   - generateLetterPdf() actually produces a real, non-trivial PDF file
 *     from the drafted body — a functional check of the whole pipeline, not
 *     just a string-substitution check, since this session cannot verify the
 *     rendered PDF visually in a browser
 *
 * Writes only inside a transaction that is always rolled back (DB rows); the
 * one file this test creates on disk is a temp PDF, always cleaned up in a
 * finally block. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tender_documents.php";
require_once "$root/core/document_letter_render.php";
require_once "$root/core/document_letter_pdf.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

$tmpPdfPath = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. New/changed files are lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    foreach ([
        'core/tender_documents.php',
        'api/tender_form_of_tender.php',
        'app/bms/tenders/tender_form_of_tender.php',
        'app/bms/tenders/_tender_nav.php',
        'migrations/2026_09_05_tender_form_of_tender.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }
    foreach (['draftFormOfTenderBodyHtml', 'tenderFormOfTenderRecipientHtml', 'tenderFormOfTenderSubject'] as $fn) {
        ok(function_exists($fn), "function $fn() is defined");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. Schema — columns exist');
    // ─────────────────────────────────────────────────────────────────────
    foreach (['form_of_tender_html', 'form_of_tender_date', 'bid_validity_days'] as $col) {
        $has = $pdo->query("SHOW COLUMNS FROM tenders LIKE '$col'")->fetch();
        ok((bool)$has, "tenders.$col column exists");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. Draft substitution — tender data + BOQ total flow into the letter');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("
        INSERT INTO tenders (tender_no, tender_description, procuring_entity_name, status, boq_grand_total, bid_validity_days)
        VALUES ('TEST-FOT-001', 'Supply of Treated Wooden Poles', 'TANESCO', 'PENDING', 25960.00, 120)
    ");
    $tenderId = (int)$pdo->lastInsertId();
    $tenderStmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
    $tenderStmt->execute([$tenderId]);
    $tender = $tenderStmt->fetch(PDO::FETCH_ASSOC);

    $body = draftFormOfTenderBodyHtml($tender);
    ok(str_contains($body, 'TEST-FOT-001'), 'drafted body contains the tender number');
    ok(str_contains($body, 'Supply of Treated Wooden Poles'), 'drafted body contains the tender title');
    ok(str_contains($body, '25,960.00'), 'drafted body contains the BOQ grand total, correctly formatted');
    ok(str_contains($body, '120 days'), 'drafted body uses the tender\'s own bid_validity_days (120), not a hard-coded 90');

    $recipient = tenderFormOfTenderRecipientHtml($tender);
    ok(str_contains($recipient, 'TANESCO'), 'recipient block contains the procuring entity name');

    $subject = tenderFormOfTenderSubject($tender);
    ok(str_contains($subject, 'TEST-FOT-001'), 'subject line contains the tender number');

    // Default (no BOQ priced yet) — must not claim a real figure that doesn't exist.
    $pdo->exec("UPDATE tenders SET boq_grand_total = 0 WHERE tender_id = $tenderId");
    $tenderStmt->execute([$tenderId]);
    $tenderZero = $tenderStmt->fetch(PDO::FETCH_ASSOC);
    $bodyZero = draftFormOfTenderBodyHtml($tenderZero);
    ok(str_contains($bodyZero, 'to be priced in the Bills of Quantities'), 'with no BOQ priced yet, the draft says so instead of claiming a fake TZS 0 figure');

    // ─────────────────────────────────────────────────────────────────────
    section('4. End-to-end PDF generation actually produces a real file');
    // ─────────────────────────────────────────────────────────────────────
    $tmpPdfPath = sys_get_temp_dir() . '/test_tender_fot_' . $tenderId . '.pdf';
    $size = generateLetterPdf($pdo, [
        'document_code'          => 'FOT-' . $tender['tender_no'],
        'letter_date'            => date('Y-m-d'),
        'use_letterhead'         => true,
        'recipient'              => $recipient,
        'subject'                => $subject,
        'content'                => $body,
        'signature_align'        => 'left',
        'suppress_signature_box' => false,
    ], $tmpPdfPath);

    ok(is_file($tmpPdfPath), 'generateLetterPdf() produced a real file on disk');
    ok($size > 1000, "generated PDF is a plausible size ($size bytes, not an empty/broken file)");
    $header = file_get_contents($tmpPdfPath, false, null, 0, 5);
    ok($header === '%PDF-', 'file starts with a valid PDF header');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
} finally {
    if ($tmpPdfPath && is_file($tmpPdfPath)) unlink($tmpPdfPath);
}

exit($fail === 0 ? 0 : 1);
