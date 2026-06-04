<?php
// File: api/purchase/get_debit_note_source.php
// scope-audit: skip — purchase return scope verified below via the linked purchase
// order's project_id + userCan(), same guard as the purchase return print page.
// Returns an approved purchase return's supplier + line items so the Debit Note
// create form can auto-fill from it.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('debit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

global $pdo;
$return_id = intval($_GET['purchase_return_id'] ?? 0);
if ($return_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid return ID']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT pr.purchase_return_id, pr.return_number, pr.supplier_id, pr.reason, pr.status,
               po.project_id, s.supplier_name, s.company_name
          FROM purchase_returns pr
          LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
          LEFT JOIN suppliers s        ON pr.supplier_id       = s.supplier_id
         WHERE pr.purchase_return_id = ?
    ");
    $stmt->execute([$return_id]);
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) { echo json_encode(['success' => false, 'message' => 'Purchase return not found']); exit; }
    if ($pr['status'] !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'Only an approved purchase return can raise a debit note.']); exit;
    }
    if (!empty($pr['project_id']) && !userCan('project', (int)$pr['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This return belongs to a project not in your scope.']); exit;
    }
    $dup = $pdo->prepare("SELECT debit_note_number FROM debit_notes
                           WHERE purchase_return_id = ? AND status NOT IN ('deleted','rejected','cancelled') LIMIT 1");
    $dup->execute([$return_id]);
    if ($existing = $dup->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => "This return already has debit note {$existing}."]); exit;
    }

    $stmtI = $pdo->prepare("SELECT * FROM purchase_return_items WHERE purchase_return_id = ?");
    $stmtI->execute([$return_id]);

    $items = [];
    foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $rate = (float)($it['tax_rate'] ?? 0);
        $items[] = [
            'product_id'  => isset($it['product_id']) && $it['product_id'] !== null ? (int)$it['product_id'] : null,
            'description' => $it['item_name'] ?? ($it['description'] ?? 'Item'),
            'quantity'    => (float)($it['quantity'] ?? 0),
            'unit_price'  => (float)($it['unit_price'] ?? 0),
            'tax_rate'    => ($rate == 18) ? 18 : 0,
        ];
    }

    echo json_encode([
        'success'       => true,
        'supplier_id'   => (int)$pr['supplier_id'],
        'supplier_name' => $pr['supplier_name'] . (!empty($pr['company_name']) ? ' — ' . $pr['company_name'] : ''),
        'return_number' => $pr['return_number'],
        'reason'        => $pr['reason'],
        'items'         => $items,
    ]);
} catch (PDOException $e) {
    error_log('get_debit_note_source error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
