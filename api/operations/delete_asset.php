<?php
// api/operations/delete_asset.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

if (!canDelete('assets')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to delete assets"]);
    exit;
}

$asset_id = $_POST['asset_id'] ?? null;

if (!$asset_id) {
    echo json_encode(["success" => false, "message" => "Asset ID is required"]);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM assets WHERE asset_id = ?");
    $stmt->execute([$asset_id]);

    // Phase 3c — asset deletes are operationally significant; track who/when.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Asset", "Asset ID: $asset_id");

    echo json_encode(["success" => true, "message" => "Asset deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
