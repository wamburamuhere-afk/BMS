<?php
// API: Add Employee Lifecycle Event (HR Actions — Tier 1)
// Creates a pending promotion/demotion/transfer/award/warning/complaint/
// resignation/termination record. Old values are snapshotted SERVER-SIDE from
// the employees row — never trusted from the client. Effects apply only on
// approval (api/change_lifecycle_status.php), never here.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/lifecycle_effects.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canCreate('employee_lifecycle')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to record HR actions']);
    exit;
}

// 3. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 4. CSRF + input validation
csrf_check();

$attachment_path = null;   // set on upload; unlinked if the insert fails

try {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $event_type  = trim($_POST['event_type'] ?? '');
    $event_date  = trim($_POST['event_date'] ?? '');
    $end_date    = trim($_POST['end_date'] ?? '');
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $notice_date = trim($_POST['notice_date'] ?? '');

    $valid_types = ['promotion', 'demotion', 'transfer', 'award', 'warning', 'complaint', 'resignation', 'termination', 'leadership'];
    if (!$employee_id) throw new Exception('Employee is required');
    if (!in_array($event_type, $valid_types, true)) throw new Exception('Invalid event type');
    if (!$event_date || !strtotime($event_date)) throw new Exception('A valid event date is required');
    if ($title === '') throw new Exception('Title is required');
    if ($end_date !== '' && (!strtotime($end_date) || strtotime($end_date) < strtotime($event_date))) {
        throw new Exception('End date must be a valid date on or after the event date');
    }
    if ($notice_date !== '' && !strtotime($notice_date)) throw new Exception('Notice date is not a valid date');

    // Project-scope gate — the target employee must be in the caller's scope
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    // Employee must exist and not be soft-deleted
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) throw new Exception('Employee not found');

    // ── Per-type required fields + typed values ────────────────────────────
    $new_designation_id = ($_POST['new_designation_id'] ?? '') !== '' ? intval($_POST['new_designation_id']) : null;
    $new_salary         = ($_POST['new_salary'] ?? '') !== '' ? (float)$_POST['new_salary'] : null;
    $new_department_id  = ($_POST['new_department_id'] ?? '') !== '' ? intval($_POST['new_department_id']) : null;
    $new_project_id     = ($_POST['new_project_id'] ?? '') !== '' ? intval($_POST['new_project_id']) : null;
    $award_type         = trim($_POST['award_type'] ?? '');
    $award_gift         = trim($_POST['award_gift'] ?? '');
    $award_amount       = ($_POST['award_amount'] ?? '') !== '' ? (float)$_POST['award_amount'] : null;
    $severity           = trim($_POST['severity'] ?? '');
    $complainant        = trim($_POST['complainant'] ?? '');
    $resolution         = trim($_POST['resolution'] ?? '');
    $termination_type   = trim($_POST['termination_type'] ?? '');
    $leadership_assistant_id = ($_POST['leadership_assistant_id'] ?? '') !== '' ? intval($_POST['leadership_assistant_id']) : null;

    switch ($event_type) {
        case 'promotion':
        case 'demotion':
            if (!$new_designation_id) throw new Exception('New designation is required for a ' . $event_type);
            $chk = $pdo->prepare("SELECT designation_id FROM designations WHERE designation_id = ? AND status = 'active'");
            $chk->execute([$new_designation_id]);
            if (!$chk->fetch()) throw new Exception('Selected designation does not exist or is inactive');
            if ($new_salary !== null && $new_salary < 0) throw new Exception('New salary cannot be negative');
            break;

        case 'transfer':
            if (!$new_department_id && !$new_project_id) {
                throw new Exception('A transfer needs a new department and/or a new project');
            }
            if ($new_department_id) {
                $chk = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ? AND status = 'active'");
                $chk->execute([$new_department_id]);
                if (!$chk->fetch()) throw new Exception('Selected department does not exist or is inactive');
            }
            if ($new_project_id) {
                $chk = $pdo->prepare("SELECT project_id FROM projects WHERE project_id = ?");
                $chk->execute([$new_project_id]);
                if (!$chk->fetch()) throw new Exception('Selected project does not exist');
                // Destination project must also be in the caller's scope
                if (function_exists('userCan') && !userCan('project', $new_project_id)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Access denied: the destination project is not in your assigned scope.']);
                    exit;
                }
            }
            break;

        case 'warning':
            if (!in_array($severity, ['verbal', 'written', 'final'], true)) {
                throw new Exception('Warning severity must be verbal, written or final');
            }
            break;

        case 'complaint':
            if ($complainant === '') throw new Exception('Complainant is required for a complaint');
            break;

        case 'resignation':
            if ($end_date === '') throw new Exception('Last working day (end date) is required for a resignation');
            break;

        case 'termination':
            if ($termination_type === '') throw new Exception('Termination type is required');
            break;

        case 'leadership':
            // employee_id is the new LEADER (validated required above).
            if (!$new_department_id) throw new Exception('A department is required to assign leadership');
            $chk = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ? AND status = 'active'");
            $chk->execute([$new_department_id]);
            if (!$chk->fetch()) throw new Exception('Selected department does not exist or is inactive');
            if ($leadership_assistant_id !== null) {
                if ($leadership_assistant_id === $employee_id) {
                    throw new Exception('The assistant leader must be a different employee from the leader');
                }
                $chk = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
                $chk->execute([$leadership_assistant_id]);
                if (!$chk->fetch()) throw new Exception('Selected assistant leader was not found');
            }
            break;
    }

    // ── Server-side snapshot of the employee's CURRENT values (D4) ─────────
    $old_designation_id = $employee['designation_id'] !== null ? (int)$employee['designation_id'] : null;
    $old_salary         = $employee['basic_salary'] !== null ? (float)$employee['basic_salary'] : null;
    $old_department_id  = $employee['department_id'] !== null ? (int)$employee['department_id'] : null;
    $old_project_id     = $employee['project_id'] !== null ? (int)$employee['project_id'] : null;

    // ── Optional attachment (security §19 — all five steps) ────────────────
    $attachment_name = null;
    if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) throw new Exception('Attachment upload failed');

        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($ext, $allowed_ext, true)) throw new Exception('Attachment file type not allowed');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $real_mime = $finfo->file($_FILES['attachment']['tmp_name']);
        $allowed_mime = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg', 'image/png', 'image/gif'
        ];
        if (!in_array($real_mime, $allowed_mime, true)) throw new Exception('Attachment content does not match allowed types');

        if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) throw new Exception('Attachment exceeds the 10MB size limit');

        $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target_dir = __DIR__ . '/../uploads/lifecycle/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $target_dir . $safe_name)) {
            throw new Exception('Attachment upload failed');
        }
        $attachment_path = 'uploads/lifecycle/' . $safe_name;
        $attachment_name = $_FILES['attachment']['name'];
    }

    // 5. Business logic — insert the pending event
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO employee_lifecycle_events (
            employee_id, event_type, event_date, end_date, title, description,
            old_designation_id, new_designation_id, old_salary, new_salary,
            old_department_id, new_department_id, leadership_assistant_id, old_project_id, new_project_id,
            award_type, award_gift, award_amount,
            severity, complainant, resolution, termination_type, notice_date,
            status, attachment_path, attachment_name, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
    ");
    $stmt->execute([
        $employee_id, $event_type, $event_date, ($end_date !== '' ? $end_date : null), $title,
        ($description !== '' ? $description : null),
        $old_designation_id, $new_designation_id, $old_salary, $new_salary,
        $old_department_id, $new_department_id, $leadership_assistant_id, $old_project_id, $new_project_id,
        ($award_type !== '' ? $award_type : null), ($award_gift !== '' ? $award_gift : null), $award_amount,
        ($severity !== '' ? $severity : null), ($complainant !== '' ? $complainant : null),
        ($resolution !== '' ? $resolution : null), ($termination_type !== '' ? $termination_type : null),
        ($notice_date !== '' ? $notice_date : null),
        $attachment_path, $attachment_name, $_SESSION['user_id']
    ]);
    $event_id = (int)$pdo->lastInsertId();

    // Department Leadership takes effect IMMEDIATELY (no approval gate): apply
    // the change to the departments table now and mark the event approved so it
    // is a clean, self-contained audit record. This is why a just-assigned
    // leader shows up at once in the department-scoped "Reporting To" picker.
    $leadership_applied = false;
    if ($event_type === 'leadership') {
        applyLifecycleEffectRow($pdo, [
            'event_id'                => $event_id,
            'event_type'              => 'leadership',
            'employee_id'             => $employee_id,
            'new_department_id'       => $new_department_id,
            'leadership_assistant_id' => $leadership_assistant_id,
        ], (int)$_SESSION['user_id']);
        $pdo->prepare("UPDATE employee_lifecycle_events
                       SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_by = ?
                       WHERE event_id = ?")
            ->execute([$_SESSION['user_id'], $_SESSION['user_id'], $event_id]);
        $leadership_applied = true;
    }

    // Register the attachment in the central document library (inside the txn)
    if ($attachment_path !== null && function_exists('registerFileInLibrary')) {
        registerFileInLibrary(
            $pdo, $attachment_path, $attachment_name, (int)$_FILES['attachment']['size'],
            'HR Action: ' . $title, 'hr,lifecycle,' . $event_type, (int)$_SESSION['user_id'],
            $old_project_id
        );
    }

    // 6. Activity + audit log
    $emp_name = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
    logActivity($pdo, $_SESSION['user_id'], 'Add HR action',
        "recorded $event_type \"$title\" for employee \"$emp_name\" (pending approval)");
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type'   => 'employee_lifecycle',
        'entity_id'     => $event_id,
        'description'   => "Recorded $event_type for $emp_name: $title (pending)",
        'new_values'    => [
            'event_type' => $event_type, 'event_date' => $event_date, 'title' => $title,
            'employee_id' => $employee_id, 'status' => 'pending',
        ],
    ]);

    $pdo->commit();
    $msg = $leadership_applied
        ? 'Department leadership updated'
        : ucfirst($event_type) . ' recorded and awaiting approval';
    echo json_encode(['success' => true, 'message' => $msg, 'event_id' => $event_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Don't orphan an uploaded file if the insert failed
    if ($attachment_path !== null && file_exists(__DIR__ . '/../' . $attachment_path)) {
        @unlink(__DIR__ . '/../' . $attachment_path);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
