<?php
// api/operations/delete_maintenance_log.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;
$id = $_POST['log_id'] ?? null;

try {
    $stmt = $pdo->prepare("DELETE FROM maintenance_logs WHERE log_id = ?");
    $stmt->execute([$id]);
    echo json_encode(["success" => true, "message" => "Log deleted"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
