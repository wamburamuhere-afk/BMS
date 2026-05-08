<?php
// api/operations/get_asset.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(["success" => false, "message" => "Asset ID is required"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE asset_id = ?");
    $stmt->execute([$id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($asset) {
        echo json_encode(["success" => true, "data" => $asset]);
    } else {
        echo json_encode(["success" => false, "message" => "Asset not found"]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
