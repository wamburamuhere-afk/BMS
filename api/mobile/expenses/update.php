<?php
// Only pending expenses may be edited; paid/approved entries require void+renter
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canEdit('expenses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$expense_id = (int)($body['expense_id'] ?? 0);
if ($expense_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid expense ID']); exit; }

try {
    assertScopeForRecord('expenses', 'expense_id', $expense_id);

    $check = $pdo->prepare("SELECT status FROM expenses WHERE expense_id = ? AND status != 'deleted'");
    $check->execute([$expense_id]);
    $expense = $check->fetch(PDO::FETCH_ASSOC);
    if (!$expense) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Expense not found']); exit; }

    // Only pending expenses are editable on the mobile app;
    // paid/approved entries have ledger postings that cannot be changed here.
    if ($expense['status'] !== 'pending') {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Only pending expenses can be edited. To correct a paid expense, please use the web admin.']);
        exit;
    }

    $sets   = ['updated_at = NOW()', 'updated_by = ?'];
    $params = [$_SESSION['user_id']];

    if (isset($body['description']) && trim($body['description']) !== '') {
        $sets[] = 'description = ?'; $params[] = trim($body['description']);
    }
    if (isset($body['amount'])) {
        $amt = (float)$body['amount'];
        if ($amt <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Amount must be greater than zero']); exit; }
        $sets[] = 'amount = ?'; $params[] = $amt;
    }
    if (isset($body['expense_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['expense_date'])) {
        $sets[] = 'expense_date = ?'; $params[] = $body['expense_date'];
    }
    if (isset($body['notes'])) {
        $sets[] = 'notes = ?'; $params[] = trim($body['notes']) ?: null;
    }

    $params[] = $expense_id;
    $pdo->prepare("UPDATE expenses SET " . implode(', ', $sets) . " WHERE expense_id = ?")
        ->execute($params);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: updated expense #$expense_id");
    echo json_encode(['success'=>true,'message'=>'Expense updated successfully']);
} catch (Throwable $e) {
    error_log('mobile/expenses/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
