<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

$region_id = isset($_GET['region_id']) ? intval($_GET['region_id']) : 0;

if (!$region_id) {
    echo json_encode(['success' => false, 'message' => 'Region ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT district_id, district_name FROM districts WHERE region_id = ? AND is_active = 1 ORDER BY district_name");
    $stmt->execute([$region_id]);
    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $districts]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
