<?php
// scope-audit: skip — POS held sales helper; POS scope deferred to Phase G-2
/**
 * API: Get Held Sales for POS
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
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
            echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
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
        // Get held sales for current user
        $stmt = $pdo->prepare("
            SELECT
                h.*,
                c.customer_name
            FROM pos_held_sales h
            LEFT JOIN customers c ON h.customer_id = c.customer_id
            WHERE h.user_id = ?
            AND h.status = 'held'
            ORDER BY h.held_at DESC
            LIMIT 20
        ");

        $stmt->execute([$user_id]);
        $held_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
