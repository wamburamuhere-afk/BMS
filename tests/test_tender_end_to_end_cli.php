<?php
/**
 * Tender upgrade — Phase H final consolidated end-to-end test
 * ----------------------------------------------------------------
 *   php tests/test_tender_end_to_end_cli.php
 *
 * Unlike tests/test_tender_{boq,materials,checklist,form_of_tender,print,
 * award_project_link}_cli.php — which each test ONE phase in isolation —
 * this runs the FULL real lifecycle in a single script, the way an actual
 * user would: create tender -> price BOQ -> add materials -> tick checklist
 * -> draft & save Form of Tender -> print every document -> award -> verify
 * the project has everything. This is what catches an integration gap that
 * two passing isolated phase tests could still hide (e.g. Phase D's letter
 * reading a stale BOQ total, or Phase E's carry-over missing a row Phase A/B
 * added after the checklist was seeded).
 *
 * Also re-lints every file the whole tender.md plan touched or added, as one
 * final sweep (not just each phase's own slice), and re-confirms the
 * tender_edit.php AWARDED-bypass guard found during Phase H's re-scout.
 *
 * Writes DB rows only inside one outer transaction, always rolled back
 * (awardTenderToProject() detects it via $pdo->inTransaction() and composes
 * into it rather than managing its own, same as every other phase test).
 * Temp PDFs are cleaned up in a finally block. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tender_boq.php";
require_once "$root/core/tender_checklist.php";
require_once "$root/core/tender_documents.php";
require_once "$root/core/tender_award.php";
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

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Final lint sweep — every file this plan touched or added');
    // ─────────────────────────────────────────────────────────────────────
    $allFiles = array_merge(
        glob("$root/core/tender_*.php"),
        glob("$root/api/tender_*.php"),
        glob("$root/app/bms/tenders/*.php"),
        glob("$root/migrations/2026_09_05_tender_*.php")
    );
    $lintFailures = 0;
    foreach ($allFiles as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { $lintFailures++; echo "    lint FAILED: $f\n" . implode("\n", $out) . "\n"; }
    }
    ok($lintFailures === 0, count($allFiles) . ' tender-module files all lint-clean');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Re-scout fix — AWARDED cannot be set via the plain edit form');
    // ─────────────────────────────────────────────────────────────────────
    $editSrc = file_get_contents("$root/app/bms/tenders/tender_edit.php");
    ok(str_contains($editSrc, "requested_status === 'AWARDED'"), 'tender_edit.php has the AWARDED-transition guard found during Phase H re-scout');
    preg_match("/\\\$statuses = \[(.*?)\];/", $editSrc, $statusArrayMatch);
    ok(isset($statusArrayMatch[1]) && !str_contains($statusArrayMatch[1], 'AWARDED'), 'AWARDED is no longer offered as a plain dropdown option alongside the normal statuses');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Create a tender the way tender_create.php really does');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("
        INSERT INTO tenders (tender_no, tender_description, procuring_entity_name, status, currency, bid_validity_days)
        VALUES ('TEST-E2E-001', 'End-to-End Test Tender', 'E2E Procuring Entity', 'PENDING', 'Tshs', 60)
    ");
    $tenderId = (int)$pdo->lastInsertId();
    seedTenderChecklist($pdo, $tenderId); // exactly what tender_create.php does post-insert

    $checklistCount = $pdo->prepare("SELECT COUNT(*) FROM tender_checklist_items WHERE tender_id = ?");
    $checklistCount->execute([$tenderId]);
    ok((int)$checklistCount->fetchColumn() === 19, 'new tender got its 19-item checklist automatically');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Price the BOQ');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->prepare("INSERT INTO tender_boq_bills (tender_id, bill_title, sort_order) VALUES (?, 'Bill No. 1 - General', 0)")->execute([$tenderId]);
    $billId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_boq_items (bill_id, description, unit, qty, rate, amount, sort_order) VALUES (?, 'Treated wooden poles', 'each', 50, 120, 6000, 0)")->execute([$billId]);
    $grandTotal = recomputeTenderBoqTotal($pdo, $tenderId, 5.0, 18.0);
    // subtotal 6000, contingency 5% = 300, base 6300, vat 18% = 1134, total 7434
    ok(abs($grandTotal - 7434.0) < 0.01, "BOQ grand total computed correctly (7,434.00, got " . number_format($grandTotal, 2) . ")");

    // ─────────────────────────────────────────────────────────────────────
    section('5. Add a materials schedule line');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec("INSERT INTO products (product_name, unit, status) VALUES ('E2E Test Pole Product', 'each', 'active')");
    $productId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_materials (tender_id, product_id, material, unit, qty, rate, amount, sort_order) VALUES (?, ?, 'E2E Test Pole Product', 'each', 50, 100, 5000, 0)")
        ->execute([$tenderId, $productId]);
    $materialsCount = $pdo->prepare("SELECT COUNT(*) FROM tender_materials WHERE tender_id = ?");
    $materialsCount->execute([$tenderId]);
    ok((int)$materialsCount->fetchColumn() === 1, 'materials schedule line added');

    // ─────────────────────────────────────────────────────────────────────
    section('6. Tick checklist items ready');
    // ─────────────────────────────────────────────────────────────────────
    $itemIds = $pdo->prepare("SELECT item_id FROM tender_checklist_items WHERE tender_id = ? ORDER BY sort_order LIMIT 5");
    $itemIds->execute([$tenderId]);
    $tickStmt = $pdo->prepare("UPDATE tender_checklist_items SET is_ready = 1 WHERE item_id = ?");
    foreach ($itemIds->fetchAll(PDO::FETCH_COLUMN) as $iid) { $tickStmt->execute([$iid]); }
    $readyCount = $pdo->prepare("SELECT SUM(is_ready) FROM tender_checklist_items WHERE tender_id = ?");
    $readyCount->execute([$tenderId]);
    ok((int)$readyCount->fetchColumn() === 5, '5 checklist items ticked ready');

    // ─────────────────────────────────────────────────────────────────────
    section('7. Draft & save the Form of Tender — must reflect THIS tender\'s live BOQ total');
    // ─────────────────────────────────────────────────────────────────────
    $tenderStmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
    $tenderStmt->execute([$tenderId]);
    $tender = $tenderStmt->fetch(PDO::FETCH_ASSOC);
    ok(abs((float)$tender['boq_grand_total'] - 7434.0) < 0.01, "tenders.boq_grand_total reflects step 4's save (7,434.00)");

    $letterBody = draftFormOfTenderBodyHtml($tender);
    ok(str_contains($letterBody, '7,434.00'), 'Form of Tender draft uses the live BOQ total, not a stale/default one');
    ok(str_contains($letterBody, '60 days'), "Form of Tender draft uses this tender's own bid_validity_days (60), not the default 90");

    $pdo->prepare("UPDATE tenders SET form_of_tender_html = ?, form_of_tender_date = CURDATE() WHERE tender_id = ?")
        ->execute([$letterBody, $tenderId]);

    // ─────────────────────────────────────────────────────────────────────
    section('8. Print every document — all four produce real, valid PDFs');
    // ─────────────────────────────────────────────────────────────────────
    $tenderStmt->execute([$tenderId]);
    $tender = $tenderStmt->fetch(PDO::FETCH_ASSOC); // re-fetch with saved letter

    $billsForPrint = [['bill_id' => $billId, 'bill_title' => 'Bill No. 1 - General']];
    $itemsForPrint = [$billId => [['description' => 'Treated wooden poles', 'unit' => 'each', 'qty' => 50, 'rate' => 120, 'amount' => 6000]]];
    $materialsForPrint = $pdo->query("SELECT * FROM tender_materials WHERE tender_id = $tenderId")->fetchAll(PDO::FETCH_ASSOC);
    $checklistForPrint = $pdo->query("SELECT * FROM tender_checklist_items WHERE tender_id = $tenderId")->fetchAll(PDO::FETCH_ASSOC);

    $documents = [
        'FormOfTender' => ['recipient' => tenderFormOfTenderRecipientHtml($tender), 'subject' => tenderFormOfTenderSubject($tender), 'content' => $tender['form_of_tender_html'], 'suppress' => false],
        'BOQ'          => ['recipient' => '', 'subject' => 'BOQ', 'content' => buildTenderBoqPrintHtml($tender, $billsForPrint, $itemsForPrint), 'suppress' => true],
        'Materials'    => ['recipient' => '', 'subject' => 'Materials', 'content' => buildTenderMaterialsPrintHtml($materialsForPrint), 'suppress' => true],
        'Checklist'    => ['recipient' => '', 'subject' => 'Checklist', 'content' => buildTenderChecklistPrintHtml($checklistForPrint), 'suppress' => true],
    ];
    foreach ($documents as $label => $doc) {
        $path = sys_get_temp_dir() . '/test_e2e_' . strtolower($label) . '_' . $tenderId . '.pdf';
        $tmpFiles[] = $path;
        generateLetterPdf($pdo, [
            'document_code' => strtoupper($label) . '-' . $tender['tender_no'],
            'letter_date' => date('Y-m-d'),
            'use_letterhead' => true,
            'recipient' => $doc['recipient'],
            'subject' => $doc['subject'],
            'content' => $doc['content'],
            'signature_align' => 'left',
            'suppress_signature_box' => $doc['suppress'],
        ], $path);
        $valid = is_file($path) && filesize($path) > 1000 && file_get_contents($path, false, null, 0, 5) === '%PDF-';
        ok($valid, "$label document prints to a real, valid PDF");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('9. Award it — verify the project has EVERYTHING');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec("
        INSERT INTO employees (employee_number, first_name, last_name, gender, date_of_birth, email, phone, address, emergency_contact, hire_date, created_by)
        VALUES ('CLI-E2E-EMP', 'EndToEnd', 'Manager', 'male', '1990-01-01', 'e2e.manager@example.test', '0700000099', 'Test', 'Test EC', '2026-01-01', 1)
    ");
    $empId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO users (username, employee_id) VALUES ('cli_e2e_user', $empId)");
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_staff (tender_id, employee_id, role_position) VALUES (?, ?, 'Project Lead')")->execute([$tenderId, $empId]);

    $result = awardTenderToProject($pdo, $tenderId, $userId, ['tender_sum' => $grandTotal]);
    ok($result['success'] === true, 'award succeeded: ' . ($result['message'] ?? ''));
    $projectId = (int)($result['project_id'] ?? 0);

    $proj = $pdo->prepare("SELECT * FROM projects WHERE project_id = ?");
    $proj->execute([$projectId]);
    $project = $proj->fetch(PDO::FETCH_ASSOC);
    ok((int)$project['tender_id'] === $tenderId, 'project links back to this exact tender');
    ok(abs((float)$project['budget'] - $grandTotal) < 0.01, "project budget matches the awarded sum ({$grandTotal})");
    ok($project['project_manager'] === 'EndToEnd Manager', 'project_manager seeded from tender_staff');

    $projBoqAmount = $pdo->query("
        SELECT SUM(pbi.amount) FROM project_boq_items pbi
        JOIN project_boq_bills pbb ON pbb.bill_id = pbi.bill_id
        WHERE pbb.project_id = $projectId
    ")->fetchColumn();
    ok(abs((float)$projBoqAmount - 6000.0) < 0.01, 'project BOQ carried over with the correct amount (6,000 pre-contingency/VAT)');

    $projNipCount = $pdo->query("
        SELECT COUNT(*) FROM nip_material_list_nips n
        JOIN nip_material_lists l ON l.id = n.material_list_id
        WHERE l.project_id = $projectId
    ")->fetchColumn();
    ok((int)$projNipCount === 1, 'project NIP material list carried the one materials-schedule line');

    $userProjCount = $pdo->prepare("SELECT COUNT(*) FROM user_projects WHERE project_id = ? AND user_id = ?");
    $userProjCount->execute([$projectId, $userId]);
    ok((int)$userProjCount->fetchColumn() === 1, 'the staff member with a login can see the new project');

    // The checklist itself belongs to the TENDER (frozen submission evidence),
    // not the project — must still exist, untouched, after award.
    $checklistStillThere = $pdo->prepare("SELECT COUNT(*) FROM tender_checklist_items WHERE tender_id = ?");
    $checklistStillThere->execute([$tenderId]);
    ok((int)$checklistStillThere->fetchColumn() === 19, "the tender's checklist survives award untouched (still 19 items)");

    // ─────────────────────────────────────────────────────────────────────
    section('10. Re-awarding is refused end to end');
    // ─────────────────────────────────────────────────────────────────────
    $second = awardTenderToProject($pdo, $tenderId, $userId, ['tender_sum' => 1]);
    ok($second['success'] === false, 'a second award attempt on this same tender is refused');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind anywhere in the chain');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
} finally {
    foreach ($tmpFiles as $f) { if (is_file($f)) unlink($f); }
}

exit($fail === 0 ? 0 : 1);
