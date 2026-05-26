<?php
// File: api/account/approve_quotation.php
// Workflow transition: reviewed -> approved. Stamps approved_by / approved_at.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!canApprove('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve quotations']);
    exit;
}

try {
    global $pdo;

    $id = intval($_POST['quotation_id'] ?? $_POST['id'] ?? 0);
    if (!$id) {
        throw new Exception("Missing quotation ID");
    }

    // Phase C — block approvals against quotations on projects not in user scope
    assertScopeForRecord('quotations', 'sales_order_id', $id);

    $stmt = $pdo->prepare("SELECT order_number, status FROM quotations WHERE sales_order_id = ?");
    $stmt->execute([$id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        throw new Exception("Quotation not found");
    }
    if ($quote['status'] !== 'reviewed') {
        throw new Exception("Only a reviewed quotation can be approved (current status: " . ucfirst($quote['status']) . ").");
    }

    $actor = workflowActorSnapshot();

    $pdo->prepare("
        UPDATE quotations
        SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_by = ?, updated_at = NOW()
        WHERE sales_order_id = ?
    ")->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);

    $sigResult = workflowCaptureSignature($pdo, 'quotation', $id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity($pdo, $_SESSION['user_id'], 'Approve Quotation',
        "{$actor['name']} approved Quotation #{$quote['order_number']}");

    $response = ['success' => true, 'message' => 'Quotation approved.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
