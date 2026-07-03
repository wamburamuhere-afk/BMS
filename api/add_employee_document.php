<?php
// API: Add Employee Document (Tier 2 — typed, expiring, library-registered)
// D8: the file is registered in the central documents library and the
// issue/expire dates are mirrored onto the library row, so the existing
// document-expiry cron alerts on it with zero new alert code.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canCreate('employee_documents')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to upload employee documents']);
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

$file_rel = null;   // unlinked on failure

try {
    $employee_id   = intval($_POST['employee_id'] ?? 0);
    $doc_type_id   = intval($_POST['doc_type_id'] ?? 0);
    $document_name = trim($_POST['document_name'] ?? '');
    $issue_date    = trim($_POST['issue_date'] ?? '');
    $expire_date   = trim($_POST['expire_date'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    if (!$employee_id) throw new Exception('Employee is required');
    if (!$doc_type_id) throw new Exception('Document type is required');
    if ($document_name === '') throw new Exception('Document name is required');
    if ($issue_date !== '' && !strtotime($issue_date)) throw new Exception('Issue date is not a valid date');
    if ($expire_date !== '' && !strtotime($expire_date)) throw new Exception('Expiry date is not a valid date');
    if ($issue_date !== '' && $expire_date !== '' && strtotime($expire_date) < strtotime($issue_date)) {
        throw new Exception('Expiry date must be on or after the issue date');
    }

    // Project-scope gate
    if (function_exists('assertScopeForEmployee')) {
        assertScopeForEmployee($employee_id);
    }

    $stmt = $pdo->prepare("SELECT first_name, last_name, project_id FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) throw new Exception('Employee not found');

    $stmt = $pdo->prepare("SELECT type_name, requires_expiry FROM employee_document_types WHERE doc_type_id = ? AND status = 'active'");
    $stmt->execute([$doc_type_id]);
    $doc_type = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc_type) throw new Exception('Document type not found or inactive');
    if ((int)$doc_type['requires_expiry'] === 1 && $expire_date === '') {
        throw new Exception("'{$doc_type['type_name']}' documents require an expiry date");
    }

    // File is mandatory — §19 5-step
    if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) throw new Exception('A file is required');
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('File upload failed');

    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($ext, $allowed_ext, true)) throw new Exception('File type not allowed');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($_FILES['file']['tmp_name']);
    $allowed_mime = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png', 'image/gif'
    ];
    if (!in_array($real_mime, $allowed_mime, true)) throw new Exception('File content does not match allowed types');

    if ($_FILES['file']['size'] > 10 * 1024 * 1024) throw new Exception('File exceeds the 10MB size limit');

    $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
    $target_dir = __DIR__ . '/../uploads/employee_docs/';
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target_dir . $safe_name)) {
        throw new Exception('Upload failed');
    }
    $file_rel = 'uploads/employee_docs/' . $safe_name;

    // 5. Business logic
    $pdo->beginTransaction();

    $emp_name = trim($employee['first_name'] . ' ' . $employee['last_name']);

    // Register in the central library (D8) then mirror the dates onto it,
    // so the existing expiry cron picks it up automatically.
    $library_id = null;
    if (function_exists('registerFileInLibrary')) {
        $library_id = registerFileInLibrary(
            $pdo, $file_rel, $_FILES['file']['name'], (int)$_FILES['file']['size'],
            $document_name . ' — ' . $emp_name,
            'hr,employee,' . strtolower(str_replace(' ', '_', $doc_type['type_name'])),
            (int)$_SESSION['user_id'],
            $employee['project_id'] !== null ? (int)$employee['project_id'] : null
        );
        if ($library_id) {
            $pdo->prepare("UPDATE documents SET issue_date = ?, expire_date = ? WHERE id = ?")
                ->execute([
                    $issue_date !== '' ? $issue_date : null,
                    $expire_date !== '' ? $expire_date : null,
                    $library_id,
                ]);
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO employee_documents
            (employee_id, doc_type_id, document_name, file_path, original_filename, file_size,
             issue_date, expire_date, library_document_id, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $employee_id, $doc_type_id, $document_name, $file_rel, $_FILES['file']['name'],
        (int)$_FILES['file']['size'],
        $issue_date !== '' ? $issue_date : null,
        $expire_date !== '' ? $expire_date : null,
        $library_id, ($notes !== '' ? $notes : null), $_SESSION['user_id']
    ]);
    $emp_doc_id = (int)$pdo->lastInsertId();

    // 6. Activity + audit log
    logActivity($pdo, $_SESSION['user_id'], 'Add employee document',
        "uploaded '{$document_name}' ({$doc_type['type_name']}) for employee \"$emp_name\"");
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type'   => 'employee_document',
        'entity_id'     => $emp_doc_id,
        'description'   => "Uploaded employee document '{$document_name}' for $emp_name",
        'new_values'    => ['doc_type' => $doc_type['type_name'], 'expire_date' => $expire_date ?: null],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Document uploaded', 'emp_doc_id' => $emp_doc_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($file_rel !== null && file_exists(__DIR__ . '/../' . $file_rel)) {
        @unlink(__DIR__ . '/../' . $file_rel);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
