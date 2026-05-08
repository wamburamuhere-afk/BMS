<?php
// api/operations/save_maintenance_log.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

$log_id = $_POST['log_id'] ?? null;
$asset_id = $_POST['asset_id'] ?? null;
$maintenance_date = $_POST['maintenance_date'] ?? null;
$maintenance_type = $_POST['maintenance_type'] ?? 'routine';
$description = $_POST['description'] ?? '';
$cost = $_POST['cost'] ?? 0;
$performed_by = $_POST['performed_by'] ?? '';
$status = $_POST['status'] ?? 'pending';
$completion_date = $_POST['completion_date'] ?? null;
$notes = $_POST['notes'] ?? '';

if (!$asset_id || !$maintenance_date || !$description) {
    echo json_encode(["success" => false, "message" => "Please fill required fields"]);
    exit;
}

try {
    if ($log_id) {
        $stmt = $pdo->prepare("UPDATE maintenance_logs SET 
            asset_id = ?, maintenance_date = ?, maintenance_type = ?, description = ?, 
            cost = ?, performed_by = ?, status = ?, completion_date = ?, notes = ? 
            WHERE log_id = ?");
        $stmt->execute([$asset_id, $maintenance_date, $maintenance_type, $description, $cost, $performed_by, $status, $completion_date, $notes, $log_id]);
        $msg = "Log updated";
    } else {
        $stmt = $pdo->prepare("INSERT INTO maintenance_logs (asset_id, maintenance_date, maintenance_type, description, cost, performed_by, status, completion_date, notes) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$asset_id, $maintenance_date, $maintenance_type, $description, $cost, $performed_by, $status, $completion_date, $notes]);
        $msg = "Log saved";
        
        // Auto-update asset status if needed (e.g., if we log a repair, the asset is and stays in maintenance until completed)
        if ($status != 'completed') {
            $pdo->prepare("UPDATE assets SET status = 'maintenance' WHERE asset_id = ?")->execute([$asset_id]);
        }
    }
    
    echo json_encode(["success" => true, "message" => $msg]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
