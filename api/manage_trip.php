<?php
// API: Manage business trips (Tier 4, Phase 4.3 — D26).
// A trip NEVER moves money: estimated_cost / requested_advance are informational
// only, and expense_reference is a plain string pointing at the real petty-cash/
// expense record (which lives in those existing modules). add / change_status /
// delete. §11.1 transitions: pending→approved/rejected, approved→completed
// (requires a trip report) / cancelled. Requester cannot approve (SoD).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');
$attachment_path = null;

try {
    if ($action === 'add') {
        if (!canCreate('employee_trips')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $purpose = trim($_POST['purpose'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '');
        $est = trim($_POST['estimated_cost'] ?? '');
        $adv = trim($_POST['requested_advance'] ?? '');
        $ref = trim($_POST['expense_reference'] ?? '');

        if (!$employee_id) throw new Exception('Employee is required');
        if ($purpose === '') throw new Exception('Purpose is required');
        if ($destination === '') throw new Exception('Destination is required');
        if (!strtotime($start) || !strtotime($end)) throw new Exception('Valid start and end dates are required');
        if (strtotime($end) < strtotime($start)) throw new Exception('End date must be on or after the start date');
        if ($est !== '' && (!is_numeric($est) || (float)$est < 0)) throw new Exception('Estimated cost must be a non-negative number');
        if ($adv !== '' && (!is_numeric($adv) || (float)$adv < 0)) throw new Exception('Requested advance must be a non-negative number');
        if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);

        $emp = $pdo->prepare("SELECT first_name, last_name, project_id FROM employees WHERE employee_id=? AND (status IS NULL OR status!='deleted')");
        $emp->execute([$employee_id]);
        $er = $emp->fetch(PDO::FETCH_ASSOC);
        if (!$er) throw new Exception('Employee not found');
        $emp_name = trim($er['first_name'] . ' ' . $er['last_name']);

        // Optional attachment — §19 5-step
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) throw new Exception('Attachment upload failed');
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif'];
            if (!in_array($ext, $allowed, true)) throw new Exception('Attachment file type not allowed');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['attachment']['tmp_name']);
            $allowedMime = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','image/jpeg','image/png','image/gif'];
            if (!in_array($mime, $allowedMime, true)) throw new Exception('Attachment content does not match allowed types');
            if ($_FILES['attachment']['size'] > 10*1024*1024) throw new Exception('Attachment exceeds 10MB');
            $safe = bin2hex(random_bytes(16)) . '.' . $ext;
            $dir = __DIR__ . '/../uploads/trips/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . $safe)) throw new Exception('Upload failed');
            $attachment_path = 'uploads/trips/' . $safe;
        }

        $pdo->prepare("INSERT INTO employee_trips (employee_id, purpose, destination, start_date, end_date, estimated_cost, requested_advance, expense_reference, attachment_path, attachment_name, status, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
            ->execute([$employee_id, $purpose, $destination, $start, $end,
                ($est !== '' ? (float)$est : null), ($adv !== '' ? (float)$adv : null), ($ref !== '' ? $ref : null),
                $attachment_path, ($attachment_path ? $_FILES['attachment']['name'] : null), $_SESSION['user_id']]);
        $id = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], 'Add trip', "trip request to $destination for \"$emp_name\"");
        logAudit($pdo, $_SESSION['user_id'], 'create', ['activity_type'=>'create','entity_type'=>'employee_trip','entity_id'=>$id,'description'=>"Trip request: $emp_name → $destination",'new_values'=>['destination'=>$destination,'status'=>'pending']]);
        echo json_encode(['success' => true, 'message' => 'Trip request submitted', 'trip_id' => $id]);
        exit;
    }

    if ($action === 'change_status') {
        $id = intval($_POST['trip_id'] ?? 0);
        $new = trim($_POST['status'] ?? '');
        $reason = trim($_POST['reject_reason'] ?? '');
        $report = trim($_POST['report'] ?? '');
        if (!$id) throw new Exception('Trip id is required');
        if (function_exists('assertScopeForEmployeeRecord')) assertScopeForEmployeeRecord('employee_trips', 'trip_id', $id);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM employee_trips WHERE trip_id=? AND status!='deleted' FOR UPDATE");
        $stmt->execute([$id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trip) throw new Exception('Trip not found');
        $cur = $trip['status'];
        $map = ['pending'=>['approved','rejected','cancelled'], 'approved'=>['completed','cancelled']];
        if (!in_array($new, $map[$cur] ?? [], true)) throw new Exception("Cannot move a trip from $cur to $new");

        // permission per verb
        $verbOk = true;
        if ($new === 'approved') $verbOk = canApprove('employee_trips');
        elseif ($new === 'rejected') $verbOk = function_exists('canReject') ? canReject('employee_trips') : canApprove('employee_trips');
        else $verbOk = canEdit('employee_trips'); // completed/cancelled
        if (!$verbOk) { throw new Exception('You do not have permission for that transition'); }

        // SoD — requester cannot approve their own trip (admins exempt)
        if ($new === 'approved' && (int)$trip['created_by'] === (int)$_SESSION['user_id'] && !isAdmin()) {
            throw new Exception('You cannot approve a trip you requested yourself');
        }
        if ($new === 'rejected' && $reason === '') throw new Exception('A reason is required to reject');
        if ($new === 'completed' && $report === '') throw new Exception('A trip report is required to complete a trip');

        if ($new === 'approved') {
            $pdo->prepare("UPDATE employee_trips SET status='approved', approved_by=?, approved_at=NOW(), updated_by=? WHERE trip_id=?")->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);
        } elseif ($new === 'rejected') {
            $pdo->prepare("UPDATE employee_trips SET status='rejected', reject_reason=?, approved_by=?, approved_at=NOW(), updated_by=? WHERE trip_id=?")->execute([$reason, $_SESSION['user_id'], $_SESSION['user_id'], $id]);
        } elseif ($new === 'completed') {
            $pdo->prepare("UPDATE employee_trips SET status='completed', report=?, updated_by=? WHERE trip_id=?")->execute([$report, $_SESSION['user_id'], $id]);
        } else {
            $pdo->prepare("UPDATE employee_trips SET status='cancelled', updated_by=? WHERE trip_id=?")->execute([$_SESSION['user_id'], $id]);
        }
        logActivity($pdo, $_SESSION['user_id'], 'Change trip status', "trip #$id: $cur → $new");
        logAudit($pdo, $_SESSION['user_id'], 'status_change', ['activity_type'=>'status_change','entity_type'=>'employee_trip','entity_id'=>$id,'description'=>"Trip $cur → $new",'old_values'=>['status'=>$cur],'new_values'=>['status'=>$new]]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Trip marked $new"]);
        exit;
    }

    if ($action === 'delete') {
        if (!canDelete('employee_trips')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $id = intval($_POST['trip_id'] ?? 0);
        if (!$id) throw new Exception('Trip id is required');
        if (function_exists('assertScopeForEmployeeRecord')) assertScopeForEmployeeRecord('employee_trips', 'trip_id', $id);
        $pdo->prepare("UPDATE employee_trips SET status='deleted', updated_by=? WHERE trip_id=?")->execute([$_SESSION['user_id'], $id]);
        logActivity($pdo, $_SESSION['user_id'], 'Delete trip', "deleted trip #$id");
        echo json_encode(['success' => true, 'message' => 'Trip deleted']);
        exit;
    }

    throw new Exception('Unknown action');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($attachment_path !== null && file_exists(__DIR__ . '/../' . $attachment_path)) @unlink(__DIR__ . '/../' . $attachment_path);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
