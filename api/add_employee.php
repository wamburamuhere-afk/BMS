<?php
// API: Add New Employee
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/actor_account.php';
require_once __DIR__ . '/../core/code_generator.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canCreate('employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to add employees']);
    exit();
}

try {
    // Basic validation
    $required = ['first_name', 'last_name', 'email', 'phone', 'employee_number', 'department_id', 'designation_id'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field is required");
        }
    }

    // Phase D — gate: can only add employee to a project in scope
    if (!empty($_POST['project_id']) && function_exists('userCan') && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
        exit();
    }

    $pdo->beginTransaction();

    // Employee number follows the company format. If it's blank or the page's
    // suggested "EMP-###" / already-prefixed default, allocate the next sequential
    // company code (e.g. BFS-EMP-0001); a custom number the user typed is honored.
    $prefix = companyCodePrefix($pdo);
    $submittedNo = trim($_POST['employee_number'] ?? '');
    $isAutoNo = ($submittedNo === '')
        || preg_match('/^EMP-\d+$/', $submittedNo)
        || preg_match('#^' . preg_quote($prefix, '#') . '-EMP-\d+$#', $submittedNo);
    $employee_number = $isAutoNo ? nextCode($pdo, 'EMP') : $submittedNo;

    // Generate employee_code if not provided (use employee_number as fallback)
    $employee_code = !empty($_POST['employee_code']) ? trim($_POST['employee_code']) : $employee_number;

    // Check if employee_code, employee_number or email already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_code = ? OR employee_number = ? OR email = ?");
    $stmt->execute([$employee_code, $employee_number, $_POST['email']]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception("Employee code, employee number, or email already exists. Please use unique values.");
    }

    // Handle document uploads
    // Absolute, __DIR__-based save path (DB stores web-relative 'uploads/hr/employees/...').
    $upload_dir = __DIR__ . '/../uploads/hr/employees/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $documents = [];
    $docTypes = [
        'cv' => 'cv_file', 
        'id' => 'id_file', 
        'certificates' => 'certificates_file',
        'intro_letter' => 'intro_letter_file',
        'app_letter' => 'app_letter_file',
        'other_doc' => 'other_doc_file'
    ];

    foreach ($docTypes as $key => $inputName) {
        $is_mandatory = in_array($key, ['cv', 'id', 'certificates']);
        $is_checked = isset($_POST['documents']) && is_array($_POST['documents']) && in_array($key, $_POST['documents']);

        // Mandatory docs are ALWAYS processed. Optional ones only if checked.
        if ($is_mandatory || $is_checked) {
            if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION);
                $file_name = $key . '_' . preg_replace('/[^A-Za-z0-9\-]/', '', $_POST['employee_number']) . '_' . time() . '.' . $file_extension;
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES[$inputName]['tmp_name'], $target_path)) {
                    $doc_rel_path = 'uploads/hr/employees/' . $file_name;
                    $documents[$key] = $doc_rel_path;

                    // Also add to the general document library (documents table)
                    $cat_id = ($key === 'id') ? 6 : 3; // 6: Identification Docs, 3: HR & Employment
                    $doc_label = ($key == 'cv' ? 'CV/Resume' : ($key == 'id' ? 'ID Copy' : ($key == 'certificates' ? 'Certificates' : ($key == 'intro_letter' ? 'Intro Letter' : ($key == 'app_letter' ? 'App Letter' : 'Other Document')))));
                    $full_name = trim($_POST['first_name'] . ' ' . ($_POST['middle_name'] ?? '') . ' ' . $_POST['last_name']);
                    
                    $lib_stmt = $pdo->prepare("
                        INSERT INTO documents (
                            document_name, description, file_path, original_filename, 
                            file_size, file_type, category_id, version, tags, access_level, uploaded_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $lib_stmt->execute([
                        "$full_name - $doc_label",
                        "Employee document for $full_name (" . $_POST['employee_number'] . ")",
                        $doc_rel_path,
                        $_FILES[$inputName]['name'],
                        $_FILES[$inputName]['size'],
                        $file_extension,
                        $cat_id,
                        '1.0',
                        "employee," . str_replace('_', '-', $key),
                        'private',
                        $_SESSION['user_id']
                    ]);
                } else {
                    throw new Exception("Failed to upload " . strtoupper($key) . " document.");
                }
            } else if ($is_mandatory) {
                // Not uploaded and it's mandatory
                $label = ($key == 'cv' ? 'CV/Resume' : ($key == 'id' ? 'ID Copy' : 'Certificates'));
                throw new Exception("Document is compulsory: Please upload " . $label);
            }
        }
    }
    $documents_json = empty($documents) ? null : json_encode($documents);
    $other_doc_name = $_POST['other_doc_name'] ?? null;
    $benefits_json = isset($_POST['benefits']) && is_array($_POST['benefits']) ? json_encode($_POST['benefits']) : null;

    // Tier 2, Phase 2.4 (D14) — optional reporting_to_id from the new Select2
    // manager picker. When provided, dual-write the manager's name into the
    // legacy reporting_to varchar so all existing readers keep working
    // unchanged. When absent (old clients/imports), reporting_to is used as-is.
    $reporting_to_id = ($_POST['reporting_to_id'] ?? '') !== '' ? intval($_POST['reporting_to_id']) : null;
    $reporting_to_name = $_POST['reporting_to'] ?? null;
    if ($reporting_to_id !== null) {
        $mgrStmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
        $mgrStmt->execute([$reporting_to_id]);
        $mgrRow = $mgrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mgrRow) throw new Exception('Selected manager does not exist');
        $reporting_to_name = trim($mgrRow['first_name'] . ' ' . $mgrRow['last_name']);
    }

    // Insert Employee
    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_code, employee_number, first_name, middle_name, last_name,
            gender, date_of_birth, marital_status, national_id, passport_number,
            email, phone, alternate_phone,
            address, physical_address, postal_address, city, country, hire_date,
            probation_end_date, contract_end_date, department_id,
            designation_id, employment_type_id, employment_status, reporting_to, reporting_to_id, work_location,
            basic_salary, hourly_rate, currency, payment_frequency,
            bank_name, bank_account, bank_branch, mobile_money,
            tax_id, social_security_number, emergency_contact,
            emergency_contact_relationship, emergency_contact_phone, emergency_contact_postal_address,
            emergency_contact_physical_address, emergency_contact_email,
            benefits, notes, documents, other_doc_name, project_id, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,   -- row 1: 5
            ?, ?, ?, ?, ?,   -- row 2: 5
            ?, ?, ?,         -- row 3: 3
            ?, ?, ?, ?, ?, ?, -- row 4: 6
            ?, ?, ?,         -- row 5: 3
            ?, ?, ?, ?, ?, ?, -- row 6: 6
            ?, ?, ?, ?,      -- row 7: 4
            ?, ?, ?, ?,      -- row 8: 4
            ?, ?, ?,         -- row 9: 3
            ?, ?, ?, ?, ?,   -- row 10: 5
            ?, ?, ?, ?, ?, ?, NOW() -- row 11: 6 + NOW()
        )
    ");

    $stmt->execute([
        $employee_code,
        $employee_number,
        $_POST['first_name'],
        $_POST['middle_name'] ?? null, 
        $_POST['last_name'], 
        $_POST['gender'] ?? null, 
        $_POST['date_of_birth'] ?? null,
        $_POST['marital_status'] ?? null,
        $_POST['national_id'] ?? null,
        $_POST['passport_number'] ?? null,
        $_POST['email'], 
        $_POST['phone'], 
        $_POST['alternate_phone'] ?? null, 
        $_POST['physical_address'] ?? $_POST['address'] ?? null,
        $_POST['physical_address'] ?? null,
        $_POST['postal_address'] ?? null,
        $_POST['city'] ?? null, 
        $_POST['country'] ?? 'Tanzania',
        $_POST['hire_date'], 
        $_POST['probation_end_date'] ?? null,
        $_POST['contract_end_date'] ?? null,
        $_POST['department_id'], 
        $_POST['designation_id'],
        $_POST['employment_type_id'],
        $_POST['employment_status'] ?? 'probation',
        $reporting_to_name,
        $reporting_to_id,
        $_POST['work_location'] ?? null,
        $_POST['basic_salary'] ?? 0, 
        $_POST['hourly_rate'] ?? 0, 
        $_POST['currency'] ?? 'TZS', 
        ($_POST['payment_frequency'] === 'other') ? ($_POST['payment_frequency_other'] ?? 'other') : ($_POST['payment_frequency'] ?? 'monthly'),
        $_POST['bank_name'] ?? null, 
        $_POST['bank_account'] ?? null, 
        $_POST['bank_branch'] ?? null, 
        $_POST['mobile_money'] ?? null,
        $_POST['tax_id'] ?? null, 
        $_POST['social_security_number'] ?? null,
        $_POST['emergency_contact'] ?? null, 
        $_POST['emergency_contact_relationship'] ?? null,
        $_POST['emergency_contact_phone'] ?? null,
        $_POST['emergency_contact_postal_address'] ?? null,
        $_POST['emergency_contact_physical_address'] ?? null,
        $_POST['emergency_contact_email'] ?? null,
        $benefits_json,
        $_POST['notes'] ?? null, 
        $documents_json,
        $other_doc_name,
        !empty($_POST['project_id']) ? $_POST['project_id'] : null,
        $_SESSION['user_id']
    ]);

    $employee_id = $pdo->lastInsertId();

    $empFullName = trim(
        $_POST['first_name'] . ' ' .
        trim($_POST['middle_name'] ?? '') . ' ' .
        $_POST['last_name']
    );
    ensureActorLedgerAccount($pdo, 'employee', (int) $employee_id, $empFullName);

    logActivity($pdo, $_SESSION['user_id'], 'Create employee', "User created a new employee: $empFullName ({$_POST['employee_number']})");

    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type' => 'employee',
        'entity_id' => $employee_id,
        'description' => "Added new employee: {$_POST['first_name']} {$_POST['last_name']} ({$_POST['employee_number']})",
        'new_values' => $_POST
    ]);

    $pdo->commit();

    // Tier 4 D28(b) — auto-spawn an onboarding checklist if a default template
    // is configured. Runs AFTER the employee transaction commits; guarded +
    // non-fatal so a checklist problem can never fail employee creation.
    if (function_exists('spawnChecklistIfConfigured')) {
        try { spawnChecklistIfConfigured($pdo, (int)$employee_id, 'onboarding', (int)$_SESSION['user_id']); }
        catch (Throwable $e) { error_log('onboarding auto-spawn: ' . $e->getMessage()); }
    } elseif (@is_file(__DIR__ . '/../core/checklists.php')) {
        try { require_once __DIR__ . '/../core/checklists.php'; spawnChecklistIfConfigured($pdo, (int)$employee_id, 'onboarding', (int)$_SESSION['user_id']); }
        catch (Throwable $e) { error_log('onboarding auto-spawn: ' . $e->getMessage()); }
    }

    echo json_encode(['success' => true, 'message' => 'Employee added successfully', 'id' => $employee_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
