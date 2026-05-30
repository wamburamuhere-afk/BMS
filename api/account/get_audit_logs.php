<?php
/**
 * api/account/get_audit_logs.php
 *
 * AJAX data source for the System Audit Trail — a merged, filtered view of
 * activity_logs (general actions) + audit_logs (data-change events). Returns a
 * summary count and the rows as JSON. System-wide security logs (no project
 * scope); access is gated by the audit_logs permission.
 *
 * Capped at 2000 most-recent rows for responsiveness (the trail can be large).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('audit_logs')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$user_id    = $_GET['user_id'] ?? '';
$log_type   = $_GET['log_type'] ?? '';   // '', 'activity', 'audit'
$action     = trim($_GET['action'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    echo json_encode(['success'=>false,'message'=>'Invalid date range']); exit;
}
$from = $start_date . ' 00:00:00';
$to   = $end_date   . ' 23:59:59';
$ROW_CAP = 2000;

try {
    global $pdo;

    $parts = [];
    $params = [];

    // activity_logs branch
    if ($log_type === '' || $log_type === 'activity') {
        $w = ["al.created_at BETWEEN ? AND ?"];
        $p = [$from, $to];
        if ($user_id !== '') { $w[] = "al.user_id = ?"; $p[] = (int)$user_id; }
        if ($action !== '')  { $w[] = "al.action LIKE ?"; $p[] = '%'.$action.'%'; }
        $parts[] = "
            SELECT al.id, al.user_id, al.action, 'Activity' AS log_type,
                   NULL AS entity_type, al.description, al.ip_address, al.created_at
              FROM activity_logs al
             WHERE " . implode(' AND ', $w);
        $params = array_merge($params, $p);
    }

    // audit_logs branch
    if ($log_type === '' || $log_type === 'audit') {
        $w = ["au.created_at BETWEEN ? AND ?"];
        $p = [$from, $to];
        if ($user_id !== '') { $w[] = "au.user_id = ?"; $p[] = (int)$user_id; }
        if ($action !== '')  { $w[] = "au.action LIKE ?"; $p[] = '%'.$action.'%'; }
        $parts[] = "
            SELECT au.id, au.user_id, au.action, 'Audit' AS log_type,
                   au.entity_type, au.description, au.ip_address, au.created_at
              FROM audit_logs au
             WHERE " . implode(' AND ', $w);
        $params = array_merge($params, $p);
    }

    if (empty($parts)) { echo json_encode(['success'=>true,'summary'=>['total'=>0,'activity'=>0,'audit'=>0],'rows'=>[]]); exit; }

    $sql = "
        SELECT t.*, COALESCE(u.username, CONCAT('User #', t.user_id)) AS username
          FROM ( " . implode(' UNION ALL ', $parts) . " ) t
          LEFT JOIN users u ON t.user_id = u.user_id
      ORDER BY t.created_at DESC
         LIMIT $ROW_CAP
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $activity = 0; $audit = 0;
    foreach ($rows as $r) { $r['log_type'] === 'Audit' ? $audit++ : $activity++; }

    echo json_encode([
        'success' => true,
        'summary' => [
            'total'    => count($rows),
            'activity' => $activity,
            'audit'    => $audit,
            'capped'   => count($rows) >= $ROW_CAP,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_audit_logs error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
