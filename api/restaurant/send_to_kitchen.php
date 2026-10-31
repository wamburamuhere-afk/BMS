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
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!canCreate('pos'))          { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$hold_id      = (int)($_POST['hold_id'] ?? 0);
$sale_id      = (int)($_POST['sale_id'] ?? 0);   // restaurant_plan.md Phase 3 — a paid take-away/delivery order
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
// order_label / sale_id columns (2026_10_31_restaurant_kitchen_ticket_label) — part of the menu schema check.
$labelCols    = restaurantMenuSchemaReady($pdo);

if (($hold_id <= 0 && $sale_id <= 0) || $warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}

if ($hold_id > 0) {
    $holdStmt = $pdo->prepare("SELECT h.hold_id, h.warehouse_id, h.table_id, h.items_data, h.hold_reference, rt.table_number
                                 FROM pos_held_sales h LEFT JOIN restaurant_tables rt ON rt.table_id = h.table_id
                                WHERE h.hold_id = ? AND h.status = 'held'");
    $holdStmt->execute([$hold_id]);
    $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
    if (!$hold || (int)$hold['warehouse_id'] !== $warehouse_id) {
        echo json_encode(['success' => false, 'message' => t('Held order not found for this table.')]);
        exit;
    }
    $items = json_decode($hold['items_data'] ?? '[]', true) ?: [];
    $orderLabel = $hold['table_number'] !== null ? t('Table') . ' ' . $hold['table_number'] : (string)$hold['hold_reference'];
} else {
    // restaurant_plan.md Phase 3 — a paid take-away / delivery sale goes to the kitchen too.
    if (!$labelCols) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
    $saleStmt = $pdo->prepare("SELECT sale_id, warehouse_id, receipt_number, sale_type FROM pos_sales WHERE sale_id = ? AND warehouse_id = ?");
    $saleStmt->execute([$sale_id, $warehouse_id]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { echo json_encode(['success' => false, 'message' => t('Sale not found.')]); exit; }
    $lineStmt = $pdo->prepare("SELECT sale_item_id, product_id, quantity, description FROM pos_sale_items WHERE sale_id = ?");
    $lineStmt->execute([$sale_id]);
    $modStmt = $pdo->prepare("SELECT option_name FROM pos_sale_item_modifiers WHERE sale_item_id = ?");
    $items = [];
    foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $ln) {
        $modStmt->execute([(int)$ln['sale_item_id']]);
        $items[] = [
            'product_id' => (int)$ln['product_id'],
            'quantity'   => (float)$ln['quantity'],
            'modifiers'  => array_map(fn($n) => ['option_name' => $n], $modStmt->fetchAll(PDO::FETCH_COLUMN)),
            'note'       => (string)($ln['description'] ?? ''),
        ];
    }
    $hold = ['table_id' => null];
    $typeLabels = ['take_away' => t('Take away'), 'delivery' => t('Delivery'), 'dine_in' => t('Dine in')];
    $orderLabel = ($typeLabels[(string)($sale['sale_type'] ?? '')] ?? t('Order')) . ' · ' . $sale['receipt_number'];
}
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
// restaurant_plan.md Phase 2 — is_menu_item marks a restaurant Menu dish (when the column exists).
$menuCol  = restaurantMenuSchemaReady($pdo) ? ', is_menu_item' : ', 0 AS is_menu_item';
$prodStmt = $pdo->prepare("SELECT product_id, product_name, kitchen_station_id, is_service$menuCol FROM products WHERE product_id IN ($placeholders)");
$prodStmt->execute($productIds);
$productsMap = [];
foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $p) { $productsMap[(int)$p['product_id']] = $p; }

// Group by station — a line with no kitchen_station_id (or a service line)
// never generates a kitchen ticket; it's a bar/retail item on the same bill.
$byStation = [];
foreach ($items as $item) {
    $pid = (int)($item['product_id'] ?? 0);
    $prod = $productsMap[$pid] ?? null;
    // A Menu dish is stored as a no-stock (service) item so it always sells —
    // it still goes to its kitchen area. Other service lines never do.
    if (!$prod || empty($prod['kitchen_station_id'])) continue;
    if (!empty($prod['is_service']) && empty($prod['is_menu_item'])) continue;
    $sid = (int)$prod['kitchen_station_id'];
    $parts = is_array($item['modifiers'] ?? null)
        ? array_filter(array_map(fn($m) => (string)($m['option_name'] ?? ''), $item['modifiers']))
        : [];
    $note = trim((string)($item['note'] ?? ''));          // "less salt" from the till (Phase 2)
    if ($note !== '') $parts[] = $note;
    $byStation[$sid][] = [
        'product_id' => $pid,
        'quantity'   => (float)($item['quantity'] ?? 1),
        'modifiers'  => $parts ? mb_substr(implode(', ', $parts), 0, 500) : null,
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
        // Same order re-sent → update its open ticket instead of adding a second
        // one. A table's order is one open ticket per area even though each save
        // creates a new held row (hold_sale.php keeps one open order per table).
        if ($hold_id > 0) {
            $existing = $pdo->prepare("
                SELECT ticket_id FROM kitchen_tickets
                WHERE station_id = ? AND status IN ('queued','preparing')
                  AND (hold_id = ? OR (? IS NOT NULL AND table_id = ?))
                ORDER BY ticket_id DESC LIMIT 1
            ");
            $existing->execute([$stationId, $hold_id, $hold['table_id'], $hold['table_id']]);
        } else {
            // A sale is sent once; a repeat call refreshes its ticket instead of doubling it.
            $existing = $pdo->prepare("SELECT ticket_id FROM kitchen_tickets WHERE station_id = ? AND sale_id = ? ORDER BY ticket_id DESC LIMIT 1");
            $existing->execute([$stationId, $sale_id]);
        }
        $ticketId = $existing->fetchColumn();

        if ($ticketId) {
            $pdo->prepare("DELETE FROM kitchen_ticket_items WHERE ticket_id = ?")->execute([$ticketId]);
            if ($hold_id > 0) {
                $pdo->prepare("UPDATE kitchen_tickets SET hold_id = ?, updated_at = NOW() WHERE ticket_id = ?")->execute([$hold_id, $ticketId]);
            } else {
                $pdo->prepare("UPDATE kitchen_tickets SET updated_at = NOW() WHERE ticket_id = ?")->execute([$ticketId]);
            }
            if ($labelCols) $pdo->prepare("UPDATE kitchen_tickets SET order_label = ? WHERE ticket_id = ?")->execute([mb_substr($orderLabel, 0, 80), $ticketId]);
        } else {
            if ($labelCols) {
                $pdo->prepare("
                    INSERT INTO kitchen_tickets (hold_id, sale_id, order_label, warehouse_id, station_id, table_id, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'queued', NOW(), NOW())
                ")->execute([$hold_id > 0 ? $hold_id : null, $sale_id > 0 ? $sale_id : null, mb_substr($orderLabel, 0, 80), $warehouse_id, $stationId, $hold['table_id']]);
            } else {
                $pdo->prepare("
                    INSERT INTO kitchen_tickets (hold_id, warehouse_id, station_id, table_id, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'queued', NOW(), NOW())
                ")->execute([$hold_id, $warehouse_id, $stationId, $hold['table_id']]);
            }
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
    logActivity($pdo, $_SESSION['user_id'], "Sent order ($orderLabel) to kitchen: " . count($ticketIds) . ' station(s)');
    echo json_encode(['success' => true, 'message' => t('Order sent to the kitchen.'), 'ticket_ids' => $ticketIds]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('send_to_kitchen: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
