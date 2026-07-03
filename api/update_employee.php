<?php
// API: Update Employee
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/code_generator.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit employees']);
    exit();
}

try {
    $employee_id = $_POST['employee_id'] ?? null;
    if (!$employee_id) {
        throw new Exception("Employee ID is required");
    }

    // Phase D — gate: current employee's project
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('employees', 'employee_id', $employee_id);
    }
    // Gate the target project if being reassigned
    if (!empty($_POST['project_id']) && function_exists('userCan') && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: target project not in your scope.']);
        exit();
    }

    $pdo->beginTransaction();

    // Get old values for logging
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$old_values) {
        throw new Exception("Employee not found");
    }

    // Re-code on edit (employees are always editable): upgrade a legacy "EMP-###"
    // number to the company format (BFS-EMP-0001). A custom (non-EMP) number the
    // user typed is honored as-is; an already-converted number is left untouched.
    if (isset($_POST['employee_number'])) {
        $submittedNo = trim($_POST['employee_number']);
        $newNo = codeForEdit($pdo, 'EMP', $submittedNo, 'EMP-\\d+', 'employees', (int)$employee_id);
        if ($newNo !== $submittedNo) {
            // Keep employee_code in sync when it mirrored the number.
            $submittedCode = $_POST['employee_code'] ?? $old_values['employee_code'];
            if ($submittedCode === $submittedNo || $submittedCode === $old_values['employee_number']) {
                $_POST['employee_code'] = $newNo;
            }
            $_POST['employee_number'] = $newNo;
        }
    }

    // Check for duplicate employee_code, employee_number, or email (excluding current employee)
    if (isset($_POST['employee_code']) || isset($_POST['employee_number']) || isset($_POST['email'])) {
        $check_code = $_POST['employee_code'] ?? $old_values['employee_code'];
        $check_number = $_POST['employee_number'] ?? $old_values['employee_number'];
        $check_email = $_POST['email'] ?? $old_values['email'];
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM employees 
            WHERE (employee_code = ? OR employee_number = ? OR email = ?) 
            AND employee_id != ?
        ");
        $stmt->execute([$check_code, $check_number, $check_email, $employee_id]);
        
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Employee code, employee number, or email already exists. Please use unique values.");
        }
    }

    // Handle document uploads
    // Absolute, __DIR__-based save path (DB stores web-relative 'uploads/hr/employees/...').
    $upload_dir = __DIR__ . '/../uploads/hr/employees/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Get existing docs
    $existing_docs = !empty($old_values['documents']) ? json_decode($old_values['documents'], true) : [];
    if (!is_array($existing_docs)) $existing_docs = [];
    
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

        // Mandatory or checked
        if ($is_mandatory || $is_checked) {
            // Did they upload a new file?
            if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION);
                $file_name = $key . '_' . preg_replace('/[^A-Za-z0-9\-]/', '', $_POST['employee_number'] ?? $old_values['employee_number']) . '_' . time() . '.' . $file_extension;
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES[$inputName]['tmp_name'], $target_path)) {
                    $doc_rel_path = 'uploads/hr/employees/' . $file_name;
                    $existing_docs[$key] = $doc_rel_path;

                    // Also add to the general document library (documents table)
                    $cat_id = ($key === 'id') ? 6 : 3; // 6: Identification Docs, 3: HR & Employment
                    $doc_label = ($key == 'cv' ? 'CV/Resume' : ($key == 'id' ? 'ID Copy' : ($key == 'certificates' ? 'Certificates' : ($key == 'intro_letter' ? 'Intro Letter' : ($key == 'app_letter' ? 'App Letter' : 'Other Document')))));
                    $full_name = trim(($_POST['first_name'] ?? $old_values['first_name']) . ' ' . ($_POST['middle_name'] ?? $old_values['middle_name'] ?? '') . ' ' . ($_POST['last_name'] ?? $old_values['last_name']));
                    
                    $lib_stmt = $pdo->prepare("
                        INSERT INTO documents (
                            document_name, description, file_path, original_filename, 
                            file_size, file_type, category_id, version, tags, access_level, uploaded_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $lib_stmt->execute([
                        "$full_name - $doc_label",
                        "Employee document updated for $full_name (" . ($_POST['employee_number'] ?? $old_values['employee_number']) . ")",
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
            } else if ($is_mandatory && !isset($existing_docs[$key])) {
                // It's mandatory but not in DB and not uploaded now
                $label = ($key == 'cv' ? 'CV/Resume' : ($key == 'id' ? 'ID Copy' : 'Certificates'));
                throw new Exception("Document is compulsory: Please upload " . $label);
            }
        }
    }
    $documents_json = empty($existing_docs) ? null : json_encode($existing_docs);
    $_POST['documents'] = $documents_json;

    // Handle "Other" payment frequency if provided
    if (isset($_POST['payment_frequency']) && $_POST['payment_frequency'] === 'other') {
        $_POST['payment_frequency'] = $_POST['payment_frequency_other'] ?? 'other';
    }

    // Handle benefits JSON
    if (isset($_POST['benefits']) && is_array($_POST['benefits'])) {
        $_POST['benefits'] = json_encode($_POST['benefits']);
    }

    // Tier 2, Phase 2.4 (D14) — optional reporting_to_id from the Select2
    // manager picker. Only touched when the field is actually submitted (old
    // clients/imports that never send it leave reporting_to_id untouched).
    // When set, dual-write the manager's name into the legacy reporting_to
    // varchar so all 4 existing readers keep working unchanged; a blank
    // value clears the manager on both columns.
    $reporting_to_id_present = array_key_exists('reporting_to_id', $_POST);
    $new_reporting_to_id = null;
    if ($reporting_to_id_present) {
        $new_reporting_to_id = trim((string)$_POST['reporting_to_id']) !== '' ? (int)$_POST['reporting_to_id'] : null;
        if ($new_reporting_to_id !== null) {
            $mgrStmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
            $mgrStmt->execute([$new_reporting_to_id]);
            $mgrRow = $mgrStmt->fetch(PDO::FETCH_ASSOC);
            if (!$mgrRow) throw new Exception('Selected manager does not exist');
            if ($new_reporting_to_id === (int)$employee_id) throw new Exception('An employee cannot report to themselves');
            $_POST['reporting_to'] = trim($mgrRow['first_name'] . ' ' . $mgrRow['last_name']);
        } else {
            $_POST['reporting_to'] = '';
        }
    }

    // Dynamic update query
    $fields = [
        'employee_code', 'first_name', 'last_name', 'middle_name', 'gender', 'date_of_birth',
        'marital_status', 'national_id', 'passport_number',
        'email', 'phone', 'alternate_phone', 'address', 'physical_address', 'postal_address',
        'city', 'state', 'ward', 'village', 'country',
        'employee_number', 'hire_date', 'probation_end_date', 'contract_end_date',
        'department_id', 'designation_id', 'employment_type_id', 'employment_status', 
        'reporting_to', 'work_location',
        'basic_salary', 'hourly_rate', 'currency', 'payment_frequency',
        'bank_name', 'bank_account', 'bank_branch', 'mobile_money',
        'tax_id', 'social_security_number', 'emergency_contact',
        'emergency_contact_relationship', 'emergency_contact_phone', 'emergency_contact_postal_address',
        'emergency_contact_physical_address', 'emergency_contact_email',
        'benefits', 'notes', 'documents', 'other_doc_name', 'project_id'
    ];

    $update_fields = [];
    $update_params = [];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $update_fields[] = "$field = ?";
            $update_params[] = $_POST[$field];
        }
    }

    // reporting_to_id can legitimately be set to NULL (clear manager), which
    // isset() would skip above — bind it explicitly when submitted.
    if ($reporting_to_id_present) {
        $update_fields[] = "reporting_to_id = ?";
        $update_params[] = $new_reporting_to_id;
    }

    if (empty($update_fields)) {
        throw new Exception("No fields to update");
    }

    $update_fields[] = "updated_by = ?";
    $update_params[] = $_SESSION['user_id'];
    $update_params[] = $employee_id; // For WHERE clause

    $sql = "UPDATE employees SET " . implode(', ', $update_fields) . " WHERE employee_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($update_params);

    logActivity($pdo, $_SESSION['user_id'], 'Edit employee', "User edited employee: {$_POST['first_name']} {$_POST['last_name']} (ID $employee_id)");

    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], 'update', [
        'activity_type' => 'update',
        'entity_type' => 'employee',
        'entity_id' => $employee_id,
        'description' => "Updated employee #$employee_id ({$_POST['first_name']} {$_POST['last_name']})",
        'old_values' => $old_values,
        'new_values' => $_POST
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Employee updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
