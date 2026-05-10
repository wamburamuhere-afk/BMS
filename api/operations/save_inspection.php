<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$inspection_id  = $_POST['inspection_id'] ?? null;
$project_id     = $_POST['project_id'] ?? null;
$milestone_id   = !empty($_POST['milestone_id']) ? $_POST['milestone_id'] : null;
$inspection_date= trim($_POST['inspection_date'] ?? '');
$inspection_time= trim($_POST['inspection_time'] ?? '') ?: null;
$inspection_type= trim($_POST['inspection_type'] ?? 'Site');
$inspector_name = trim($_POST['inspector_name'] ?? '');
$inspector_org  = trim($_POST['inspector_org'] ?? '');
$location_area  = trim($_POST['location_area'] ?? '');
$result         = trim($_POST['result'] ?? 'Pass');
$defects_found  = trim($_POST['defects_found'] ?? '');
$corrective_action = trim($_POST['corrective_action'] ?? '');
$reinspection_required = isset($_POST['reinspection_required']) ? 1 : 0;
$reinspection_date = !empty($_POST['reinspection_date']) ? $_POST['reinspection_date'] : null;
$signed_off_by  = trim($_POST['signed_off_by'] ?? '');
$notes          = trim($_POST['notes'] ?? '');
$status         = trim($_POST['status'] ?? 'Open');

if (!$project_id || !$inspection_date || !$inspector_name) {
    echo json_encode(['success'=>false,'message'=>'Project, date and inspector name are required']); exit();
}

if ($reinspection_required && $result !== 'Fail' && $result !== 'Conditional Pass') {
    $reinspection_required = 0;
}

try {
    if ($inspection_id) {
        // Update
        $stmt = $pdo->prepare("UPDATE project_inspections SET
            milestone_id=?, inspection_date=?, inspection_time=?, inspection_type=?,
            inspector_name=?, inspector_org=?, location_area=?, result=?,
            defects_found=?, corrective_action=?, reinspection_required=?,
            reinspection_date=?, signed_off_by=?, notes=?, status=?, updated_at=NOW()
            WHERE inspection_id=? AND project_id=?");
        $stmt->execute([
            $milestone_id, $inspection_date, $inspection_time, $inspection_type,
            $inspector_name, $inspector_org, $location_area, $result,
            $defects_found, $corrective_action, $reinspection_required,
            $reinspection_date, $signed_off_by, $notes, $status,
            $inspection_id, $project_id
        ]);
        logActivity($pdo, $_SESSION['user_id'], "Updated inspection {$inspection_id} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'Inspection updated successfully']);
    } else {
        // Auto-generate inspection_no
        $count = $pdo->prepare("SELECT COUNT(*) FROM project_inspections WHERE project_id=?");
        $count->execute([$project_id]);
        $no = 'INS-' . str_pad($count->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT INTO project_inspections
            (project_id, milestone_id, inspection_no, inspection_date, inspection_time, inspection_type,
             inspector_name, inspector_org, location_area, result, defects_found, corrective_action,
             reinspection_required, reinspection_date, signed_off_by, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $project_id, $milestone_id, $no, $inspection_date, $inspection_time, $inspection_type,
            $inspector_name, $inspector_org, $location_area, $result, $defects_found, $corrective_action,
            $reinspection_required, $reinspection_date, $signed_off_by, $notes, $status, $_SESSION['user_id']
        ]);
        logActivity($pdo, $_SESSION['user_id'], "Added inspection {$no} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'Inspection recorded successfully','inspection_no'=>$no]);
    }
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
