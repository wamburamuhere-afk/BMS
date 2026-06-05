<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
require_once __DIR__ . '/../../core/payment_source.php';

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
    $status             = 'pending';
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

        // GAP 1 — NO ledger posting or cash movement at creation. The expense is
        // recorded as 'pending'; the money leaves the bank, the double-entry, and
        // the bank-statement register row are all posted ONLY when the expense is
        // marked PAID (see api/account/update_expense_status.php). The linked
        // payroll is likewise marked paid at that Paid step, not here. This keeps
        // unapproved/rejected expenses from ever touching cash.

        logActivity($pdo, $created_by, "Added new expense: " . $description . " (Amount: " . $amount . ")");

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Expense added successfully', 'id' => $expense_id]);
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
