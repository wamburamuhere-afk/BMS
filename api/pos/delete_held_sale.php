<?php
/**
 * API: Delete Held Sale
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}


if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit();
}

if (!canDelete('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access Denied: you do not have permission to delete held POS sales', 'Access Denied: you do not have permission to delete held sales')]);
    exit();
}

try {
    global $pdo;

    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $hold_id = isset($data['hold_id']) ? intval($data['hold_id']) : 0;
    $user_id = $_SESSION['user_id'];
    
    if ($hold_id <= 0) {
        echo json_encode(['success' => false, 'message' => t('Invalid hold ID')]);
        exit();
    }
    
    // Verify ownership and status. An accepted ONLINE order may be loaded by any staff of its shop.
    $hasOnline = (bool)$pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'online_order_id'")->fetch();
    $stmt = $pdo->prepare("SELECT hold_id, user_id, warehouse_id, status" . ($hasOnline ? ", online_order_id" : ", NULL AS online_order_id") . "
                             FROM pos_held_sales WHERE hold_id = ?");
    $stmt->execute([$hold_id]);
    $hold = $stmt->fetch(PDO::FETCH_ASSOC);
    $isOnline = $hold && !empty($hold['online_order_id']);
    if ($isOnline && $hold['status'] !== 'held') {
        echo json_encode(['success' => false, 'message' => $hold['status'] === 'loaded'
            ? t('Another till has already opened this online order.')
            : t('This online order was cancelled. Check Online orders.')]);
        exit();
    }
    if ($hold && !$isOnline && $hold['status'] !== 'held') $hold = false;
    if ($isOnline) {
        require_once __DIR__ . '/../../core/project_scope.php';
        $allowed = (int)$hold['user_id'] === (int)$user_id || ($hold['warehouse_id'] !== null && userCan('warehouse', (int)$hold['warehouse_id']));
    } else {
        $allowed = $hold && (int)$hold['user_id'] === (int)$user_id;
    }
    if (!$allowed) {
        echo json_encode(['success' => false, 'message' => t('Held sale not found or access denied')]);
        exit();
    }

    // An online order is never thrown away from the till: loading it onto the till keeps the
    // record (status 'loaded'); removing it must go through Online orders, which tells the customer.
    if ($isOnline) {
        if (empty($data['loaded'])) {
            echo json_encode(['success' => false, 'message' => t('This is an online order. To remove it, cancel it on Online orders so the customer is told.')]);
            exit();
        }
        $claim = $pdo->prepare("UPDATE pos_held_sales SET status = 'loaded', loaded_at = NOW() WHERE hold_id = ? AND status = 'held'");
        $claim->execute([$hold_id]);
        if ($claim->rowCount() !== 1) {
            echo json_encode(['success' => false, 'message' => t('Another till has already opened this online order.')]);
            exit();
        }
        logActivity($pdo, $user_id, "Load held sale", "loaded online order held sale id $hold_id onto the till");
        echo json_encode(['success' => true, 'message' => t('Held sale loaded')]);
        exit();
    }

    // Delete (or soft delete)
    // For now we'll update status to 'cancelled' so we keep history, or delete physically.
    // Let's delete strictly to keep it clean as requested.
    $stmt = $pdo->prepare("DELETE FROM pos_held_sales WHERE hold_id = ?");
    $stmt->execute([$hold_id]);

    logActivity($pdo, $user_id, "Delete held sale", "deleted held POS sale with id $hold_id");

    echo json_encode([
        'success' => true,
        'message' => t('Held sale deleted successfully')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
