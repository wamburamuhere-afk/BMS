<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!isAuthenticated()) {
        throw new Exception('Unauthorized access');
    }

    if (!canCreate('bank_reconciliation')) {
        throw new Exception('Access Denied: you do not have permission to create bank reconciliations');
    }

    $bank_account_id = $_POST['bank_account_id'] ?? '';
    $reconciliation_date = $_POST['reconciliation_date'] ?? '';
    $period_start = $_POST['period_start'] ?? '';
    $period_end = $_POST['period_end'] ?? '';
    $statement_balance = $_POST['statement_balance'] ?? 0;
    // Book balance is taken authoritatively from the ledger (accounts.current_balance)
    // rather than a blind form value, so the reconciliation difference is meaningful.
    $book_balance = (float)($_POST['book_balance'] ?? 0); // provisional; overwritten below
    $notes = $_POST['notes'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 1; // Fallback to 1 if no session (dev)

    if (empty($bank_account_id) || empty($reconciliation_date) || empty($period_start) || empty($period_end)) {
        throw new Exception('Bank Account, Reconciliation Date, and Period dates are required');
    }

    // Generate Reconciliation Number (REC-YYYYMM-XXXX)
    $datePart = date('Ym');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bank_reconciliations WHERE DATE_FORMAT(created_at, '%Y%m') = ?");
    $stmt->execute([$datePart]);
    $count = $stmt->fetchColumn() + 1;
    $reconciliation_number = 'REC-' . $datePart . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

    // Always derive the book balance from the ledger for this account; keep the
    // submitted value only if the account lookup somehow returns nothing.
    $bb = $pdo->prepare("SELECT current_balance FROM accounts WHERE account_id = ?");
    $bb->execute([$bank_account_id]);
    $bbVal = $bb->fetchColumn();
    if ($bbVal !== false) { $book_balance = (float)$bbVal; }

    $difference = (float)$statement_balance - (float)$book_balance;
    // Status logic: if difference is 0, it might be reconciled, but usually starts as pending until approved? 
    // Schema default is pending. Let's keep it pending or calculate based on difference.
    // User prompt schema default is 'pending'. Let's stick to that unless 0 difference implies auto-reconciled.
    // However, usually 'reconciled' means approved. 'pending' is safer.
    $status = 'pending'; 

    $stmt = $pdo->prepare("
        INSERT INTO bank_reconciliations (
            reconciliation_number,
            bank_account_id, 
            reconciliation_date,
            period_start,
            period_end,
            statement_balance, 
            book_balance, 
            difference, 
            status, 
            notes, 
            prepared_by, 
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $reconciliation_number,
        $bank_account_id,
        $reconciliation_date,
        $period_start,
        $period_end,
        $statement_balance,
        $book_balance,
        $difference,
        $status,
        $notes,
        $user_id
    ]);

    $reconciliation_id = $pdo->lastInsertId();

    // Phase 3a — bank reconciliation is a high-sensitivity financial event.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Create bank reconciliation', "User created a new bank reconciliation (ID $reconciliation_id)");

    echo json_encode(['success' => true, 'message' => 'Reconciliation created successfully', 'reconciliation_id' => $reconciliation_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
