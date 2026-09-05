<?php
// scope-audit: skip — tender print views; tenders reference customers (no direct project_id); deferred to Phase G-2
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/tender_documents.php';
require_once __DIR__ . '/../core/document_letter_render.php';
require_once __DIR__ . '/../core/document_letter_pdf.php';

if (!isAuthenticated()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

if (!canView('tenders')) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

global $pdo;
$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$tender_id = (int)($_GET['tender_id'] ?? 0);

if (!$tender_id) {
    http_response_code(400);
    echo 'Invalid Tender ID';
    exit;
}

$tenderStmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
$tenderStmt->execute([$tender_id]);
$tender = $tenderStmt->fetch(PDO::FETCH_ASSOC);
if (!$tender) {
    http_response_code(404);
    echo 'Tender not found';
    exit;
}

$printable = ['PRINT_BOQ', 'PRINT_MATERIALS', 'PRINT_CHECKLIST'];
if (!in_array($action, $printable, true)) {
    http_response_code(400);
    echo 'Unknown action';
    exit;
}

switch ($action) {
    case 'PRINT_BOQ':
        $billsStmt = $pdo->prepare("SELECT * FROM tender_boq_bills WHERE tender_id = ? ORDER BY sort_order");
        $billsStmt->execute([$tender_id]);
        $bills = $billsStmt->fetchAll(PDO::FETCH_ASSOC);
        $itemsByBill = [];
        if ($bills) {
            $billIds = array_column($bills, 'bill_id');
            $ph = implode(',', array_fill(0, count($billIds), '?'));
            $itemsStmt = $pdo->prepare("SELECT * FROM tender_boq_items WHERE bill_id IN ($ph) ORDER BY sort_order");
            $itemsStmt->execute($billIds);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $itemsByBill[$item['bill_id']][] = $item;
            }
        }
        $content = buildTenderBoqPrintHtml($tender, $bills, $itemsByBill);
        $subject = 'Bills of Quantities — ' . $tender['tender_no'];
        $filenameBase = 'BOQ';
        break;

    case 'PRINT_MATERIALS':
        $matStmt = $pdo->prepare("SELECT * FROM tender_materials WHERE tender_id = ? ORDER BY sort_order");
        $matStmt->execute([$tender_id]);
        $content = buildTenderMaterialsPrintHtml($matStmt->fetchAll(PDO::FETCH_ASSOC));
        $subject = 'Materials Schedule — ' . $tender['tender_no'];
        $filenameBase = 'Materials-Schedule';
        break;

    case 'PRINT_CHECKLIST':
        $chkStmt = $pdo->prepare("SELECT * FROM tender_checklist_items WHERE tender_id = ? ORDER BY sort_order");
        $chkStmt->execute([$tender_id]);
        $content = buildTenderChecklistPrintHtml($chkStmt->fetchAll(PDO::FETCH_ASSOC));
        $subject = 'Compliance Checklist — ' . $tender['tender_no'];
        $filenameBase = 'Checklist';
        break;
}

$tmpPath = sys_get_temp_dir() . '/tender_print_' . $tender_id . '_' . bin2hex(random_bytes(6)) . '.pdf';
try {
    generateLetterPdf($pdo, [
        'document_code'          => strtoupper(str_replace('PRINT_', '', $action)) . '-' . $tender['tender_no'],
        'letter_date'            => date('Y-m-d'),
        'use_letterhead'         => true,
        'recipient'              => '',
        'subject'                => $subject,
        'content'                => $content,
        'signature_align'        => 'left',
        'suppress_signature_box' => true,
    ], $tmpPath);

    logActivity($pdo, $user_id, 'VIEW', "[Tender Print] $action for tender #$tender_id");

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filenameBase . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tender['tender_no']) . '.pdf"');
    header('Content-Length: ' . filesize($tmpPath));
    readfile($tmpPath);
} finally {
    if (is_file($tmpPath)) unlink($tmpPath);
}
