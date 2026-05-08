<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;

    $stmt = $pdo->prepare("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status = 'active' ORDER BY rate_percentage DESC");
    $stmt->execute();
    $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $rates
    ]);

} catch (Exception $e) {
    error_log("Error fetching tax rates: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
