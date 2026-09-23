<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('expenses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid expense ID']); exit; }

try {
    assertScopeForRecord('expenses', 'expense_id', $id);

    $stmt = $pdo->prepare("
        SELECT e.expense_id, e.expense_date, e.description, e.notes, e.amount, e.status,
               e.expense_account_id, ea.account_name AS expense_account_name,
               e.bank_account_id,    ba.account_name AS bank_account_name,
               e.warehouse_id, w.warehouse_name,
               e.project_id, p.project_name,
               e.paid_to_type, e.paid_to_id,
               e.payee_manual_role, e.payee_manual_name,
               e.created_at, e.updated_at
          FROM expenses e
          LEFT JOIN accounts   ea ON ea.account_id  = e.expense_account_id
          LEFT JOIN accounts   ba ON ba.account_id  = e.bank_account_id
          LEFT JOIN warehouses w  ON w.warehouse_id = e.warehouse_id
          LEFT JOIN projects   p  ON p.project_id   = e.project_id
         WHERE e.expense_id = ? AND e.status != 'deleted'
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Expense not found']); exit; }

    echo json_encode(['success'=>true,'data'=>$row]);
} catch (Throwable $e) {
    error_log('mobile/expenses/get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
