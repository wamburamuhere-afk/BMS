<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

try {
    $stmt = $pdo->prepare("
        UPDATE crm_lead_activities
        SET    status = 'overdue'
        WHERE  status = 'pending'
          AND  due_date < NOW()
    ");
    $stmt->execute();
    $updated = $stmt->rowCount();
    echo json_encode(['success' => true, 'updated' => $updated]);
} catch (PDOException $e) {
    error_log('mark_overdue_activities error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
