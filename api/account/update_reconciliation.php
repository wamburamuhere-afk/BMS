<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $reconciliation_id = $_POST['reconciliation_id'] ?? '';
    $bank_account_id = $_POST['bank_account_id'] ?? '';
    $reconciliation_date = $_POST['reconciliation_date'] ?? '';
    $period_start = $_POST['period_start'] ?? '';
    $period_end = $_POST['period_end'] ?? '';
    $statement_balance = $_POST['statement_balance'] ?? 0;
    $book_balance = $_POST['book_balance'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 1;

    if (empty($reconciliation_id)) {
        throw new Exception('Reconciliation ID is required');
    }

    if (empty($bank_account_id) || empty($reconciliation_date) || empty($period_start) || empty($period_end)) {
        throw new Exception('Bank Account, Reconciliation Date, and Period dates are required');
    }

    $difference = $statement_balance - $book_balance;

    $stmt = $pdo->prepare("
        UPDATE bank_reconciliations SET
            bank_account_id = ?,
            reconciliation_date = ?,
            period_start = ?,
            period_end = ?,
            statement_balance = ?,
            book_balance = ?,
            difference = ?,
            notes = ?,
            updated_at = NOW()
        WHERE reconciliation_id = ?
    ");
    
    $stmt->execute([
        $bank_account_id,
        $reconciliation_date,
        $period_start,
        $period_end,
        $statement_balance,
        $book_balance,
        $difference,
        $notes,
        $reconciliation_id
    ]);

    // Phase 3a — financial-write audit trail.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Bank Reconciliation", "Reconciliation ID: $reconciliation_id");

    echo json_encode(['success' => true, 'message' => 'Reconciliation updated successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
