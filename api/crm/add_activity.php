<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/recalculate_lead_score.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('crm_activities')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
csrf_check();

$lead_id = intval($_POST['lead_id'] ?? 0);
$subject = trim($_POST['subject'] ?? '');
if (!$lead_id) { echo json_encode(['success'=>false,'message'=>'lead_id required']); exit; }
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
if ($due_date !== '' && !strtotime($due_date)) $due_date = null;
$due_date = $due_date ?: null;

try {
    // Validate lead exists
    $chk = $pdo->prepare("SELECT lead_code, first_name, last_name FROM crm_leads WHERE lead_id = ? AND status != 'deleted'");
    $chk->execute([$lead_id]);
    $lead = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$lead) { echo json_encode(['success'=>false,'message'=>'Lead not found']); exit; }

    $pdo->prepare("
        INSERT INTO crm_lead_activities (lead_id, activity_type, subject, description, activity_date, due_date, outcome, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $lead_id, $type, $subject,
        trim($_POST['description'] ?? '') ?: null,
        $activity_date, $due_date,
        trim($_POST['outcome'] ?? '') ?: null,
        $status,
        $_SESSION['user_id']
    ]);
    $aid = (int)$pdo->lastInsertId();

    // Update last_activity timestamp on the lead
    $pdo->prepare("UPDATE crm_leads SET last_activity = NOW() WHERE lead_id = ?")->execute([$lead_id]);

    // Recalculate lead score
    $score = computeLeadScore($pdo, $lead_id);
    $pdo->prepare("UPDATE crm_leads SET lead_score = ? WHERE lead_id = ?")->execute([$score, $lead_id]);

    $full_name = trim($lead['first_name'].' '.$lead['last_name']);
    logActivity($pdo, $_SESSION['user_id'], "Logged $type on lead {$lead['lead_code']} ($full_name): $subject");

    echo json_encode(['success'=>true,'message'=>'Activity logged.','activity_id'=>$aid,'score'=>$score]);
} catch (PDOException $e) {
    error_log('add_activity error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error.']);
}
