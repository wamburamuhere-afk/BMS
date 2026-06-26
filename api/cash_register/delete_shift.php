<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !canDelete('cash_register'))) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit();
}

try {
    $shift_id = intval($_POST['shift_id'] ?? 0);

    if (!$shift_id) {
        throw new Exception("Shift ID is required.");
    }

    // Check if shift exists
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ?");
    $stmt->execute([$shift_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        throw new Exception("Shift record not found.");
    }

    // Start transaction to delete shift and its transactions
    $pdo->beginTransaction();

    // Detach sales (set shift_id to NULL instead of deleting sales to protect history/inventory)
    $detachSales = $pdo->prepare("UPDATE pos_sales SET shift_id = NULL WHERE shift_id = ?");
    $detachSales->execute([$shift_id]);

    // Delete transactions
    $delTrans = $pdo->prepare("DELETE FROM cash_register_transactions WHERE shift_id = ?");
    $delTrans->execute([$shift_id]);

    // Delete shift
    $delShift = $pdo->prepare("DELETE FROM cash_register_shifts WHERE shift_id = ?");
    $delShift->execute([$shift_id]);

    $pdo->commit();

    // Phase 3b — deleting a shift removes audit history; high-sensitivity event.
    logActivity($pdo, $_SESSION['user_id'], "Delete cash register shift", "deleted cash register shift with id $shift_id (transactions removed, sales detached)");

    echo json_encode([
        'success' => true,
        'message' => 'Shift and associated transactions deleted successfully'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
