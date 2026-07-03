<?php
// API: Manage training participants (Tier 3, Phase 3.5).
// add (one or many, scope-gated) / update (status/score/remarks) / remove.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('trainings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to manage participants']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'add': {
            $training_id = intval($_POST['training_id'] ?? 0);
            $emp_ids = $_POST['employee_ids'] ?? [];
            if (!$training_id) throw new Exception('Training id is required');
            if (!is_array($emp_ids) || !count($emp_ids)) throw new Exception('Select at least one employee');
            $tr = $pdo->query("SELECT title FROM trainings WHERE training_id=$training_id AND status!='deleted'")->fetchColumn();
            if ($tr === false) throw new Exception('Training not found');

            $ins = $pdo->prepare("INSERT IGNORE INTO training_participants (training_id, employee_id, status) VALUES (?, ?, 'enrolled')");
            $added = 0;
            foreach ($emp_ids as $eid) {
                $eid = (int)$eid;
                if (!$eid) continue;
                if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($eid);
                $chk = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id=? AND (status IS NULL OR status!='deleted')");
                $chk->execute([$eid]);
                if (!$chk->fetch()) continue;
                $ins->execute([$training_id, $eid]);
                $added += $ins->rowCount();
            }
            logActivity($pdo, $_SESSION['user_id'], 'Add training participants', "enrolled $added into training #$training_id");
            echo json_encode(['success' => true, 'message' => "$added participant(s) enrolled"]);
            break;
        }
        case 'update': {
            $participant_id = intval($_POST['participant_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            $score  = trim($_POST['score'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            if (!$participant_id) throw new Exception('Participant id is required');
            $valid = ['enrolled','attended','completed','failed','withdrawn'];
            if (!in_array($status, $valid, true)) throw new Exception('Invalid status');
            // scope gate follows the participant's employee
            $row = $pdo->prepare("SELECT p.employee_id FROM training_participants p WHERE p.participant_id=?");
            $row->execute([$participant_id]);
            $eid = $row->fetchColumn();
            if ($eid === false) throw new Exception('Participant not found');
            if (function_exists('assertScopeForEmployee')) assertScopeForEmployee((int)$eid);

            $pdo->prepare("UPDATE training_participants SET status=?, score=?, remarks=?, updated_by=? WHERE participant_id=?")
                ->execute([$status, ($score!==''?$score:null), ($remarks!==''?$remarks:null), $_SESSION['user_id'], $participant_id]);
            logActivity($pdo, $_SESSION['user_id'], 'Update participant', "participant #$participant_id → $status");
            echo json_encode(['success' => true, 'message' => 'Participant updated']);
            break;
        }
        case 'remove': {
            $participant_id = intval($_POST['participant_id'] ?? 0);
            if (!$participant_id) throw new Exception('Participant id is required');
            $row = $pdo->prepare("SELECT employee_id FROM training_participants WHERE participant_id=?");
            $row->execute([$participant_id]);
            $eid = $row->fetchColumn();
            if ($eid === false) throw new Exception('Participant not found');
            if (function_exists('assertScopeForEmployee')) assertScopeForEmployee((int)$eid);
            $pdo->prepare("DELETE FROM training_participants WHERE participant_id=?")->execute([$participant_id]);
            logActivity($pdo, $_SESSION['user_id'], 'Remove participant', "removed participant #$participant_id");
            echo json_encode(['success' => true, 'message' => 'Participant removed']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
