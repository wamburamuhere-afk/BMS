<?php
// File: api/approve_rfq.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $rfq_id = intval($_POST['rfq_id'] ?? 0);
    if (!$rfq_id) throw new Exception('RFQ ID is required');

    // Get current RFQ
    $stmt = $pdo->prepare("SELECT rfq_id, rfq_number, status FROM rfq WHERE rfq_id = ?");
    $stmt->execute([$rfq_id]);
    $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rfq) throw new Exception('RFQ not found');
    if ($rfq['status'] === 'approved') throw new Exception('RFQ is already approved');

    // Approve it
    $pdo->prepare("UPDATE rfq SET status = 'approved', updated_at = NOW() WHERE rfq_id = ?")
        ->execute([$rfq_id]);

    logActivity($pdo, $_SESSION['user_id'], "Approved RFQ #{$rfq['rfq_number']}");

    echo json_encode([
        'success' => true,
        'message' => "RFQ #{$rfq['rfq_number']} has been approved successfully."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}