<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $userId = $_SESSION['user_id'] ?? 0;
    $id = $_POST['campaign_id'] ?? null;
    $name = $_POST['campaign_name'] ?? '';
    $type = $_POST['type'] ?? '';
    $target = $_POST['target_audience'] ?? '';
    $start = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $budget = floatval($_POST['budget'] ?? 0);
    $status = $_POST['status'] ?? 'Planned';

    if (empty($name) || empty($type)) {
        throw new Exception("Campaign name and type are required");
    }

    if (!empty($id)) {
        // Update existing
        $stmt = $pdo->prepare("UPDATE marketing_campaigns SET campaign_name = ?, type = ?, target_audience = ?, start_date = ?, end_date = ?, budget = ?, status = ? WHERE campaign_id = ?");
        $stmt->execute([$name, $type, $target, $start, $end, $budget, $status, $id]);
        $msg = "Campaign updated successfully";
        logActivity($pdo, $userId, "Updated Marketing Campaign", "Campaign: $name (ID: $id)");
    } else {
        // Create new
        $stmt = $pdo->prepare("INSERT INTO marketing_campaigns (campaign_name, type, target_audience, start_date, end_date, budget, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $type, $target, $start, $end, $budget, $status, $userId]);
        $newId = $pdo->lastInsertId();
        $msg = "Campaign created successfully";
        logActivity($pdo, $userId, "Created Marketing Campaign", "Campaign: $name (ID: $newId)");
    }

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
