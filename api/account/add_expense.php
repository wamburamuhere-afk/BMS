<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
require_once __DIR__ . '/../../core/payment_source.php';
require_once __DIR__ . '/../../core/bank_register.php';

header('Content-Type: application/json');

try {
    // Check if user is authenticated
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!canCreate('expenses')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create expenses']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Validate required fields
    $required_fields = ['expense_date', 'amount', 'description']; // Removed expense_account_id from strict requirement
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required field: ' . $field]);
            exit;
        }
    }

    // Sanitize and prepare data
    $expense_date       = $_POST['expense_date'];
    $expense_account_id = !empty($_POST['expense_account_id']) ? intval($_POST['expense_account_id']) : null;
    
    // Fallback: If no account ID provided, pick the first active expense account
    if (!$expense_account_id) {
        $stmtAcc = $pdo->query("SELECT account_id FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%expense%') LIMIT 1");
        $expense_account_id = $stmtAcc->fetchColumn();
        if (!$expense_account_id) {
            throw new Exception("No valid Expense Account found in the system. Please create one in the Chart of Accounts.");
        }
    }
    $type_id            = !empty($_POST['expense_type']) ? intval($_POST['expense_type']) : null;
    $category_id        = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $amount             = floatval($_POST['amount']);
    $bank_account_id    = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
    $project_id         = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;

    // Every expense must name the cash/bank account it is paid from, so the
    // money actually leaves that account (consistent with all other payments).
    if (!$bank_account_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please choose the account the expense is paid from (Paid From).']);
        exit;
    }

    // Phase C — when project_id is supplied, it must be in user scope.
    if ($project_id && !userCan('project', $project_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
        exit;
    }

    $description        = trim($_POST['description']);
    $notes              = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $requested_status = trim($_POST['status'] ?? 'pending');
    $status = ($requested_status === 'paid'
               && $bank_account_id > 0
               && $expense_account_id > 0
               && $amount > 0)
        ? 'paid'
        : 'pending';
    $budget_id          = !empty($_POST['budget_id']) ? intval($_POST['budget_id']) : null;
    $voucher_id         = !empty($_POST['voucher_id']) ? intval($_POST['voucher_id']) : null;
    $created_by         = getCurrentUserId();
    $expense_items      = isset($_POST['expense_items']) ? $_POST['expense_items'] : null;

    // Paid To Logic — unified paid_to_id from form
    $paid_to_type = !empty($_POST['paid_to_type']) ? $_POST['paid_to_type'] : null;
    $paid_to_id   = !empty($_POST['paid_to_id']) ? intval($_POST['paid_to_id']) : null;
    $invoice_id   = !empty($_POST['invoice_id']) ? intval($_POST['invoice_id']) : null;
    $payroll_id   = !empty($_POST['payroll_id']) ? intval($_POST['payroll_id']) : null;

    // Start database transaction
    $pdo->beginTransaction();

    // Insert into database
    $sql = "INSERT INTO expenses (
        expense_date, expense_account_id, type_id, amount, bank_account_id,
        project_id, budget_id, voucher_id, description, notes, status,
        created_by, paid_to_type, paid_to_id, invoice_id, payroll_id, expense_items
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $expense_date, $expense_account_id, $type_id, $amount, $bank_account_id,
        $project_id, $budget_id, $voucher_id, $description, $notes, $status,
        $created_by, $paid_to_type, $paid_to_id, $invoice_id, $payroll_id, $expense_items
    ]);

    if ($result) {
        $expense_id = $pdo->lastInsertId();

        // Insert Category into mapping table
        if ($category_id) {
            $pdo->prepare("INSERT INTO expense_category_map (expense_id, category_id) VALUES (?, ?)")
                ->execute([$expense_id, $category_id]);
        }

        // Direct-pay path: when status=paid is requested (e.g. Quick Expense), post
        // Dr Expense / Cr Bank immediately via postOutflow — same engine used by the
        // manual paid transition in update_expense_status.php. Skipped for pending
        // expenses, which post only when approved then marked paid through the workflow.
        if ($status === 'paid') {
            $ref  = 'EXP-' . $expense_id;
            $desc = 'Expense #' . $expense_id . ': ' . substr($description, 0, 100);

            $txnId = postOutflow(
                $pdo,
                'expense',
                $bank_account_id,    // Cr — cash/bank account (money leaves here)
                $expense_account_id, // Dr — expense GL account (cost recognised here)
                $amount,
                $expense_date,
                $ref,
                $desc,
                $project_id,
                0,    // no WHT on Quick Expense
                null
            );

            if (!$txnId) {
                throw new Exception('Ledger posting failed — verify the expense account and paid-from account are active.');
            }

            $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE expense_id = ?")
                ->execute([$txnId, $expense_id]);

            recordBankTransaction(
                $pdo,
                $bank_account_id,
                $amount,
                'withdrawal',
                $expense_date,
                $ref,
                $desc,
                $created_by
            );
        }

        logActivity($pdo, $created_by, "Added new expense: " . $description . " (Amount: " . $amount . ")");

        $pdo->commit();

        // Build ledger summary for the success notification.
        // Fetch both account labels (code — name) in one query.
        $ledger = null;
        if ($status === 'paid') {
            $accLookup = $pdo->prepare(
                "SELECT account_id, CONCAT(COALESCE(NULLIF(account_code,''),'?'), ' — ', account_name) AS label
                   FROM accounts WHERE account_id IN (?, ?)"
            );
            $accLookup->execute([$expense_account_id, $bank_account_id]);
            $accMap = [];
            foreach ($accLookup->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $accMap[(int)$row['account_id']] = $row['label'];
            }
            $ledger = [
                'dr' => $accMap[$expense_account_id] ?? 'Expense Account',
                'cr' => $accMap[$bank_account_id]    ?? 'Bank/Cash Account',
                'amount' => number_format($amount, 2),
            ];
        }

        echo json_encode([
            'success' => true,
            'message' => 'Expense saved successfully.',
            'id'      => $expense_id,
            'posted'  => ($status === 'paid'),
            'ledger'  => $ledger,
        ]);
    } else {
        throw new Exception('Failed to insert expense');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Database error in add_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in add_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
