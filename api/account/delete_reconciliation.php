<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $reconciliation_id = $_POST['reconciliation_id'] ?? '';

    if (empty($reconciliation_id)) {
        throw new Exception('Reconciliation ID is required');
    }

    $stmt = $pdo->prepare("DELETE FROM bank_reconciliations WHERE reconciliation_id = ?");
    $stmt->execute([$reconciliation_id]);

    // Phase 3a — every reconciliation delete is a high-sensitivity financial event.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Bank Reconciliation", "Reconciliation ID: $reconciliation_id");

    echo json_encode(['success' => true, 'message' => 'Reconciliation deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
