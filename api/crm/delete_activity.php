<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canDelete('crm_activities')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
csrf_check();

$activity_id = intval($_POST['activity_id'] ?? 0);
if (!$activity_id) { echo json_encode(['success'=>false,'message'=>'activity_id required']); exit; }

try {
    $chk = $pdo->prepare("SELECT subject FROM crm_lead_activities WHERE activity_id = ? AND status != 'deleted'");
    $chk->execute([$activity_id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Activity not found']); exit; }

    $pdo->prepare("UPDATE crm_lead_activities SET status='deleted' WHERE activity_id=?")->execute([$activity_id]);

    logActivity($pdo, $_SESSION['user_id'], "Delete activity", "deleted activity \"{$row['subject']}\" with id $activity_id");
    echo json_encode(['success'=>true,'message'=>'Activity deleted.']);
} catch (PDOException $e) {
    error_log('delete_activity error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error.']);
}
