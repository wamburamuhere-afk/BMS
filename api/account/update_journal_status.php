<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $entry_id = $_POST['entry_id'] ?? 0;
    $status = $_POST['status'] ?? '';

    if ($entry_id <= 0 || empty($status)) {
        throw new Exception('Missing required parameters');
    }

    $allowed_statuses = ['draft', 'posted', 'void', 'reversed'];
    if (!in_array($status, $allowed_statuses)) {
        throw new Exception('Invalid status');
    }

    $stmt = $pdo->prepare("UPDATE journal_entries SET status = ?, updated_at = NOW(), updated_by = ? WHERE entry_id = ?");
    $result = $stmt->execute([$status, $_SESSION['user_id'], $entry_id]);

    if ($result) {
        logActivity($pdo, $_SESSION['user_id'], "Updated journal entry status to '$status' for entry ID: $entry_id");
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        throw new Exception('Failed to update status');
    }

} catch (Exception $e) {
    error_log("Error in update_journal_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
