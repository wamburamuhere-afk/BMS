<?php
// scope-audit: skip — POS held sales helper; POS scope deferred to Phase G-2
/**
 * API: Get Held Sales for POS
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

try {
    global $pdo;
    
    $user_id = $_SESSION['user_id'];

    // Phase 30 (pos_upgrade_plan.md §9) — a table's open order must be
    // discoverable by whichever staff member selects that table next, not
    // only by whoever originally held it, so this branch drops the
    // user_id ownership filter in favour of a warehouse+table lookup. Still
    // warehouse-scope checked, same as every other warehouse-addressed
    // endpoint in this plan.
    $table_id = !empty($_GET['table_id']) ? (int)$_GET['table_id'] : null;
    if ($table_id) {
        $warehouse_id = !empty($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
        require_once __DIR__ . '/../../core/project_scope.php';
        if (!$warehouse_id || !userCan('warehouse', $warehouse_id)) {
            echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.', true)]);
            exit();
        }
        $stmt = $pdo->prepare("
            SELECT h.*, c.customer_name
            FROM pos_held_sales h
            LEFT JOIN customers c ON h.customer_id = c.customer_id
            WHERE h.table_id = ? AND h.warehouse_id = ? AND h.status = 'held'
            ORDER BY h.held_at DESC
            LIMIT 1
        ");
        $stmt->execute([$table_id, $warehouse_id]);
        $held_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // The user's own held sales, plus accepted ONLINE orders of any shop in their scope —
        // whoever is at the till completes an online order, not only the person who accepted it.
        $hasOnline = (bool)$pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'online_order_id'")->fetch();
        $stmt = $pdo->prepare("
            SELECT
                h.*,
                c.customer_name,
                w.warehouse_name
            FROM pos_held_sales h
            LEFT JOIN customers c ON h.customer_id = c.customer_id
            LEFT JOIN warehouses w ON w.warehouse_id = h.warehouse_id
            WHERE h.status = 'held'
              AND (h.user_id = ?" . ($hasOnline ? " OR h.online_order_id IS NOT NULL" : "") . ")
            ORDER BY h.held_at DESC
            LIMIT 60
        ");

        $stmt->execute([$user_id]);
        require_once __DIR__ . '/../../core/project_scope.php';
        $held_sales = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function ($h) use ($user_id) {
            if ((int)$h['user_id'] === (int)$user_id) return true;
            return !empty($h['online_order_id']) && $h['warehouse_id'] !== null && userCan('warehouse', (int)$h['warehouse_id']);
        }));
    }
    
    echo json_encode([
        'success' => true,
        'data' => $held_sales
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
