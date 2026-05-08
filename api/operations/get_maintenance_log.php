<?php
// api/operations/get_maintenance_log.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;
$id = $_GET['id'] ?? null;

try {
    $stmt = $pdo->prepare("SELECT m.*, a.asset_name, a.asset_code 
                           FROM maintenance_logs m 
                           JOIN assets a ON m.asset_id = a.asset_id 
                           WHERE m.log_id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(["success" => !!$data, "data" => $data]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
