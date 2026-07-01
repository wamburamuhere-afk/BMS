<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('crm_labels') && !canEdit('crm_labels')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$label_id   = intval($_POST['label_id'] ?? 0);
$label_name = trim($_POST['label_name'] ?? '');
$color      = trim($_POST['color'] ?? '#0d6efd');

if ($label_name === '') { echo json_encode(['success' => false, 'message' => 'Label name is required']); exit; }
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#0d6efd';

try {
    if ($label_id) {
        if (!canEdit('crm_labels')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $chk = $pdo->prepare("SELECT label_id FROM crm_labels WHERE label_id = ? AND status != 'deleted'");
        $chk->execute([$label_id]);
        if (!$chk->fetchColumn()) { echo json_encode(['success' => false, 'message' => 'Label not found']); exit; }
        $pdo->prepare("UPDATE crm_labels SET label_name = ?, color = ? WHERE label_id = ?")
            ->execute([$label_name, $color, $label_id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated CRM label: $label_name");
        echo json_encode(['success' => true, 'message' => "Label \"$label_name\" updated."]);
    } else {
        if (!canCreate('crm_labels')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        // Check duplicate
        $dup = $pdo->prepare("SELECT COUNT(*) FROM crm_labels WHERE label_name = ? AND status != 'deleted'");
        $dup->execute([$label_name]);
        if ((int)$dup->fetchColumn() > 0) { echo json_encode(['success' => false, 'message' => "Label \"$label_name\" already exists."]); exit; }
        $pdo->prepare("INSERT INTO crm_labels (label_name, color, status, created_by) VALUES (?, ?, 'active', ?)")
            ->execute([$label_name, $color, $_SESSION['user_id']]);
        $new_id = $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created CRM label: $label_name");
        echo json_encode(['success' => true, 'message' => "Label \"$label_name\" created.", 'label_id' => $new_id]);
    }
} catch (PDOException $e) {
    error_log('save_label error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
