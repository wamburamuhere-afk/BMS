<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $stmt = $pdo->query("SELECT country_id, country_name FROM countries WHERE is_active = 1 ORDER BY country_name");
    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $countries]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
