<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('crm_activities')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$lead_id = intval($_GET['lead_id'] ?? 0);
if (!$lead_id) { echo json_encode(['success'=>false,'message'=>'lead_id required']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT a.*,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS created_by_name
        FROM crm_lead_activities a
        LEFT JOIN users u ON a.created_by = u.user_id
        WHERE a.lead_id = ? AND a.status != 'deleted'
        ORDER BY a.activity_date DESC, a.activity_id DESC
    ");
    $stmt->execute([$lead_id]);
    echo json_encode(['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (PDOException $e) {
    error_log('get_activities error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error.']);
}
