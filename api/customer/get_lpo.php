<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$lpo_id = intval($_GET['lpo_id'] ?? 0);
if (!$lpo_id) {
    echo json_encode(['success' => false, 'message' => 'LPO ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT l.*,
               CASE WHEN c.customer_type = 'business' AND c.company_name != '' AND c.company_name IS NOT NULL
                    THEN c.company_name ELSE c.customer_name END AS customer_display_name
        FROM customer_lpos l
        LEFT JOIN customers c ON l.customer_id = c.customer_id
        WHERE l.lpo_id = ? AND l.status != 'deleted'
    ");
    $stmt->execute([$lpo_id]);
    $lpo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lpo) {
        echo json_encode(['success' => false, 'message' => 'LPO not found']);
        exit;
    }

    $lpo['document_url'] = !empty($lpo['document_path']) ? buildUrl($lpo['document_path']) : null;

    echo json_encode(['success' => true, 'data' => $lpo]);
} catch (PDOException $e) {
    error_log("get_lpo error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
