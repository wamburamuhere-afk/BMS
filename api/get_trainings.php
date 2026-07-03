<?php
// API: List trainings (+ stats) or a single training with participants (Tier 3, Phase 3.5).
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('trainings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

try {
    $training_id = intval($_GET['training_id'] ?? 0);

    // ── Single training detail (+ participants) ─────────────────────────────
    if ($training_id) {
        $stmt = $pdo->prepare("
            SELECT t.*, tt.type_name, te.first_name AS trainer_first, te.last_name AS trainer_last
            FROM trainings t
            LEFT JOIN training_types tt ON tt.training_type_id = t.training_type_id
            LEFT JOIN employees te ON te.employee_id = t.trainer_employee_id
            WHERE t.training_id = ? AND t.status != 'deleted'
        ");
        $stmt->execute([$training_id]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t) { echo json_encode(['success' => false, 'message' => 'Training not found']); exit; }

        $parts = $pdo->prepare("
            SELECT p.*, e.first_name, e.last_name, e.employee_number,
                   DATEDIFF(p.certificate_expire_date, CURDATE()) AS cert_days_left
            FROM training_participants p
            JOIN employees e ON e.employee_id = p.employee_id
            WHERE p.training_id = ?
            ORDER BY e.first_name, e.last_name
        ");
        $parts->execute([$training_id]);
        echo json_encode(['success' => true, 'data' => $t, 'participants' => $parts->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── List + stats ────────────────────────────────────────────────────────
    $type_id = intval($_GET['training_type_id'] ?? 0);
    $status  = trim($_GET['status'] ?? '');
    $from    = trim($_GET['date_from'] ?? '');
    $to      = trim($_GET['date_to'] ?? '');

    $where = ["t.status != 'deleted'"];
    $params = [];
    if ($type_id) { $where[] = "t.training_type_id = ?"; $params[] = $type_id; }
    if ($status !== '') { $where[] = "t.status = ?"; $params[] = $status; }
    if ($from !== '' && strtotime($from)) { $where[] = "t.start_date >= ?"; $params[] = $from; }
    if ($to !== '' && strtotime($to)) { $where[] = "t.start_date <= ?"; $params[] = $to; }

    $sql = "
        SELECT t.training_id, t.title, t.start_date, t.end_date, t.status, t.cost,
               tt.type_name,
               (SELECT COUNT(*) FROM training_participants p WHERE p.training_id = t.training_id) AS participant_count
        FROM trainings t
        LEFT JOIN training_types tt ON tt.training_type_id = t.training_type_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.start_date DESC, t.training_id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $year = (int)date('Y');
    $stats = ['planned' => 0, 'in_progress' => 0, 'completed_year' => 0, 'participants_year' => 0];
    foreach ($rows as $r) {
        if ($r['status'] === 'planned') $stats['planned']++;
        if ($r['status'] === 'in_progress') $stats['in_progress']++;
        if ($r['status'] === 'completed' && (int)substr($r['start_date'], 0, 4) === $year) {
            $stats['completed_year']++;
            $stats['participants_year'] += (int)$r['participant_count'];
        }
    }

    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);

} catch (Exception $e) {
    error_log("get_trainings error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
