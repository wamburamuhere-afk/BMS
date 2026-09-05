<?php
// scope-audit: skip — tender Form of Tender sub-data; same entity/scope as tenders (customers, not project-scoped); deferred to Phase G-2
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/tender_documents.php';

if (!isAuthenticated()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

global $pdo;
$user_id = (int)$_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';
$tender_id = (int)($_REQUEST['tender_id'] ?? 0);

if (!$tender_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid Tender ID']);
    exit;
}

$tenderStmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
$tenderStmt->execute([$tender_id]);
$tender = $tenderStmt->fetch(PDO::FETCH_ASSOC);
if (!$tender) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Tender not found']);
    exit;
}

// PRINT is a read-only GET (view/download) — everything else changes state
// and requires edit permission + CSRF, same as every other tender sub-API.
if ($action === 'PRINT') {
    if (!canView('tenders')) {
        http_response_code(403);
        echo 'Access Denied';
        exit;
    }
    require_once __DIR__ . '/../core/document_letter_render.php';
    require_once __DIR__ . '/../core/document_letter_pdf.php';

    $bodyHtml = $tender['form_of_tender_html'] ?: draftFormOfTenderBodyHtml($tender);
    $letterDate = $tender['form_of_tender_date'] ?: date('Y-m-d');

    $tmpPath = sys_get_temp_dir() . '/tender_fot_' . $tender_id . '_' . bin2hex(random_bytes(6)) . '.pdf';
    try {
        generateLetterPdf($pdo, [
            'document_code'          => 'FOT-' . $tender['tender_no'],
            'letter_date'            => $letterDate,
            'use_letterhead'         => true,
            'recipient'              => tenderFormOfTenderRecipientHtml($tender),
            'subject'                => tenderFormOfTenderSubject($tender),
            'content'                => $bodyHtml,
            'signature_align'        => 'left',
            'suppress_signature_box' => false,
        ], $tmpPath);

        logActivity($pdo, $user_id, 'VIEW', "[Tender Form of Tender] Printed letter for tender #$tender_id");

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Form-of-Tender-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tender['tender_no']) . '.pdf"');
        header('Content-Length: ' . filesize($tmpPath));
        readfile($tmpPath);
    } finally {
        if (is_file($tmpPath)) unlink($tmpPath);
    }
    exit;
}

header('Content-Type: application/json');

if (!canEdit('tenders') && !canCreate('tenders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit the Form of Tender']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

try {
    switch ($action) {

        case 'SAVE_LETTER':
            $bodyHtml = $_POST['body_html'] ?? '';
            $letterDate = !empty($_POST['letter_date']) ? $_POST['letter_date'] : date('Y-m-d');

            $pdo->prepare("UPDATE tenders SET form_of_tender_html = ?, form_of_tender_date = ?, updated_at = NOW() WHERE tender_id = ?")
                ->execute([$bodyHtml, $letterDate, $tender_id]);

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Form of Tender] Saved letter for tender #$tender_id");
            echo json_encode(['success' => true, 'message' => 'Letter saved.']);
            break;

        case 'REDRAFT':
            $freshBody = draftFormOfTenderBodyHtml($tender);
            $pdo->prepare("UPDATE tenders SET form_of_tender_html = ?, updated_at = NOW() WHERE tender_id = ?")
                ->execute([$freshBody, $tender_id]);

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Form of Tender] Re-drafted letter from details for tender #$tender_id");
            echo json_encode(['success' => true, 'message' => 'Letter re-drafted from tender details.', 'body_html' => $freshBody]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (PDOException $e) {
    error_log("api/tender_form_of_tender.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
