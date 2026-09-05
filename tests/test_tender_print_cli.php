<?php
/**
 * Tender upgrade — Phase F (Print + NeST shortcut) CLI test
 * ----------------------------------------------------------------
 *   php tests/test_tender_print_cli.php
 *
 * Verifies:
 *   - core/tender_documents.php's new print-table builders, api/tender_print.php,
 *     the new page, and _tender_nav.php are lint-clean
 *   - buildTenderBoqPrintHtml() / buildTenderMaterialsPrintHtml() /
 *     buildTenderChecklistPrintHtml() produce HTML containing the real data
 *     passed in (amounts, material names, checklist ready-count)
 *   - each of the three actually produces a real, valid PDF end-to-end via
 *     the same generateLetterPdf() engine Phase D's Form of Tender uses (no
 *     second PDF pipeline) — proven the same way Phase D's test proved its
 *     pipeline, since this session cannot open a browser to look at it
 *
 * Writes only inside a transaction that is always rolled back (DB rows); the
 * temp PDFs this test creates on disk are always cleaned up in a finally
 * block. Exit 0 = pass.
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

$tmpFiles = [];

function assertRealPdf(PDO $pdo, array $fields, string $path): array
{
    generateLetterPdf($pdo, $fields, $path);
    $isFile = is_file($path);
    $size = $isFile ? filesize($path) : 0;
    $header = $isFile ? file_get_contents($path, false, null, 0, 5) : '';
    return [$isFile, $size, $header];
}

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. New/changed files are lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    foreach ([
        'core/tender_documents.php',
        'api/tender_print.php',
        'app/bms/tenders/tender_print.php',
        'app/bms/tenders/_tender_nav.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }
    foreach (['buildTenderBoqPrintHtml', 'buildTenderMaterialsPrintHtml', 'buildTenderChecklistPrintHtml'] as $fn) {
        ok(function_exists($fn), "function $fn() is defined");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. Build a tender with BOQ, Materials, Checklist to print');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("
        INSERT INTO tenders (tender_no, tender_description, status, boq_grand_total, boq_contingency_percent, boq_vat_percent)
        VALUES ('TEST-PRINT-001', 'CLI Print Test Tender', 'PENDING', 12980.00, 10.00, 18.00)
    ");
    $tenderId = (int)$pdo->lastInsertId();
    $tenderStmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
    $tenderStmt->execute([$tenderId]);
    $tender = $tenderStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare("INSERT INTO tender_boq_bills (tender_id, bill_title, sort_order) VALUES (?, 'Bill No. 1', 0)")->execute([$tenderId]);
    $billId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_boq_items (bill_id, description, unit, qty, rate, amount, sort_order) VALUES (?, 'Test Item', 'pcs', 4, 2500, 10000, 0)")->execute([$billId]);
    $bills = [['bill_id' => $billId, 'bill_title' => 'Bill No. 1']];
    $itemsByBill = [$billId => [['description' => 'Test Item', 'unit' => 'pcs', 'qty' => 4, 'rate' => 2500, 'amount' => 10000]]];

    $pdo->prepare("INSERT INTO tender_materials (tender_id, material, unit, qty, rate, amount, sort_order) VALUES (?, 'Test Material', 'bags', 3, 1500, 4500, 0)")->execute([$tenderId]);
    $materials = [['material' => 'Test Material', 'specification' => null, 'unit' => 'bags', 'qty' => 3, 'rate' => 1500, 'amount' => 4500]];

    $checklistItems = [
        ['item_text' => 'Form of Tender — completed, signed & stamped', 'is_ready' => 1],
        ['item_text' => 'TIN Certificate', 'is_ready' => 0],
    ];

    // ─────────────────────────────────────────────────────────────────────
    section('3. HTML builders contain the real data');
    // ─────────────────────────────────────────────────────────────────────
    $boqHtml = buildTenderBoqPrintHtml($tender, $bills, $itemsByBill);
    ok(str_contains($boqHtml, 'Test Item'), 'BOQ print HTML contains the item description');
    ok(str_contains($boqHtml, '10,000.00'), 'BOQ print HTML contains the bill total');
    ok(str_contains($boqHtml, '12,980.00'), 'BOQ print HTML contains the grand total');

    $matHtml = buildTenderMaterialsPrintHtml($materials);
    ok(str_contains($matHtml, 'Test Material'), 'Materials print HTML contains the material name');
    ok(str_contains($matHtml, '4,500.00'), 'Materials print HTML contains the correct total');

    $chkHtml = buildTenderChecklistPrintHtml($checklistItems);
    ok(str_contains($chkHtml, '1 / 2 ready'), 'Checklist print HTML shows the correct ready count (1 / 2)');
    ok(str_contains($chkHtml, 'TIN Certificate'), 'Checklist print HTML lists the unticked item');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Each document produces a real, valid PDF end-to-end');
    // ─────────────────────────────────────────────────────────────────────
    $cases = [
        'BOQ'       => $boqHtml,
        'Materials' => $matHtml,
        'Checklist' => $chkHtml,
    ];
    foreach ($cases as $label => $content) {
        $path = sys_get_temp_dir() . '/test_tender_print_' . strtolower($label) . '_' . $tenderId . '.pdf';
        $tmpFiles[] = $path;
        [$isFile, $size, $header] = assertRealPdf($pdo, [
            'document_code'          => strtoupper($label) . '-' . $tender['tender_no'],
            'letter_date'            => date('Y-m-d'),
            'use_letterhead'         => true,
            'recipient'              => '',
            'subject'                => "$label — {$tender['tender_no']}",
            'content'                => $content,
            'signature_align'        => 'left',
            'suppress_signature_box' => true,
        ], $path);
        ok($isFile, "$label: generateLetterPdf() produced a real file");
        ok($size > 1000, "$label: plausible file size ($size bytes)");
        ok($header === '%PDF-', "$label: valid PDF header");
    }

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
} finally {
    foreach ($tmpFiles as $f) { if (is_file($f)) unlink($f); }
}

exit($fail === 0 ? 0 : 1);
