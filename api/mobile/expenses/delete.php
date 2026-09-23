<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canDelete('expenses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$expense_id = (int)($body['expense_id'] ?? 0);
if ($expense_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid expense ID']); exit; }

try {
    assertScopeForRecord('expenses', 'expense_id', $expense_id);

    $check = $pdo->prepare("SELECT description, status FROM expenses WHERE expense_id = ? AND status != 'deleted'");
    $check->execute([$expense_id]);
    $expense = $check->fetch(PDO::FETCH_ASSOC);
    if (!$expense) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Expense not found']); exit; }

    // Paid/approved expenses have ledger postings — cannot soft-delete without reversing GL
    if (in_array($expense['status'], ['paid','approved'], true)) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Cannot delete a paid/approved expense — please use the web admin to void/reverse it']);
        exit;
    }

    $pdo->prepare("UPDATE expenses SET status = 'deleted', updated_at = NOW(), updated_by = ? WHERE expense_id = ?")
        ->execute([$_SESSION['user_id'], $expense_id]);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: deleted expense #$expense_id ({$expense['description']})");
    echo json_encode(['success'=>true,'message'=>'Expense deleted successfully']);
} catch (Throwable $e) {
    error_log('mobile/expenses/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
