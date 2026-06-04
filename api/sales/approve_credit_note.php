<?php
// File: api/sales/approve_credit_note.php
// Workflow transition: reviewed -> approved. Stamps approved_by/_at + e-signature.
// No cash side-effect here — settlement happens via pay_credit_note.php (the
// "Record Payment" action) once approved.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
if (!canApprove('credit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot approve credit notes']); exit; }

try {
    global $pdo;
    $id = intval($_POST['credit_note_id'] ?? $_POST['id'] ?? 0);
    if (!$id) throw new Exception('Missing credit note ID');

    $stmt = $pdo->prepare("SELECT credit_note_number, status FROM credit_notes WHERE credit_note_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Credit note not found');
    if ($row['status'] !== 'reviewed') {
        throw new Exception('Only a reviewed credit note can be approved (current: ' . ucfirst($row['status']) . ').');
    }

    $actor = workflowActorSnapshot();
    $pdo->prepare("UPDATE credit_notes SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE credit_note_id = ?")
        ->execute([$_SESSION['user_id'], $id]);

    $sig = workflowCaptureSignature($pdo, 'credit_note', $id, 'approved',
        (int)$_SESSION['user_id'], $actor['name'], $actor['role']);

    require_once __DIR__ . '/../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], 'Approve Credit Note',
        "{$actor['name']} approved Credit Note #{$row['credit_note_number']}");

    $resp = ['success' => true, 'message' => 'Credit note approved. You can now record the refund payment.'];
    if (!$sig['has_signature']) $resp['sig_warning'] = 'Your e-signature was not captured (none on file). Set one up in E-Signatures.';
    echo json_encode($resp);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
