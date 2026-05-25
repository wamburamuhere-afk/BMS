<?php
// File: api/account/delete_quotation.php
// Deletes a quotation (and its items) from the dedicated `quotations` table.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

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

if (!canDelete('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete quotations']);
    exit;
}

try {
    global $pdo;

    $quotation_id = intval($_POST['quotation_id'] ?? $_POST['order_id'] ?? 0);
    if (!$quotation_id) {
        throw new Exception("Missing quotation ID");
    }

    // Phase C — block deletes against quotations on projects not in user scope
    assertScopeForRecord('quotations', 'sales_order_id', $quotation_id);

    $stmt = $pdo->prepare("SELECT order_number FROM quotations WHERE sales_order_id = ?");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        throw new Exception("Quotation not found");
    }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM quotation_items WHERE order_id = ?")->execute([$quotation_id]);
    $pdo->prepare("DELETE FROM quotations WHERE sales_order_id = ?")->execute([$quotation_id]);
    $pdo->commit();

    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], "Delete Quotation",
        "$user_name deleted Quotation #" . ($quotation['order_number'] ?? 'Unknown'));

    echo json_encode(['success' => true, 'message' => 'Quotation deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
