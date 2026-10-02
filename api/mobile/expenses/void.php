<?php
// scope-audit: skip — delegates to api/account/update_expense_status.php, which runs assertScopeForRecord
// Mobile wrapper: void a paid expense (status → rejected) through the same code
// path as the web — reverses the cash outflow + bank register entry. The record
// can then be deleted via api/mobile/expenses/delete.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canEdit('expenses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

$expense_id = (int)($_POST['expense_id'] ?? 0);
if ($expense_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid expense ID']); exit; }

$st = $pdo->prepare("SELECT status FROM expenses WHERE expense_id = ?");
$st->execute([$expense_id]);
$status = $st->fetchColumn();
if ($status === false) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Expense not found']); exit; }
if ($status === 'rejected') { echo json_encode(['success'=>true,'idempotent'=>true,'message'=>'Expense is already voided']); exit; }
if (!in_array($status, ['paid', 'approved', 'reviewed', 'pending'], true)) {
    http_response_code(409); echo json_encode(['success'=>false,'message'=>"Cannot void an expense with status '$status'"]); exit;
}

$_POST = ['expense_id' => $expense_id, 'status' => 'rejected'];
mobileRun($pdo, 'expenses/void', null, function () {
    global $pdo;
    require __DIR__ . '/../../account/update_expense_status.php';
});
