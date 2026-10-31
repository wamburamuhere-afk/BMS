<?php
/**
 * API: move a table's open order to another free table (restaurant_plan.md Phase 2b).
 * POST: warehouse_id, from_table_id, to_table_id
 * Moves the open (held) order and its open kitchen tickets; the old table becomes free,
 * the new one occupied. Refuses a target that already has an open order (joining two
 * orders is Phase 4). Gate: Restaurant POS + Smart POS on, Restaurant/Hybrid shop, canCreate('pos').
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!canCreate('pos'))          { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$from = (int)($_POST['from_table_id'] ?? 0);
$to   = (int)($_POST['to_table_id'] ?? 0);

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}
if (!restaurantShopActive($pdo, $warehouse_id)) {
    echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);
    exit;
}
if ($from <= 0 || $to <= 0 || $from === $to) {
    echo json_encode(['success' => false, 'message' => t('Choose another table.')]);
    exit;
}

try {
    $tbl = $pdo->prepare("SELECT table_id, table_number FROM restaurant_tables WHERE table_id = ? AND warehouse_id = ?");
    $tbl->execute([$from, $warehouse_id]); $fromRow = $tbl->fetch(PDO::FETCH_ASSOC);
    $tbl->execute([$to, $warehouse_id]);   $toRow   = $tbl->fetch(PDO::FETCH_ASSOC);
    if (!$fromRow || !$toRow) throw new Exception(t('Table not found.'));

    $open = $pdo->prepare("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'");
    $open->execute([$from]);
    if ((int)$open->fetchColumn() === 0) throw new Exception(t('This table has no open order.'));
    $open->execute([$to]);
    if ((int)$open->fetchColumn() > 0) throw new Exception(t('That table already has an open order. Choose a free table.'));

    $pdo->beginTransaction();
    $ref = t('Table') . ' ' . $toRow['table_number'];
    $pdo->prepare("UPDATE pos_held_sales SET table_id = ?, hold_reference = ? WHERE table_id = ? AND status = 'held'")->execute([$to, $ref, $from]);
    $pdo->prepare("UPDATE kitchen_tickets SET table_id = ?, updated_at = NOW() WHERE table_id = ? AND status IN ('queued','preparing','ready')")->execute([$to, $from]);
    $pdo->prepare("UPDATE restaurant_tables SET status = 'available', updated_at = NOW() WHERE table_id = ?")->execute([$from]);
    $pdo->prepare("UPDATE restaurant_tables SET status = 'occupied', updated_at = NOW() WHERE table_id = ?")->execute([$to]);
    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Moved table order {$fromRow['table_number']} → {$toRow['table_number']}");
    echo json_encode(['success' => true, 'message' => sprintf(t('Order moved to table %s.'), $toRow['table_number'])]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('move_table_order: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('Database error.')]);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
