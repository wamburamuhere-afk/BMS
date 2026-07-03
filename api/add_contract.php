<?php
// API: Add Employee Contract (Tier 2, Phase 2.3 — D12)
// Creates a DRAFT contract. Nothing on the employees row changes until the
// contract is activated (api/change_contract_status.php) — activation is
// where the D12 dual-write to contract_end_date/probation_end_date happens.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canCreate('employee_contracts')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create employee contracts']);
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

$attachment_path = null;   // unlinked on failure

try {
    $employee_id      = intval($_POST['employee_id'] ?? 0);
    $contract_type    = trim($_POST['contract_type'] ?? '');
    $start_date       = trim($_POST['start_date'] ?? '');
    $end_date         = trim($_POST['end_date'] ?? '');
    $probation_months = trim($_POST['probation_months'] ?? '');
    $basic_salary     = trim($_POST['basic_salary'] ?? '');
    $terms            = trim($_POST['terms'] ?? '');

    if (!$employee_id) throw new Exception('Employee is required');
    if ($contract_type === '') throw new Exception('Contract type is required');
    if ($start_date === '' || !strtotime($start_date)) throw new Exception('A valid start date is required');
    if ($end_date !== '' && (!strtotime($end_date) || strtotime($end_date) < strtotime($start_date))) {
        throw new Exception('End date must be a valid date on or after the start date');
    }
    if ($probation_months !== '' && (!ctype_digit($probation_months) || (int)$probation_months < 0)) {
        throw new Exception('Probation months must be a non-negative number');
    }
    if ($basic_salary !== '' && (!is_numeric($basic_salary) || (float)$basic_salary < 0)) {
        throw new Exception('Basic salary must be a non-negative number');
    }

    // Project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    $stmt = $pdo->prepare("SELECT first_name, last_name, project_id FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) throw new Exception('Employee not found');
    $emp_name = trim($employee['first_name'] . ' ' . $employee['last_name']);

    // Optional signed-copy upload — §19 5-step
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
        $target_dir = __DIR__ . '/../uploads/contracts/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $target_dir . $safe_name)) {
            throw new Exception('Attachment upload failed');
        }
        $attachment_path = 'uploads/contracts/' . $safe_name;
        $attachment_name = $_FILES['attachment']['name'];
    }

    // 5. Business logic
    $pdo->beginTransaction();

    $library_id = null;
    if ($attachment_path !== null && function_exists('registerFileInLibrary')) {
        $library_id = registerFileInLibrary(
            $pdo, $attachment_path, $attachment_name, (int)$_FILES['attachment']['size'],
            'Contract (' . $contract_type . ') — ' . $emp_name,
            'hr,contract,' . strtolower(str_replace(' ', '_', $contract_type)),
            (int)$_SESSION['user_id'],
            $employee['project_id'] !== null ? (int)$employee['project_id'] : null
        );
        if ($library_id && $end_date !== '') {
            $pdo->prepare("UPDATE documents SET expire_date = ? WHERE id = ?")->execute([$end_date, $library_id]);
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO employee_contracts
            (employee_id, contract_type, start_date, end_date, probation_months, basic_salary, terms,
             attachment_path, attachment_name, library_document_id, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
    ");
    $stmt->execute([
        $employee_id, $contract_type, $start_date,
        ($end_date !== '' ? $end_date : null),
        ($probation_months !== '' ? (int)$probation_months : null),
        ($basic_salary !== '' ? (float)$basic_salary : null),
        ($terms !== '' ? $terms : null),
        $attachment_path, ($attachment_path !== null ? $attachment_name : null), $library_id,
        $_SESSION['user_id'],
    ]);
    $contract_id = (int)$pdo->lastInsertId();

    // 6. Activity + audit log
    logActivity($pdo, $_SESSION['user_id'], 'Add employee contract',
        "created $contract_type contract (draft) for employee \"$emp_name\"");
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type'   => 'employee_contract',
        'entity_id'     => $contract_id,
        'description'   => "Created $contract_type contract for $emp_name (draft)",
        'new_values'    => ['contract_type' => $contract_type, 'start_date' => $start_date, 'end_date' => $end_date ?: null, 'status' => 'draft'],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Contract created as draft', 'contract_id' => $contract_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($attachment_path !== null && file_exists(__DIR__ . '/../' . $attachment_path)) {
        @unlink(__DIR__ . '/../' . $attachment_path);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
