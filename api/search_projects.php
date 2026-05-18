<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');

try {
    if ($q === '') {
        $stmt = $pdo->prepare("SELECT project_id AS id, project_name AS text FROM projects WHERE status = 'active' ORDER BY project_name ASC LIMIT 30");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("SELECT project_id AS id, project_name AS text FROM projects WHERE status = 'active' AND project_name LIKE ? ORDER BY project_name ASC LIMIT 30");
        $stmt->execute(['%' . $q . '%']);
    }
    echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (PDOException $e) {
    echo json_encode(['results' => []]);
}
