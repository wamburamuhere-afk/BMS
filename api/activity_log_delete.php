<?php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit;
}

// Deleting audit evidence is admin-only — stricter than canView('audit_logs')
if (!isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Admin access required']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']); exit;
}

csrf_check();

$action   = $_POST['action'] ?? '';
$admin_id = (int)$_SESSION['user_id'];

// Build WHERE clause from filter params — mirrors activity_log.php filter logic
function build_log_where(array &$params): string {
    $conditions = [];

    $type      = trim($_POST['type']      ?? '');
    $user_id   = trim($_POST['user_id']   ?? '');
    $date_from = trim($_POST['date_from'] ?? '');
    $date_to   = trim($_POST['date_to']   ?? '');

    if ($type !== '') {
        $conditions[] = 'action LIKE :type';
        $params[':type'] = "%$type%";
    }
    if ($user_id !== '' && ctype_digit($user_id)) {
        $conditions[] = 'activity_logs.user_id = :user_id';
        $params[':user_id'] = (int)$user_id;
    }
    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $conditions[] = 'activity_logs.created_at >= :date_from';
        $params[':date_from'] = $date_from . ' 00:00:00';
    }
    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $conditions[] = 'activity_logs.created_at <= :date_to';
        $params[':date_to'] = $date_to . ' 23:59:59';
    }

    return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
}

try {
    $params = [];
    $where  = build_log_where($params);
    $all_records = empty($params);

    if ($action === 'count') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $where");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        echo json_encode([
            'success'     => true,
            'count'       => (int)$stmt->fetchColumn(),
            'all_records' => $all_records,
        ]);

    } elseif ($action === 'purge') {
        // Re-count right before delete to guard against concurrent changes
        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $where");
        foreach ($params as $k => $v) {
            $cnt_stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $cnt_stmt->execute();
        $count = (int)$cnt_stmt->fetchColumn();

        if ($count === 0) {
            echo json_encode(['success' => false, 'error' => 'No matching records found']); exit;
        }

        $del_stmt = $pdo->prepare("DELETE FROM activity_logs $where");
        foreach ($params as $k => $v) {
            $del_stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $del_stmt->execute();

        // Build readable filter summary for meta-audit trail
        $parts = [];
        if (!empty($_POST['type']))      $parts[] = "type='" . htmlspecialchars($_POST['type']) . "'";
        if (!empty($_POST['user_id']))   $parts[] = "user_id=" . (int)$_POST['user_id'];
        if (!empty($_POST['date_from'])) $parts[] = "from={$_POST['date_from']}";
        if (!empty($_POST['date_to']))   $parts[] = "to={$_POST['date_to']}";
        $filter_desc = !empty($parts) ? implode(', ', $parts) : 'all records (no filter)';

        logActivity($pdo, $admin_id, "Purged $count activity log entries ($filter_desc)");

        echo json_encode([
            'success' => true,
            'count'   => $count,
            'message' => "Purged $count log entries",
        ]);

    } else {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (Exception $e) {
    error_log("Activity log delete error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
