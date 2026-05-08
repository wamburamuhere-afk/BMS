<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $bank_account_id = $_POST['bank_account_id'] ?? '';
    $reconciliation_date = $_POST['reconciliation_date'] ?? '';
    $period_start = $_POST['period_start'] ?? '';
    $period_end = $_POST['period_end'] ?? '';
    $statement_balance = $_POST['statement_balance'] ?? 0;
    $book_balance = $_POST['book_balance'] ?? 0; // Use submitted book balance or calculate? Form sends it.
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

    // If book_balance wasn't sent, fallback to fetching it (though form should send it)
    if ($book_balance === 0 && !isset($_POST['book_balance'])) {
         $stmt = $pdo->prepare("SELECT current_balance FROM accounts WHERE account_id = ?");
         $stmt->execute([$bank_account_id]);
         $book_balance = $stmt->fetchColumn() ?: 0;
    }

    $difference = $statement_balance - $book_balance;
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

    echo json_encode(['success' => true, 'message' => 'Reconciliation created successfully', 'reconciliation_id' => $reconciliation_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
