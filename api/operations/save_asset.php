<?php
// api/operations/save_asset.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

$asset_id = $_POST['asset_id'] ?? null;
$asset_name = $_POST['asset_name'] ?? '';
$asset_code = $_POST['asset_code'] ?? '';
$category = $_POST['category'] ?? '';
$location = $_POST['location'] ?? '';
$purchase_date = $_POST['purchase_date'] ?? null;
$cost = $_POST['cost'] ?? 0;
$status = $_POST['status'] ?? 'active';
$description = $_POST['description'] ?? '';
$action = $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 1;

if ($action === 'update_status' && $asset_id) {
    try {
        $stmt = $pdo->prepare("UPDATE assets SET status = ?, updated_at = NOW() WHERE asset_id = ?");
        $stmt->execute([$status, $asset_id]);

        // Phase 3c — asset status changes are operationally significant.
        logActivity($pdo, $user_id, "Updated Asset Status", "Asset ID: $asset_id, new status: $status");

        echo json_encode(["success" => true, "message" => "Asset status updated to " . ucfirst($status)]);
        exit;
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
        exit;
    }
}

if (!$asset_name || !$category) {
    echo json_encode(["success" => false, "message" => "Asset Name and Category are required"]);
    exit;
}

try {
    if ($asset_id) {
        // Update
        $stmt = $pdo->prepare("UPDATE assets SET 
            asset_name = ?, 
            asset_code = ?, 
            category = ?, 
            location = ?, 
            purchase_date = ?, 
            cost = ?, 
            status = ?, 
            description = ?, 
            updated_at = NOW() 
            WHERE asset_id = ?");
        $stmt->execute([$asset_name, $asset_code, $category, $location, $purchase_date, $cost, $status, $description, $asset_id]);
        $message = "Asset updated successfully";
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO assets (
            asset_name, 
            asset_code, 
            category, 
            location, 
            purchase_date, 
            cost, 
            status, 
            description, 
            created_by, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$asset_name, $asset_code, $category, $location, $purchase_date, $cost, $status, $description, $user_id]);
        $asset_id = $pdo->lastInsertId();
        $message = "Asset added successfully";
    }

    // Phase 3c — asset writes track operational inventory.
    logActivity(
        $pdo,
        $user_id,
        isset($_POST['asset_id']) && $_POST['asset_id'] ? "Updated Asset" : "Created Asset",
        "Asset ID: $asset_id, name: $asset_name, cost: $cost"
    );

    echo json_encode(["success" => true, "message" => $message]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
