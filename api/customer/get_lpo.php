<?php
// scope-audit: skip — LPO lookup helper for customer forms; no project scope needed
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('lpo')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$lpo_id = intval($_GET['lpo_id'] ?? 0);
if (!$lpo_id) {
    echo json_encode(['success' => false, 'message' => 'LPO ID is required']);
    exit;
}

require_once __DIR__ . '/../../core/project_scope.php';
assertScopeForRecord('customer_lpos', 'lpo_id', $lpo_id);

try {
    $stmt = $pdo->prepare("
        SELECT l.*,
               CASE WHEN c.customer_type = 'business' AND c.company_name != '' AND c.company_name IS NOT NULL
                    THEN c.company_name ELSE c.customer_name END AS customer_display_name,
               c.email AS customer_email, c.phone AS customer_phone, c.address AS customer_address,
               p.project_name,
               u.username AS created_by_name
        FROM customer_lpos l
        LEFT JOIN customers c ON l.customer_id = c.customer_id
        LEFT JOIN projects p ON l.project_id = p.project_id
        LEFT JOIN users u ON l.created_by = u.user_id
        WHERE l.lpo_id = ? AND l.status != 'deleted'
    ");
    $stmt->execute([$lpo_id]);
    $lpo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lpo) {
        echo json_encode(['success' => false, 'message' => 'LPO not found']);
        exit;
    }

    $lpo['document_url'] = !empty($lpo['document_path']) ? buildUrl($lpo['document_path']) : null;

    // Fetch line items
    try {
        $iStmt = $pdo->prepare("SELECT item_id, sort_order, product_name, quantity, unit_price, tax_rate, total FROM customer_lpo_items WHERE lpo_id = ? ORDER BY sort_order, item_id");
        $iStmt->execute([$lpo_id]);
        $lpo['items'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $lpo['items'] = []; }

    // Fetch attachments
    try {
        $aStmt = $pdo->prepare("SELECT attachment_id, file_path, original_name, file_size FROM customer_lpo_attachments WHERE lpo_id = ? ORDER BY attachment_id");
        $aStmt->execute([$lpo_id]);
        $attachments = $aStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attachments as &$att) {
            $att['download_url'] = buildUrl($att['file_path']);
        }
        $lpo['attachments'] = $attachments;
    } catch (PDOException $e) { $lpo['attachments'] = []; }

    echo json_encode(['success' => true, 'data' => $lpo]);
} catch (PDOException $e) {
    error_log("get_lpo error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
