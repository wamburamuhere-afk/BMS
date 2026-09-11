<?php
/**
 * API: Send a held table order to the kitchen
 *
 * POST: hold_id, warehouse_id
 *
 * Reads the held sale's current items snapshot (pos_held_sales.items_data —
 * the same JSON cart shape process_sale.php/hold_sale.php already use),
 * groups the non-service lines by their product's kitchen_station_id, and
 * writes/updates one kitchen_tickets row per station. Deliberately does NOT
 * touch pos_sales — the bill isn't finalized here, only the kitchen queue
 * and the table's occupied status (already set by hold_sale.php).
 *
 * Idempotent per (hold_id, station_id): calling this again after the order
 * changes (more items added) replaces that station's still-open ticket's
 * items with the fresh snapshot rather than creating a duplicate ticket —
 * a server pressing "Send to Kitchen" twice for the same table must not
 * double the kitchen's queue.
 *
 * Gate: restaurant_pos + canCreate('pos') (sending an order is a normal
 * part of taking a sale, same permission level as creating the sale itself).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!canCreate('pos'))          { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$hold_id      = (int)($_POST['hold_id'] ?? 0);
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);

if ($hold_id <= 0 || $warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

$holdStmt = $pdo->prepare("SELECT hold_id, warehouse_id, table_id, items_data FROM pos_held_sales WHERE hold_id = ? AND status = 'held'");
$holdStmt->execute([$hold_id]);
$hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
if (!$hold || (int)$hold['warehouse_id'] !== $warehouse_id) {
    echo json_encode(['success' => false, 'message' => t('Held order not found for this table.')]);
    exit;
}

$items = json_decode($hold['items_data'] ?? '[]', true) ?: [];
if (empty($items)) {
    echo json_encode(['success' => false, 'message' => t('There are no items to send to the kitchen.')]);
    exit;
}

$productIds = array_values(array_unique(array_filter(array_map(fn($i) => (int)($i['product_id'] ?? 0), $items))));
if (empty($productIds)) {
    echo json_encode(['success' => false, 'message' => t('There are no items to send to the kitchen.')]);
    exit;
}
$placeholders = str_repeat('?,', count($productIds) - 1) . '?';
$prodStmt = $pdo->prepare("SELECT product_id, product_name, kitchen_station_id, is_service FROM products WHERE product_id IN ($placeholders)");
$prodStmt->execute($productIds);
$productsMap = [];
foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $p) { $productsMap[(int)$p['product_id']] = $p; }

// Group by station — a line with no kitchen_station_id (or a service line)
// never generates a kitchen ticket; it's a bar/retail item on the same bill.
$byStation = [];
foreach ($items as $item) {
    $pid = (int)($item['product_id'] ?? 0);
    $prod = $productsMap[$pid] ?? null;
    if (!$prod || !empty($prod['is_service']) || empty($prod['kitchen_station_id'])) continue;
    $sid = (int)$prod['kitchen_station_id'];
    $byStation[$sid][] = [
        'product_id' => $pid,
        'quantity'   => (float)($item['quantity'] ?? 1),
        'modifiers'  => is_array($item['modifiers'] ?? null)
            ? implode(', ', array_map(fn($m) => (string)($m['option_name'] ?? ''), $item['modifiers']))
            : null,
    ];
}

if (empty($byStation)) {
    echo json_encode(['success' => false, 'message' => t('None of the items on this order route to a kitchen station.')]);
    exit;
}

$pdo->beginTransaction();
try {
    $ticketIds = [];
    foreach ($byStation as $stationId => $lines) {
        $existing = $pdo->prepare("
            SELECT ticket_id FROM kitchen_tickets
            WHERE hold_id = ? AND station_id = ? AND status IN ('queued','preparing')
            LIMIT 1
        ");
        $existing->execute([$hold_id, $stationId]);
        $ticketId = $existing->fetchColumn();

        if ($ticketId) {
            $pdo->prepare("DELETE FROM kitchen_ticket_items WHERE ticket_id = ?")->execute([$ticketId]);
            $pdo->prepare("UPDATE kitchen_tickets SET updated_at = NOW() WHERE ticket_id = ?")->execute([$ticketId]);
        } else {
            $pdo->prepare("
                INSERT INTO kitchen_tickets (hold_id, warehouse_id, station_id, table_id, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'queued', NOW(), NOW())
            ")->execute([$hold_id, $warehouse_id, $stationId, $hold['table_id']]);
            $ticketId = (int)$pdo->lastInsertId();
        }

        $insItem = $pdo->prepare("
            INSERT INTO kitchen_ticket_items (ticket_id, product_id, quantity, modifiers_summary, status, created_at)
            VALUES (?, ?, ?, ?, 'queued', NOW())
        ");
        foreach ($lines as $line) {
            $insItem->execute([$ticketId, $line['product_id'], $line['quantity'], $line['modifiers']]);
        }
        $ticketIds[] = (int)$ticketId;
    }

    $pdo->commit();
    logActivity($pdo, $_SESSION['user_id'], "Sent order (hold #$hold_id) to kitchen: " . count($ticketIds) . ' station(s)');
    echo json_encode(['success' => true, 'message' => t('Order sent to the kitchen.'), 'ticket_ids' => $ticketIds]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('send_to_kitchen: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
