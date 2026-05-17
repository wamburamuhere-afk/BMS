<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$inspection_id        = $_POST['inspection_id'] ?? null;
$project_id           = $_POST['project_id'] ?? null;
$milestone_id         = !empty($_POST['milestone_id']) ? intval($_POST['milestone_id']) : null;
$sub_milestone_id     = !empty($_POST['sub_milestone_id']) ? intval($_POST['sub_milestone_id']) : null;
$inspected_scope      = isset($_POST['inspected_scope']) && $_POST['inspected_scope'] !== '' ? floatval($_POST['inspected_scope']) : null;
$inspection_date      = trim($_POST['inspection_date'] ?? '');
$inspection_time      = trim($_POST['inspection_time'] ?? '') ?: null;
$inspection_type      = trim($_POST['inspection_type'] ?? 'Site');
$location_area        = trim($_POST['location_area'] ?? '');
$result               = trim($_POST['result'] ?? '');
$defects_found        = trim($_POST['defects_found'] ?? '');
$corrective_action    = trim($_POST['corrective_action'] ?? '');
$reinspection_required = isset($_POST['reinspection_required']) ? intval($_POST['reinspection_required']) : 0;
$reinspection_date    = !empty($_POST['reinspection_date']) ? $_POST['reinspection_date'] : null;
$signed_off_by        = trim($_POST['signed_off_by'] ?? '');
$notes                = trim($_POST['notes'] ?? '');
$status               = trim($_POST['status'] ?? 'Pending');

// Inspectors array (new multiple-inspector format)
$insp_names = isset($_POST['insp_name']) && is_array($_POST['insp_name']) ? $_POST['insp_name'] : [];
$insp_orgs  = isset($_POST['insp_org'])  && is_array($_POST['insp_org'])  ? $_POST['insp_org']  : [];

// Backward-compat: if old single-inspector fields sent instead (e.g. from edit modal)
$inspector_name = '';
$inspector_org  = '';
if (empty($insp_names) && !empty($_POST['inspector_name'])) {
    $inspector_name = trim($_POST['inspector_name']);
    $inspector_org  = trim($_POST['inspector_org'] ?? '');
} elseif (!empty($insp_names)) {
    $inspector_name = trim($insp_names[0] ?? '');
    $inspector_org  = trim($insp_orgs[0]  ?? '');
}

if (!$project_id || !$inspection_date || !$inspector_name) {
    echo json_encode(['success'=>false,'message'=>'Project, date and inspector name are required']); exit();
}

if ($reinspection_required && $result !== 'Fail' && $result !== 'Conditional Pass') {
    $reinspection_required = 0;
}

try {
    if ($inspection_id) {
        // Update existing inspection
        $stmt = $pdo->prepare("UPDATE project_inspections SET
            milestone_id=?, sub_milestone_id=?, inspected_scope=?,
            inspection_date=?, inspection_time=?, inspection_type=?,
            inspector_name=?, inspector_org=?, location_area=?, result=?,
            defects_found=?, corrective_action=?, reinspection_required=?,
            reinspection_date=?, signed_off_by=?, notes=?, status=?, updated_at=NOW()
            WHERE inspection_id=? AND project_id=?");
        $stmt->execute([
            $milestone_id, $sub_milestone_id, $inspected_scope,
            $inspection_date, $inspection_time, $inspection_type,
            $inspector_name, $inspector_org, $location_area, $result,
            $defects_found, $corrective_action, $reinspection_required,
            $reinspection_date, $signed_off_by, $notes, $status,
            $inspection_id, $project_id
        ]);
        // Replace inspectors if new array provided
        if (!empty($insp_names)) {
            $pdo->prepare("DELETE FROM inspection_inspectors WHERE inspection_id = ?")->execute([$inspection_id]);
            $ins_stmt = $pdo->prepare("INSERT INTO inspection_inspectors (inspection_id, inspector_name, inspector_org, sort_order) VALUES (?,?,?,?)");
            foreach ($insp_names as $i => $nm) {
                $nm = trim($nm);
                if ($nm === '') continue;
                $ins_stmt->execute([$inspection_id, $nm, trim($insp_orgs[$i] ?? ''), $i]);
            }
        }
        logActivity($pdo, $_SESSION['user_id'], "Updated inspection {$inspection_id} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'Inspection updated successfully']);

    } else {
        // Insert new inspection
        $count = $pdo->prepare("SELECT COUNT(*) FROM project_inspections WHERE project_id=?");
        $count->execute([$project_id]);
        $no = 'INS-' . str_pad($count->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT INTO project_inspections
            (project_id, milestone_id, sub_milestone_id, inspected_scope, inspection_no,
             inspection_date, inspection_time, inspection_type,
             inspector_name, inspector_org, location_area, result, defects_found,
             corrective_action, reinspection_required, reinspection_date,
             signed_off_by, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $project_id, $milestone_id, $sub_milestone_id, $inspected_scope, $no,
            $inspection_date, $inspection_time, $inspection_type,
            $inspector_name, $inspector_org, $location_area, $result, $defects_found,
            $corrective_action, $reinspection_required, $reinspection_date,
            $signed_off_by, $notes, $status, $_SESSION['user_id']
        ]);
        $new_id = (int)$pdo->lastInsertId();

        // Save all inspectors to inspection_inspectors table
        if (!empty($insp_names)) {
            $ins_stmt = $pdo->prepare("INSERT INTO inspection_inspectors (inspection_id, inspector_name, inspector_org, sort_order) VALUES (?,?,?,?)");
            foreach ($insp_names as $i => $nm) {
                $nm = trim($nm);
                if ($nm === '') continue;
                $ins_stmt->execute([$new_id, $nm, trim($insp_orgs[$i] ?? ''), $i]);
            }
        }

        // Handle file attachments
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = ROOT_DIR . '/uploads/projects/inspections/' . $new_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif'];
            $max_size = 10 * 1024 * 1024; // 10 MB
            $attach_names = isset($_POST['attach_name']) && is_array($_POST['attach_name']) ? $_POST['attach_name'] : [];
            $att_stmt = $pdo->prepare("INSERT INTO inspection_attachments (inspection_id, file_name, original_name, display_name, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?,?)");

            foreach ($_FILES['attachments']['name'] as $idx => $orig_name) {
                if ($_FILES['attachments']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) continue;
                if ($_FILES['attachments']['size'][$idx] > $max_size) continue;
                $stored = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $orig_name);
                $display_name = trim($attach_names[$idx] ?? '') ?: $orig_name;
                if (move_uploaded_file($_FILES['attachments']['tmp_name'][$idx], $upload_dir . $stored)) {
                    $att_stmt->execute([$new_id, $stored, $orig_name, $display_name, $ext, $_FILES['attachments']['size'][$idx], $_SESSION['user_id']]);
                }
            }
        }

        logActivity($pdo, $_SESSION['user_id'], "Added inspection {$no} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'Inspection recorded successfully','inspection_no'=>$no]);
    }
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
