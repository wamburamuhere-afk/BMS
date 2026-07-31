<?php
// api/operations/delete_maintenance_log.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/expense_posting.php'; // reverseMaintenanceLedger

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

if (!canDelete('maintenance')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to delete maintenance logs"]);
    exit;
}

$id = (int)($_POST['log_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid log ID"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM maintenance_logs WHERE log_id = ? AND status != 'deleted'");
    $stmt->execute([$id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$log) {
        echo json_encode(["success" => false, "message" => "Log not found or already deleted"]);
        exit;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);

    // Reverse whatever this log posted (settlement, then accrual) BEFORE
    // removing it — a deleted log must never leave an orphaned journal entry
    // pointing at a source that no longer exists (post_principle.md Q6).
    reverseMaintenanceLedger($pdo, $log, $userId);

    // Soft delete (CLAUDE.md §12 — never hard DELETE) — the GL trail above is
    // now clean, so nothing about this row remains financially live; the row
    // itself is kept for audit history like every other module in this codebase.
    $pdo->prepare("UPDATE maintenance_logs
                      SET status = 'deleted', paid_from_account_id = NULL, payment_date = NULL,
                          paid_amount = NULL, transaction_id = NULL
                    WHERE log_id = ?")
        ->execute([$id]);

    logActivity($pdo, $userId, "Delete maintenance log", "deleted maintenance log with id $id");

    echo json_encode(["success" => true, "message" => "Log deleted"]);
} catch (Exception $e) {
    error_log("delete_maintenance_log: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
