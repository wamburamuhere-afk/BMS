<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

$council_id = isset($_GET['council_id']) ? intval($_GET['council_id']) : 0;

if (!$council_id) {
    echo json_encode(['success' => false, 'message' => 'Council ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT ward_id, ward_name FROM wards WHERE council_id = ? ORDER BY ward_name");
    $stmt->execute([$council_id]);
    $wards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $wards]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
