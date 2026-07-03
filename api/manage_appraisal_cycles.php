<?php
// API: Manage appraisal cycles (Tier 3, Phase 3.3).
// list (view) + add/update/close/reopen/delete (edit). Closing a cycle blocks
// NEW appraisals in it; existing appraisals finish their workflow.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$action = trim($_POST['action'] ?? $_GET['action'] ?? 'list');

if ($action === 'list') {
    if (!canView('hr_performance')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
    $rows = $pdo->query("
        SELECT c.*,
               (SELECT COUNT(*) FROM employee_appraisals a WHERE a.cycle_id = c.cycle_id AND a.status != 'deleted') AS appraisal_count
        FROM appraisal_cycles c
        WHERE c.status != 'deleted'
        ORDER BY c.period_from DESC, c.cycle_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if (!canEdit('hr_performance')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to manage cycles']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    switch ($action) {
        case 'add': {
            $name = trim($_POST['cycle_name'] ?? '');
            $from = trim($_POST['period_from'] ?? '');
            $to   = trim($_POST['period_to'] ?? '');
            if ($name === '') throw new Exception('Cycle name is required');
            if (!strtotime($from) || !strtotime($to)) throw new Exception('Valid period dates are required');
            if (strtotime($to) < strtotime($from)) throw new Exception('Period end must be on or after the start');
            $chk = $pdo->prepare("SELECT cycle_id, status FROM appraisal_cycles WHERE cycle_name = ?");
            $chk->execute([$name]);
            $ex = $chk->fetch(PDO::FETCH_ASSOC);
            if ($ex && $ex['status'] !== 'deleted') throw new Exception('A cycle with that name already exists');
            if ($ex) {
                $pdo->prepare("UPDATE appraisal_cycles SET status='open', period_from=?, period_to=? WHERE cycle_id=?")
                    ->execute([$from, $to, (int)$ex['cycle_id']]);
                $id = (int)$ex['cycle_id'];
            } else {
                $pdo->prepare("INSERT INTO appraisal_cycles (cycle_name, period_from, period_to, created_by) VALUES (?, ?, ?, ?)")
                    ->execute([$name, $from, $to, $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            logActivity($pdo, $_SESSION['user_id'], 'Add appraisal cycle', "created appraisal cycle '$name'");
            echo json_encode(['success' => true, 'message' => 'Cycle saved', 'cycle_id' => $id]);
            break;
        }
        case 'update': {
            $id = intval($_POST['cycle_id'] ?? 0);
            $name = trim($_POST['cycle_name'] ?? '');
            $from = trim($_POST['period_from'] ?? '');
            $to   = trim($_POST['period_to'] ?? '');
            if (!$id || $name === '') throw new Exception('Cycle id and name are required');
            if (!strtotime($from) || !strtotime($to)) throw new Exception('Valid period dates are required');
            if (strtotime($to) < strtotime($from)) throw new Exception('Period end must be on or after the start');
            $pdo->prepare("UPDATE appraisal_cycles SET cycle_name=?, period_from=?, period_to=? WHERE cycle_id=? AND status!='deleted'")
                ->execute([$name, $from, $to, $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Update appraisal cycle', "updated cycle #$id");
            echo json_encode(['success' => true, 'message' => 'Cycle updated']);
            break;
        }
        case 'close':
        case 'reopen': {
            $id = intval($_POST['cycle_id'] ?? 0);
            if (!$id) throw new Exception('Cycle id is required');
            $new = $action === 'close' ? 'closed' : 'open';
            $pdo->prepare("UPDATE appraisal_cycles SET status=? WHERE cycle_id=? AND status!='deleted'")->execute([$new, $id]);
            logActivity($pdo, $_SESSION['user_id'], ucfirst($action) . ' appraisal cycle', "$action cycle #$id");
            echo json_encode(['success' => true, 'message' => "Cycle " . ($action === 'close' ? 'closed' : 'reopened')]);
            break;
        }
        case 'delete': {
            $id = intval($_POST['cycle_id'] ?? 0);
            if (!$id) throw new Exception('Cycle id is required');
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM employee_appraisals WHERE cycle_id=$id AND status!='deleted'")->fetchColumn();
            if ($cnt > 0) throw new Exception("Cannot delete a cycle with $cnt appraisal(s)");
            $pdo->prepare("UPDATE appraisal_cycles SET status='deleted' WHERE cycle_id=?")->execute([$id]);
            logActivity($pdo, $_SESSION['user_id'], 'Delete appraisal cycle', "deleted cycle #$id");
            echo json_encode(['success' => true, 'message' => 'Cycle deleted']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
