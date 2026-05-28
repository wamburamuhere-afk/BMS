<?php
// File: api/sales/review_return.php
// Workflow transition: pending -> reviewed. Stamps reviewed_by / reviewed_at.
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

if (!canReview('sales_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to review sales returns']);
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
    if ($row['status'] !== 'pending') {
        throw new Exception("Only a pending sales return can be reviewed (current status: " . ucfirst($row['status']) . ").");
    }

    $actor = workflowActorSnapshot();

    $pdo->prepare("
        UPDATE sales_returns
        SET status = 'reviewed', reviewed_by = ?, reviewed_at = NOW()
        WHERE sales_return_id = ?
    ")->execute([$_SESSION['user_id'], $id]);

    $sigResult = workflowCaptureSignature($pdo, 'sales_return', $id, 'reviewed',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    require_once __DIR__ . '/../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], 'Review Sales Return',
        "{$actor['name']} marked Sales Return #{$row['return_number']} as reviewed");

    $response = ['success' => true, 'message' => 'Sales Return marked as reviewed.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
