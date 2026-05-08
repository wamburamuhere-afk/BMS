<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

$district_id = isset($_GET['district_id']) ? intval($_GET['district_id']) : 0;

if (!$district_id) {
    echo json_encode(['success' => false, 'message' => 'District ID is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT council_id, council_name FROM councils WHERE district_id = ? ORDER BY council_name");
    $stmt->execute([$district_id]);
    $councils = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $councils]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
