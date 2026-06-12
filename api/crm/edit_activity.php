<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canEdit('crm_activities')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
csrf_check();

$activity_id = intval($_POST['activity_id'] ?? 0);
$subject     = trim($_POST['subject'] ?? '');
if (!$activity_id) { echo json_encode(['success'=>false,'message'=>'activity_id required']); exit; }
if ($subject === '') { echo json_encode(['success'=>false,'message'=>'Subject is required']); exit; }

$allowed_types = ['call','email','meeting','note','task','site_visit'];
$type = $_POST['activity_type'] ?? 'note';
if (!in_array($type, $allowed_types, true)) $type = 'note';

$allowed_statuses = ['pending','done','overdue'];
$status = $_POST['status'] ?? 'pending';
if (!in_array($status, $allowed_statuses, true)) $status = 'pending';

$activity_date = trim($_POST['activity_date'] ?? '');
if ($activity_date === '' || !strtotime($activity_date)) $activity_date = date('Y-m-d H:i:s');

$due_date = trim($_POST['due_date'] ?? '');
$due_date = ($due_date !== '' && strtotime($due_date)) ? $due_date : null;

try {
    $chk = $pdo->prepare("SELECT activity_id FROM crm_lead_activities WHERE activity_id = ? AND status != 'deleted'");
    $chk->execute([$activity_id]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Activity not found']); exit; }

    $pdo->prepare("
        UPDATE crm_lead_activities
        SET activity_type=?, subject=?, description=?, activity_date=?, due_date=?, outcome=?, status=?
        WHERE activity_id=?
    ")->execute([
        $type, $subject,
        trim($_POST['description'] ?? '') ?: null,
        $activity_date, $due_date,
        trim($_POST['outcome'] ?? '') ?: null,
        $status, $activity_id
    ]);

    logActivity($pdo, $_SESSION['user_id'], "Updated activity #$activity_id: $subject");
    echo json_encode(['success'=>true,'message'=>'Activity updated.']);
} catch (PDOException $e) {
    error_log('edit_activity error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error.']);
}
