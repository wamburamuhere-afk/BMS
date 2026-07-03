<?php
// API: List candidates (optionally by opening/stage) or a single candidate
// with interviews (Tier 4, Phase 4.5).
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('recruitment')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

try {
    $candidate_id = intval($_GET['candidate_id'] ?? 0);
    if ($candidate_id) {
        $stmt = $pdo->prepare("SELECT c.*, o.job_title FROM candidates c JOIN job_openings o ON o.opening_id=c.opening_id WHERE c.candidate_id=? AND c.status!='deleted'");
        $stmt->execute([$candidate_id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) { echo json_encode(['success' => false, 'message' => 'Candidate not found']); exit; }
        $iv = $pdo->prepare("SELECT * FROM candidate_interviews WHERE candidate_id=? AND status!='deleted' ORDER BY interview_date DESC, interview_id DESC");
        $iv->execute([$candidate_id]);
        echo json_encode(['success' => true, 'data' => $c, 'interviews' => $iv->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $opening = intval($_GET['opening_id'] ?? 0);
    $stage = trim($_GET['stage'] ?? '');
    $where = ["c.status = 'active'"]; $params = [];
    if ($opening) { $where[] = "c.opening_id = ?"; $params[] = $opening; }
    if ($stage !== '') { $where[] = "c.stage = ?"; $params[] = $stage; }
    $stmt = $pdo->prepare("
        SELECT c.candidate_id, c.opening_id, c.full_name, c.email, c.phone, c.stage, c.cv_path, c.hired_employee_id,
               o.job_title
        FROM candidates c JOIN job_openings o ON o.opening_id=c.opening_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    error_log("get_candidates error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
