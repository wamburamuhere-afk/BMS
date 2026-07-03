<?php
// API: Manage job openings (Tier 4, Phase 4.5). add/update/change_status/delete.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');
$need = ($action === 'add') ? 'create' : (($action === 'delete') ? 'delete' : 'edit');
$ok = $need === 'create' ? canCreate('recruitment') : ($need === 'delete' ? canDelete('recruitment') : canEdit('recruitment'));
if (!$ok) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    switch ($action) {
        case 'add':
        case 'update': {
            $id = intval($_POST['opening_id'] ?? 0);
            $title = trim($_POST['job_title'] ?? '');
            $desig = ($_POST['designation_id'] ?? '') !== '' ? (int)$_POST['designation_id'] : null;
            $dept = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
            $desc = trim($_POST['description'] ?? '');
            $req = trim($_POST['requirements'] ?? '');
            $count = max(1, (int)($_POST['openings_count'] ?? 1));
            $close = trim($_POST['close_date'] ?? '');
            if ($title === '') throw new Exception('Job title is required');
            if ($close !== '' && !strtotime($close)) throw new Exception('Close date is not a valid date');

            if ($action === 'add') {
                $pdo->prepare("INSERT INTO job_openings (job_title, designation_id, department_id, description, requirements, openings_count, close_date, status, created_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?)")
                    ->execute([$title, $desig, $dept, ($desc!==''?$desc:null), ($req!==''?$req:null), $count, ($close!==''?$close:null), $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
                logActivity($pdo, $_SESSION['user_id'], 'Add opening', "opening '$title'");
                echo json_encode(['success' => true, 'message' => 'Opening created', 'opening_id' => $id]);
            } else {
                if (!$id) throw new Exception('Opening id is required');
                $pdo->prepare("UPDATE job_openings SET job_title=?, designation_id=?, department_id=?, description=?, requirements=?, openings_count=?, close_date=?, updated_by=? WHERE opening_id=? AND status!='deleted'")
                    ->execute([$title, $desig, $dept, ($desc!==''?$desc:null), ($req!==''?$req:null), $count, ($close!==''?$close:null), $_SESSION['user_id'], $id]);
                logActivity($pdo, $_SESSION['user_id'], 'Update opening', "opening #$id");
                echo json_encode(['success' => true, 'message' => 'Opening updated']);
            }
            break;
        }
        case 'change_status': {
            $id = intval($_POST['opening_id'] ?? 0);
            $new = trim($_POST['status'] ?? '');
            if (!$id) throw new Exception('Opening id is required');
            if (!in_array($new, ['open','on_hold','closed'], true)) throw new Exception('Invalid status');
            $pdo->prepare("UPDATE job_openings SET status=?, updated_by=? WHERE opening_id=? AND status!='deleted'")->execute([$new, $_SESSION['user_id'], $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Change opening status', "opening #$id → $new");
            echo json_encode(['success' => true, 'message' => "Opening marked " . str_replace('_', ' ', $new)]);
            break;
        }
        case 'delete': {
            $id = intval($_POST['opening_id'] ?? 0);
            if (!$id) throw new Exception('Opening id is required');
            $pdo->prepare("UPDATE job_openings SET status='deleted', updated_by=? WHERE opening_id=?")->execute([$_SESSION['user_id'], $id]);
            echo json_encode(['success' => true, 'message' => 'Opening deleted']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
