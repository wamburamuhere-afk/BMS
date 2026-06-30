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
    require_once __DIR__ . '/../../core/code_generator.php';
    $reconciliation_number = nextCode($pdo, 'REC');   // company-prefixed sequential (BFS-REC-0001)

    // Derive book balance from the posted GL as-of period_end — the only correct
    // basis for reconciliation. current_balance is a live "now" snapshot and must
    // never be used here.
    require_once __DIR__ . '/../../core/account_balance.php';
    $book_balance = accountLedgerBalanceAsOf($pdo, (int)$bank_account_id, $period_end);

    // Period overlap guard: reject if another non-cancelled reconciliation exists
    // for this account whose period overlaps the requested window.
    $overlap = $pdo->prepare(
        "SELECT reconciliation_id FROM bank_reconciliations
          WHERE bank_account_id = ?
            AND status NOT IN ('cancelled')
            AND period_start <= ? AND period_end >= ?
          LIMIT 1"
    );
    $overlap->execute([$bank_account_id, $period_end, $period_start]);
    if ($overlap->fetchColumn()) {
        throw new Exception('A reconciliation for this account already covers part of this period. Cancel or adjust the existing one first.');
    }

    // Beginning-balance chain: opening_balance = adjusted_balance of the most
    // recently finalized reconciliation for this account (period_end < new period_start).
    $priorStmt = $pdo->prepare(
        "SELECT adjusted_balance FROM bank_reconciliations
          WHERE bank_account_id = ?
            AND status = 'reconciled'
            AND period_end < ?
          ORDER BY period_end DESC, reconciliation_id DESC
          LIMIT 1"
    );
    $priorStmt->execute([$bank_account_id, $period_start]);
    $opening_balance = (float)($priorStmt->fetchColumn() ?: 0.00);

    $difference = (float)$statement_balance - (float)$book_balance;
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
            opening_balance,
            difference,
            status,
            notes,
            prepared_by,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $reconciliation_number,
        $bank_account_id,
        $reconciliation_date,
        $period_start,
        $period_end,
        $statement_balance,
        $book_balance,
        $opening_balance,
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
