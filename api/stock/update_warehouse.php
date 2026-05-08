<?php
// api/stock/update_warehouse.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

// Check permission
if (!isAdmin() && !canEdit('warehouses')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $warehouse_name = trim($_POST['warehouse_name'] ?? '');
    $warehouse_code = trim($_POST['warehouse_code'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $manager_name = trim($_POST['manager_name'] ?? '');
    $manager_phone = trim($_POST['manager_phone'] ?? '');
    $capacity = ($_POST['capacity'] ?? null) ?: null;
    $status = $_POST['status'] ?? 'active';
    $is_primary = isset($_POST['is_primary']) ? 1 : 0;
    $project_id = ($_POST['project_id'] ?? null) ?: null;
    $notes = trim($_POST['notes'] ?? '');

    // Validate input
    if (empty($warehouse_id)) {
        echo json_encode(['success' => false, 'message' => 'Warehouse ID is required']);
        exit;
    }
    if (empty($warehouse_name)) {
        echo json_encode(['success' => false, 'message' => 'Warehouse name is required']);
        exit;
    }
    if (empty($warehouse_code)) {
        echo json_encode(['success' => false, 'message' => 'Warehouse code is required']);
        exit;
    }

    // Check if warehouse code already exists (excluding current warehouse)
    $check_stmt = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_code = ? AND warehouse_id != ?");
    $check_stmt->execute([$warehouse_code, $warehouse_id]);
    if ($check_stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => "Warehouse code '{$warehouse_code}' already exists."]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // If setting as primary, update all others to not primary
        if ($is_primary) {
            $stmt_primary = $pdo->prepare("UPDATE warehouses SET is_primary = 0 WHERE is_primary = 1 AND warehouse_id != ?");
            $stmt_primary->execute([$warehouse_id]);
        }

        // Combine location info
        $location_info = trim($city);
        if (!empty($country)) $location_info .= ($location_info ? ', ' : '') . $country;

        $query = "UPDATE warehouses SET
            warehouse_name = ?, warehouse_code = ?, location = ?, address = ?, 
            city = ?, state = ?, country = ?, postal_code = ?,
            contact_person = ?, phone = ?, email = ?, 
            capacity = ?, status = ?, is_primary = ?, project_id = ?, notes = ?, updated_by = ?
            WHERE warehouse_id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $warehouse_name, $warehouse_code, $location_info, $address,
            $city, $state, $country, $postal_code,
            $manager_name, $phone, $email,
            $capacity, $status, $is_primary, $project_id, $notes, $user_id, $warehouse_id
        ]);

        logActivity($pdo, $user_id, 'Updated Warehouse (via API)', "User updated warehouse: $warehouse_name ($warehouse_code)");
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Warehouse updated successfully!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
