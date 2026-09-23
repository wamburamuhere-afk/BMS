<?php
// scope-audit: skip — project/warehouse scope gates enforced inline before insert
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/payment_source.php';
require_once __DIR__ . '/../../../core/bank_register.php';
require_once __DIR__ . '/../../../core/expense_posting.php';
require_once __DIR__ . '/../../../core/gl_accounts.php';
require_once __DIR__ . '/../../../core/pos_nav.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('expenses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$description  = trim($body['description']  ?? '');
$amount_raw   = $body['amount']           ?? '';
$expense_date = trim($body['expense_date'] ?? '');

if ($description === '')   { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Description is required']); exit; }
if ($amount_raw  === '')   { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Amount is required']); exit; }
if ($expense_date === '')  { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Expense date is required']); exit; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_date)) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Date must be YYYY-MM-DD']); exit; }

$amount       = (float)$amount_raw;
if ($amount <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Amount must be greater than zero']); exit; }

$notes        = trim($body['notes']        ?? '');
$warehouse_id = (int)($body['warehouse_id'] ?? 0) ?: null;
$project_id   = (int)($body['project_id']  ?? 0) ?: null;
$paid_to_type = in_array($body['paid_to_type'] ?? '', ['supplier','staff'], true) ? $body['paid_to_type'] : null;
$paid_to_id   = $paid_to_type ? ((int)($body['paid_to_id'] ?? 0) ?: null) : null;

// Scope gates
if ($project_id   && !userCan('project',   $project_id))   { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: project not in your scope']); exit; }
if ($warehouse_id && !userCan('warehouse', $warehouse_id)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: warehouse not in your scope']); exit; }

try {
    // Simple POS auto-resolves expense account and paid-from account
    $expense_account_id = miscExpenseAccountId($pdo);
    if (!$expense_account_id) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'No expense account found — please create one in the Chart of Accounts']);
        exit;
    }

    $bank_account_id = defaultCashAccountId($pdo);
    if (!$bank_account_id) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'No cash/bank account configured — please set one up first']);
        exit;
    }

    $created_by = (int)$_SESSION['user_id'];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO expenses
            (expense_date, expense_account_id, amount, bank_account_id,
             project_id, warehouse_id, description, notes, status,
             created_by, paid_to_type, paid_to_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?)
    ");
    $stmt->execute([
        $expense_date, $expense_account_id, $amount, $bank_account_id,
        $project_id, $warehouse_id, $description,
        $notes !== '' ? $notes : null,
        $created_by, $paid_to_type, $paid_to_id,
    ]);
    $expense_id = (int)$pdo->lastInsertId();

    // GL posting — same two-step accrual+settle sequence as the web handler
    $ref  = 'EXP-' . $expense_id;
    $desc = 'Expense #' . $expense_id . ': ' . substr($description, 0, 100);

    $accr = postExpenseAccrual($pdo, $expense_id, $expense_account_id, $amount,
        $expense_date, $project_id, $created_by, $ref, $description);

    $settle_debit = $expense_account_id;
    if (!empty($accr['posted'])) {
        $accruedAcc = accruedExpensesAccountId($pdo);
        if ($accruedAcc) $settle_debit = (int)$accruedAcc;
    }

    $txnId = postOutflow(
        $pdo, 'expense',
        $bank_account_id, $settle_debit,
        $amount, $expense_date, $ref, $desc,
        $project_id, 0, null
    );
    if (!$txnId) {
        throw new RuntimeException('Ledger posting failed — check expense and cash accounts are active');
    }

    $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE expense_id = ?")
        ->execute([$txnId, $expense_id]);

    recordBankTransaction(
        $pdo, $bank_account_id, $amount, 'withdrawal',
        $expense_date, $ref, $desc, $created_by
    );

    logActivity($pdo, $created_by, "Mobile: created expense '$description' TZS " . number_format($amount, 2));

    $pdo->commit();

    require_once __DIR__ . '/../../../core/notify.php';
    dispatchEvent($pdo, 'expense.needs_review', [
        'entity_type' => 'expense',
        'entity_id'   => $expense_id,
        'project_id'  => $project_id,
        'title'       => 'Expense: ' . substr($description, 0, 80),
        'message'     => 'New expense (' . number_format($amount, 2) . ') via mobile app',
        'action_url'  => 'expenses/view?id=' . $expense_id,
    ]);

    echo json_encode([
        'success'    => true,
        'expense_id' => $expense_id,
        'message'    => 'Expense recorded successfully',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mobile/expenses/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error: ' . $e->getMessage()]);
}
