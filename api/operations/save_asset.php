<?php
// api/operations/save_asset.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

$asset_id = $_POST['asset_id'] ?? null;

if (!empty($asset_id) ? !canEdit('assets') : !canCreate('assets')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to " . (!empty($asset_id) ? 'edit' : 'create') . " assets"]);
    exit;
}

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

// Phase 1 depreciation fields — optional; all NULL means "no schedule yet".
// When a category is chosen on the form, JS pre-fills these from the
// asset_categories defaults; the user can override.
$category_id              = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
$useful_life_years        = isset($_POST['useful_life_years']) && $_POST['useful_life_years'] !== '' ? (int)$_POST['useful_life_years'] : null;
$annual_rate_percent      = isset($_POST['annual_rate_percent']) && $_POST['annual_rate_percent'] !== '' ? (float)$_POST['annual_rate_percent'] : null;
$depreciation_method      = $_POST['depreciation_method'] ?? null;
if ($depreciation_method !== null && !in_array($depreciation_method, ['straight_line','reducing_balance'], true)) {
    $depreciation_method = null;
}
$salvage_value            = isset($_POST['salvage_value']) && $_POST['salvage_value'] !== '' ? (float)$_POST['salvage_value'] : 0;
$depreciation_start_date  = !empty($_POST['depreciation_start_date']) ? $_POST['depreciation_start_date'] : null;

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
            category_id = ?,
            location = ?,
            purchase_date = ?,
            cost = ?,
            status = ?,
            description = ?,
            useful_life_years = ?,
            annual_rate_percent = ?,
            depreciation_method = ?,
            salvage_value = ?,
            depreciation_start_date = ?,
            updated_at = NOW()
            WHERE asset_id = ?");
        $stmt->execute([
            $asset_name, $asset_code, $category, $category_id, $location,
            $purchase_date, $cost, $status, $description,
            $useful_life_years, $annual_rate_percent, $depreciation_method,
            $salvage_value, $depreciation_start_date,
            $asset_id,
        ]);
        $message = "Asset updated successfully";
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO assets (
            asset_name, asset_code, category, category_id, location,
            purchase_date, cost, status, description,
            useful_life_years, annual_rate_percent, depreciation_method,
            salvage_value, depreciation_start_date,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $asset_name, $asset_code, $category, $category_id, $location,
            $purchase_date, $cost, $status, $description,
            $useful_life_years, $annual_rate_percent, $depreciation_method,
            $salvage_value, $depreciation_start_date,
            $user_id,
        ]);
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
} catch (PDOException $e) {
    // Surface user-friendly messages for the integrity-constraint violations
    // most commonly hit by this form, instead of leaking raw SQLSTATE strings.
    $msg = $e->getMessage();
    $friendly = $msg;
    if ($e->getCode() === '23000') {
        if (stripos($msg, "for key 'asset_code'") !== false || stripos($msg, "asset_code") !== false) {
            $friendly = "An asset with this code already exists. Use a different Asset Code.";
        } elseif (stripos($msg, 'fk_assets_category_id') !== false || stripos($msg, 'category_id') !== false) {
            $friendly = "The selected category is no longer available. Please pick another from the dropdown.";
        } else {
            $friendly = "This save conflicts with existing data. Please double-check the form and try again.";
        }
    }
    error_log("save_asset SQLSTATE " . $e->getCode() . ": " . $msg);
    echo json_encode(["success" => false, "message" => $friendly]);
} catch (Exception $e) {
    error_log("save_asset error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
