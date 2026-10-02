<?php
// scope-audit: skip — delegates to api/account/delete_expense.php, which runs assertScopeForRecord
// Mobile wrapper: same code path as the web delete (reverses any accrual/ledger
// posting, archives to deleted_expenses, hard-deletes). Paid expenses must be
// voided first via api/mobile/expenses/void.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canDelete('expenses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

$expense_id = (int)($_POST['expense_id'] ?? 0);
if ($expense_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid expense ID']); exit; }

$chk = $pdo->prepare("SELECT 1 FROM expenses WHERE expense_id = ?");
$chk->execute([$expense_id]);
if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Expense not found']); exit; }

$_POST = ['expense_id' => $expense_id];
mobileRun($pdo, 'expenses/delete', null, function () {
    global $pdo;
    require __DIR__ . '/../../account/delete_expense.php';
});
