<?php
// API: Manage trainings (Tier 3, Phase 3.5).
// add / update / change_status / delete. Cost is informational only (D21) —
// no ledger posting; actual payments flow through Expenses as usual.
// Status: planned → in_progress → completed / cancelled. Completion requires
// every participant to be in a terminal state (completed/failed/withdrawn).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');
$needsCreate = ($action === 'add');
if ($needsCreate ? !canCreate('trainings') : !canEdit('trainings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to manage trainings']);
    exit;
}

try {
    switch ($action) {
        case 'add':
        case 'update': {
            $training_id = intval($_POST['training_id'] ?? 0);
            $type_id = intval($_POST['training_type_id'] ?? 0);
            $title   = trim($_POST['title'] ?? '');
            $desc    = trim($_POST['description'] ?? '');
            $trainer_kind = ($_POST['trainer_kind'] ?? 'internal') === 'external' ? 'external' : 'internal';
            $trainer_emp  = intval($_POST['trainer_employee_id'] ?? 0);
            $trainer_name = trim($_POST['trainer_name'] ?? '');
            $venue   = trim($_POST['venue'] ?? '');
            $start   = trim($_POST['start_date'] ?? '');
            $end     = trim($_POST['end_date'] ?? '');
            $cost    = trim($_POST['cost'] ?? '');

            if (!$type_id) throw new Exception('Training type is required');
            if ($title === '') throw new Exception('Title is required');
            if (!strtotime($start)) throw new Exception('A valid start date is required');
            if ($end !== '' && (!strtotime($end) || strtotime($end) < strtotime($start))) throw new Exception('End date must be on or after the start date');
            if ($cost !== '' && (!is_numeric($cost) || (float)$cost < 0)) throw new Exception('Cost must be a non-negative number');
            $chk = $pdo->prepare("SELECT training_type_id FROM training_types WHERE training_type_id=? AND status='active'");
            $chk->execute([$type_id]);
            if (!$chk->fetch()) throw new Exception('Training type does not exist or is inactive');

            if ($trainer_kind === 'internal') { $trainer_name = null; if (!$trainer_emp) $trainer_emp = null; }
            else { $trainer_emp = null; if ($trainer_name === '') throw new Exception('External trainer name is required'); }

            if ($action === 'add') {
                $pdo->prepare("
                    INSERT INTO trainings (training_type_id, title, description, trainer_kind, trainer_employee_id, trainer_name, venue, start_date, end_date, cost, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planned', ?)
                ")->execute([$type_id, $title, ($desc!==''?$desc:null), $trainer_kind, $trainer_emp, $trainer_name, ($venue!==''?$venue:null), $start, ($end!==''?$end:null), ($cost!==''?(float)$cost:null), $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
                logActivity($pdo, $_SESSION['user_id'], 'Add training', "created training '$title'");
                echo json_encode(['success' => true, 'message' => 'Training created', 'training_id' => $id]);
            } else {
                if (!$training_id) throw new Exception('Training id is required');
                $pdo->prepare("
                    UPDATE trainings SET training_type_id=?, title=?, description=?, trainer_kind=?, trainer_employee_id=?, trainer_name=?, venue=?, start_date=?, end_date=?, cost=?, updated_by=?
                    WHERE training_id=? AND status!='deleted'
                ")->execute([$type_id, $title, ($desc!==''?$desc:null), $trainer_kind, $trainer_emp, $trainer_name, ($venue!==''?$venue:null), $start, ($end!==''?$end:null), ($cost!==''?(float)$cost:null), $_SESSION['user_id'], $training_id]);
                logActivity($pdo, $_SESSION['user_id'], 'Update training', "updated training #$training_id");
                echo json_encode(['success' => true, 'message' => 'Training updated']);
            }
            break;
        }
        case 'change_status': {
            $training_id = intval($_POST['training_id'] ?? 0);
            $new = trim($_POST['status'] ?? '');
            if (!$training_id) throw new Exception('Training id is required');
            $cur = $pdo->query("SELECT status FROM trainings WHERE training_id=$training_id AND status!='deleted'")->fetchColumn();
            if ($cur === false) throw new Exception('Training not found');
            $map = ['planned' => ['in_progress','cancelled'], 'in_progress' => ['completed','cancelled']];
            if (!in_array($new, $map[$cur] ?? [], true)) throw new Exception("Cannot move a training from $cur to $new");
            if ($new === 'completed') {
                $open = (int)$pdo->query("SELECT COUNT(*) FROM training_participants WHERE training_id=$training_id AND status IN ('enrolled','attended')")->fetchColumn();
                if ($open > 0) throw new Exception("$open participant(s) are not in a final state yet (complete/fail/withdraw them first)");
            }
            $pdo->prepare("UPDATE trainings SET status=?, updated_by=? WHERE training_id=?")->execute([$new, $_SESSION['user_id'], $training_id]);
            logActivity($pdo, $_SESSION['user_id'], 'Change training status', "training #$training_id → $new");
            echo json_encode(['success' => true, 'message' => "Training marked $new"]);
            break;
        }
        case 'delete': {
            if (!canDelete('trainings')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
            $training_id = intval($_POST['training_id'] ?? 0);
            if (!$training_id) throw new Exception('Training id is required');
            $pdo->prepare("UPDATE trainings SET status='deleted', updated_by=? WHERE training_id=?")->execute([$_SESSION['user_id'], $training_id]);
            logActivity($pdo, $_SESSION['user_id'], 'Delete training', "deleted training #$training_id");
            echo json_encode(['success' => true, 'message' => 'Training deleted']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
