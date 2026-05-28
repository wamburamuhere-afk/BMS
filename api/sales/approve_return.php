<?php
// File: api/sales/approve_return.php
// Workflow transition: reviewed -> approved. Stamps approved_by / approved_at.
// No stock or accounting side-effect — sales returns historically only do a
// status update on approval. The user-facing "Mark Refunded" button (a
// post-approval transition) handles any meaningful follow-up.
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

if (!canApprove('sales_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve sales returns']);
    exit;
}

try {
    global $pdo;

    $id = intval($_POST['return_id'] ?? $_POST['id'] ?? 0);
    if (!$id) {
        throw new Exception("Missing sales return ID");
    }

    $stmt = $pdo->prepare("SELECT return_number, status FROM sales_returns WHERE sales_return_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("Sales return not found");
    }
    if ($row['status'] !== 'reviewed') {
        throw new Exception("Only a reviewed sales return can be approved (current status: " . ucfirst($row['status']) . ").");
    }

    $actor = workflowActorSnapshot();

    $pdo->prepare("
        UPDATE sales_returns
        SET status = 'approved', approved_by = ?, approved_at = NOW()
        WHERE sales_return_id = ?
    ")->execute([$_SESSION['user_id'], $id]);

    $sigResult = workflowCaptureSignature($pdo, 'sales_return', $id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    require_once __DIR__ . '/../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], 'Approve Sales Return',
        "{$actor['name']} approved Sales Return #{$row['return_number']}");

    $response = ['success' => true, 'message' => 'Sales Return approved.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
