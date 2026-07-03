<?php
// API: List announcements (Tier 4, Phase 4.2).
// mode=manage  → admin/editor list of all non-deleted announcements + stats.
// mode=feed    → the current announcements THIS user should see (audience match,
//                within publish/expire window), unread-first (for banners / My HR).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$mode = trim($_GET['mode'] ?? 'feed');
$uid  = (int)$_SESSION['user_id'];

try {
    if ($mode === 'manage') {
        if (!canView('announcements')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $status = trim($_GET['status'] ?? '');
        $where = ["a.status != 'deleted'"]; $params = [];
        if ($status !== '') { $where[] = "a.status = ?"; $params[] = $status; }
        $stmt = $pdo->prepare("
            SELECT a.*, d.department_name, p.project_name,
                   (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.announcement_id) AS read_count,
                   DATEDIFF(a.expire_date, CURDATE()) AS days_to_expire
            FROM announcements a
            LEFT JOIN departments d ON d.department_id = a.department_id
            LEFT JOIN projects p ON p.project_id = a.project_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.publish_date DESC, a.announcement_id DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        $stats = ['published_current' => 0, 'drafts' => 0, 'expiring' => 0];
        foreach ($rows as $r) {
            if ($r['status'] === 'draft') $stats['drafts']++;
            if ($r['status'] === 'published' && $r['publish_date'] <= $today && (empty($r['expire_date']) || $r['expire_date'] >= $today)) {
                $stats['published_current']++;
                if (!empty($r['expire_date']) && (int)$r['days_to_expire'] >= 0 && (int)$r['days_to_expire'] <= 7) $stats['expiring']++;
            }
        }
        echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);
        exit;
    }

    // ── feed: what THIS user should currently see ───────────────────────────
    // Audience match: 'all', OR department = user's department, OR project in
    // the user's assigned projects. Admins see all published+current.
    $today = date('Y-m-d');
    $isAdmin = isAdmin();
    $myDept = (int)($pdo->query("SELECT department_id FROM users WHERE user_id=$uid")->fetchColumn() ?: 0);
    $myProjects = [];
    foreach ($pdo->query("SELECT project_id FROM user_projects WHERE user_id=$uid")->fetchAll(PDO::FETCH_COLUMN) as $pid) $myProjects[] = (int)$pid;

    $audienceSql = "a.audience_type = 'all'";
    $params = [];
    if (!$isAdmin) {
        if ($myDept) { $audienceSql .= " OR (a.audience_type='department' AND a.department_id = ?)"; $params[] = $myDept; }
        if ($myProjects) { $in = implode(',', array_fill(0, count($myProjects), '?')); $audienceSql .= " OR (a.audience_type='project' AND a.project_id IN ($in))"; $params = array_merge($params, $myProjects); }
    } else {
        $audienceSql = "1=1"; // admins see every published+current announcement
    }

    $sql = "
        SELECT a.announcement_id, a.title, a.body, a.priority, a.publish_date, a.expire_date,
               (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.announcement_id AND r.user_id = ?) AS is_read
        FROM announcements a
        WHERE a.status = 'published' AND a.publish_date <= ? AND (a.expire_date IS NULL OR a.expire_date >= ?)
          AND ($audienceSql)
        ORDER BY (SELECT COUNT(*) FROM announcement_reads r2 WHERE r2.announcement_id=a.announcement_id AND r2.user_id=?) ASC,
                 FIELD(a.priority,'urgent','important','normal'), a.publish_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$uid, $today, $today], $params, [$uid]));
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    error_log("get_announcements error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
