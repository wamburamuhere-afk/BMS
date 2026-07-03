<?php
// API: List job openings (+ recruitment stats) or a single opening (Tier 4, Phase 4.5).
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('recruitment')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

try {
    $opening_id = intval($_GET['opening_id'] ?? 0);
    if ($opening_id) {
        $stmt = $pdo->prepare("SELECT o.*, des.designation_name, d.department_name FROM job_openings o
                               LEFT JOIN designations des ON des.designation_id=o.designation_id
                               LEFT JOIN departments d ON d.department_id=o.department_id
                               WHERE o.opening_id=? AND o.status!='deleted'");
        $stmt->execute([$opening_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'message' => 'Opening not found']); exit; }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    $status = trim($_GET['status'] ?? '');
    $where = ["o.status != 'deleted'"]; $params = [];
    if ($status !== '') { $where[] = "o.status = ?"; $params[] = $status; }
    $stmt = $pdo->prepare("
        SELECT o.opening_id, o.job_title, o.openings_count, o.close_date, o.status,
               des.designation_name, d.department_name,
               (SELECT COUNT(*) FROM candidates c WHERE c.opening_id=o.opening_id AND c.status='active') AS candidate_count,
               (SELECT COUNT(*) FROM candidates c WHERE c.opening_id=o.opening_id AND c.status='active' AND c.stage='hired') AS hired_count
        FROM job_openings o
        LEFT JOIN designations des ON des.designation_id=o.designation_id
        LEFT JOIN departments d ON d.department_id=o.department_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $year = (int)date('Y');
    $stats = [
        'open_positions' => 0,
        'total_candidates' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE status='active'")->fetchColumn(),
        'in_interview' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE status='active' AND stage='interview'")->fetchColumn(),
        'hired_year' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE status='active' AND stage='hired' AND YEAR(updated_at)=$year")->fetchColumn(),
    ];
    foreach ($rows as $r) if ($r['status'] === 'open') $stats['open_positions']++;

    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);

} catch (Exception $e) {
    error_log("get_openings error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
