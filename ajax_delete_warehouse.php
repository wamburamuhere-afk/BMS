<?php
require_once __DIR__ . '/roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isAdmin() && !canDelete('warehouses')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$warehouse_id = intval($_POST['warehouse_id'] ?? 0);
$csrf_token   = $_POST['csrf_token'] ?? '';
$confirmed    = !empty($_POST['confirmed']);

if ($csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

if ($warehouse_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Warehouse ID']);
    exit();
}

// Found during the 2026-07-17 warehouse-scope sweep: this endpoint had no
// scope check at all — any user with delete permission could delete any
// company warehouse. Gate on project scope (see the equivalent handler in
// app/bms/stock/warehouses.php for why userCan('warehouse', ...) is the
// wrong check for a management action like this).
require_once __DIR__ . '/core/project_scope.php';
$del_project_stmt = $pdo->prepare("SELECT project_id FROM warehouses WHERE warehouse_id = ?");
$del_project_stmt->execute([$warehouse_id]);
$del_current_project = $del_project_stmt->fetchColumn();
if ($del_current_project !== false && $del_current_project !== null
    && !userCan('project', (int)$del_current_project)) {
    echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse belongs to a project not in your scope.']);
    exit();
}

try {
    // Gather counts for the warning summary
    $stockRow = $pdo->prepare("SELECT COUNT(DISTINCT product_id) as product_count, COALESCE(SUM(stock_quantity),0) as total_qty FROM product_stocks WHERE warehouse_id = ?");
    $stockRow->execute([$warehouse_id]);
    $stockData = $stockRow->fetch(PDO::FETCH_ASSOC);

    $locRow = $pdo->prepare("SELECT COUNT(*) as location_count FROM locations WHERE warehouse_id = ?");
    $locRow->execute([$warehouse_id]);
    $locData = $locRow->fetch(PDO::FETCH_ASSOC);

    $productCount  = (int)$stockData['product_count'];
    $totalQty      = (float)$stockData['total_qty'];
    $locationCount = (int)$locData['location_count'];

    // First call (no confirmed flag) — return summary so JS can show warning
    if (!$confirmed) {
        echo json_encode([
            'success'        => true,
            'needs_confirm'  => true,
            'product_count'  => $productCount,
            'total_qty'      => $totalQty,
            'location_count' => $locationCount,
        ]);
        exit();
    }

    // Confirmed — cascade delete then soft-delete warehouse as ONE atomic unit:
    // a failure anywhere rolls everything back (no orphaned half-deleted state).
    // stock_movements are intentionally KEPT — they are the audit history of
    // stock in/out; only current-state rows (stocks, locations) are removed.
    $pdo->beginTransaction();
    try {
        // 1. Remove current stock records
        $pdo->prepare("DELETE FROM product_stocks WHERE warehouse_id = ?")->execute([$warehouse_id]);

        // 2. Remove locations
        $pdo->prepare("DELETE FROM locations WHERE warehouse_id = ?")->execute([$warehouse_id]);

        // 3. Soft-delete the warehouse
        $pdo->prepare("UPDATE warehouses SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE warehouse_id = ?")
            ->execute([$_SESSION['user_id'], $warehouse_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    logActivity($pdo, $_SESSION['user_id'], 'Deleted Warehouse', "Warehouse ID $warehouse_id deleted (cascade: $productCount products, $locationCount locations; movement history preserved).");

    echo json_encode(['success' => true, 'message' => 'Warehouse deleted successfully.']);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
