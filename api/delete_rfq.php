<?php
// File: api/delete_rfq.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $rfq_id = intval($_POST['rfq_id'] ?? 0);
    if (!$rfq_id) throw new Exception('RFQ ID is required');

    $stmt = $pdo->prepare("SELECT rfq_number FROM rfq WHERE rfq_id = ?");
    $stmt->execute([$rfq_id]);
    $rfq = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rfq) throw new Exception('RFQ not found');

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM rfq_items WHERE rfq_id = ?")->execute([$rfq_id]);
    $pdo->prepare("DELETE FROM rfq WHERE rfq_id = ?")->execute([$rfq_id]);
    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Deleted RFQ #{$rfq['rfq_number']}");
    echo json_encode(['success' => true, 'message' => "RFQ #{$rfq['rfq_number']} deleted successfully."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}