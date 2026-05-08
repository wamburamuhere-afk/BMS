<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

$country_id = isset($_GET['country_id']) ? intval($_GET['country_id']) : 0;

if (!$country_id) {
    echo json_encode(['success' => false, 'message' => 'Country ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT region_id, region_name FROM regions WHERE country_id = ? AND is_active = 1 ORDER BY region_name");
    $stmt->execute([$country_id]);
    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $regions]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
