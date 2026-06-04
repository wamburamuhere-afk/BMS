<?php
// File: api/sales/get_credit_note_source.php
// scope-audit: skip — sales return scope verified below via the linked sales order's
// project_id + userCan(), same guard as print_sales_return.php.
// Returns an approved sales return's customer + line items so the Credit Note
// create form can auto-fill from it (§autofill).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('credit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

global $pdo;
$return_id = intval($_GET['sales_return_id'] ?? 0);
if ($return_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid return ID']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT sr.sales_return_id, sr.return_number, sr.customer_id, sr.reason, sr.status,
               so.project_id,
               c.customer_name, c.company_name
          FROM sales_returns sr
          LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
          LEFT JOIN customers c     ON sr.customer_id    = c.customer_id
         WHERE sr.sales_return_id = ?
    ");
    $stmt->execute([$return_id]);
    $sr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sr) { echo json_encode(['success' => false, 'message' => 'Sales return not found']); exit; }
    if ($sr['status'] !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'Only an approved sales return can be credited.']); exit;
    }
    // Project-scope guard (data confidentiality §23)
    if (!empty($sr['project_id']) && !userCan('project', (int)$sr['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This return belongs to a project not in your scope.']); exit;
    }
    // Guard against double-crediting
    $dup = $pdo->prepare("SELECT credit_note_number FROM credit_notes
                           WHERE sales_return_id = ? AND status NOT IN ('deleted','rejected','cancelled') LIMIT 1");
    $dup->execute([$return_id]);
    if ($existing = $dup->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => "This return already has credit note {$existing}."]); exit;
    }

    $stmtI = $pdo->prepare("
        SELECT sri.product_id, sri.quantity, sri.unit_price,
               COALESCE(sri.tax_rate, 0) AS tax_rate,
               COALESCE(p.product_name, 'Item') AS product_name
          FROM sales_return_items sri
          LEFT JOIN products p ON sri.product_id = p.product_id
         WHERE sri.sales_return_id = ?
    ");
    $stmtI->execute([$return_id]);

    $items = [];
    foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $items[] = [
            'product_id'   => $it['product_id'] !== null ? (int)$it['product_id'] : null,
            'description'  => $it['product_name'],
            'quantity'     => (float)$it['quantity'],
            'unit_price'   => (float)$it['unit_price'],
            'tax_rate'     => ((float)$it['tax_rate'] == 18) ? 18 : 0,
        ];
    }

    echo json_encode([
        'success'       => true,
        'customer_id'   => (int)$sr['customer_id'],
        'customer_name' => $sr['customer_name'] . (!empty($sr['company_name']) ? ' — ' . $sr['company_name'] : ''),
        'return_number' => $sr['return_number'],
        'reason'        => $sr['reason'],
        'items'         => $items,
    ]);
} catch (PDOException $e) {
    error_log('get_credit_note_source error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
