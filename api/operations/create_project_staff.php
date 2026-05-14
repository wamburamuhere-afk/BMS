<?php
// api/operations/create_project_staff.php
// Creates an employee via 5-step wizard and assigns them directly to a project — no document upload required.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    $required = ['first_name', 'last_name', 'email', 'phone', 'employee_number', 'department_id', 'designation_id', 'project_id'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field '$field' is required");
        }
    }

    $project_id    = intval($_POST['project_id']);
    $employee_code = !empty($_POST['employee_code']) ? trim($_POST['employee_code']) : trim($_POST['employee_number']);

    // Verify project exists
    $chk = $pdo->prepare("SELECT project_id FROM projects WHERE project_id = ?");
    $chk->execute([$project_id]);
    if (!$chk->fetch()) {
        throw new Exception("Project not found");
    }

    // Check uniqueness
    $dup = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_code = ? OR employee_number = ? OR email = ?");
    $dup->execute([$employee_code, trim($_POST['employee_number']), trim($_POST['email'])]);
    if ($dup->fetchColumn() > 0) {
        throw new Exception("Employee number or email already exists. Please use unique values.");
    }

    // Benefits checkboxes
    $benefits = !empty($_POST['benefits']) ? json_encode($_POST['benefits']) : null;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_code, employee_number, first_name, middle_name, last_name,
            gender, date_of_birth, marital_status,
            national_id, passport_number,
            email, phone, alternate_phone,
            physical_address, postal_address, city, country,
            hire_date, probation_end_date, contract_end_date,
            department_id, designation_id, employment_type_id, employment_status,
            reporting_to, work_location,
            basic_salary, hourly_rate, currency, payment_frequency, payment_method,
            bank_name, bank_account, bank_branch, mobile_money,
            tax_id, social_security_number,
            emergency_contact, emergency_contact_relationship, emergency_contact_phone,
            emergency_contact_email, emergency_contact_postal_address, emergency_contact_physical_address,
            benefits, notes, additional_notes, project_id, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?, NOW()
        )
    ");

    $stmt->execute([
        $employee_code,
        trim($_POST['employee_number']),
        trim($_POST['first_name']),
        trim($_POST['middle_name'] ?? ''),
        trim($_POST['last_name']),
        $_POST['gender'] ?? null,
        !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
        $_POST['marital_status'] ?? null,
        trim($_POST['national_id'] ?? ''),
        trim($_POST['passport_number'] ?? ''),
        trim($_POST['email']),
        trim($_POST['phone']),
        trim($_POST['alternate_phone'] ?? ''),
        trim($_POST['physical_address'] ?? ''),
        trim($_POST['postal_address'] ?? ''),
        trim($_POST['city'] ?? ''),
        trim($_POST['country'] ?? 'Tanzania'),
        !empty($_POST['hire_date']) ? $_POST['hire_date'] : date('Y-m-d'),
        !empty($_POST['probation_end_date']) ? $_POST['probation_end_date'] : null,
        !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null,
        intval($_POST['department_id']),
        intval($_POST['designation_id']),
        !empty($_POST['employment_type_id']) ? intval($_POST['employment_type_id']) : null,
        $_POST['employment_status'] ?? 'probation',
        trim($_POST['reporting_to'] ?? ''),
        trim($_POST['work_location'] ?? ''),
        floatval($_POST['basic_salary'] ?? 0),
        floatval($_POST['hourly_rate'] ?? 0),
        $_POST['currency'] ?? 'TZS',
        $_POST['payment_frequency'] ?? 'monthly',
        $_POST['payment_method'] ?? 'bank',
        trim($_POST['bank_name'] ?? ''),
        trim($_POST['bank_account'] ?? ''),
        trim($_POST['bank_branch'] ?? ''),
        trim($_POST['mobile_money'] ?? ''),
        trim($_POST['tax_id'] ?? ''),
        trim($_POST['social_security_number'] ?? ''),
        trim($_POST['emergency_contact'] ?? ''),
        trim($_POST['emergency_contact_relationship'] ?? ''),
        trim($_POST['emergency_contact_phone'] ?? ''),
        trim($_POST['emergency_contact_email'] ?? ''),
        trim($_POST['emergency_contact_postal_address'] ?? ''),
        trim($_POST['emergency_contact_physical_address'] ?? ''),
        $benefits,
        trim($_POST['notes'] ?? ''),
        trim($_POST['additional_notes'] ?? ''),
        $project_id,
        $_SESSION['user_id']
    ]);

    $employee_id = $pdo->lastInsertId();

    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type'   => 'employee',
        'entity_id'     => $employee_id,
        'description'   => "Created project staff: {$_POST['first_name']} {$_POST['last_name']} ({$_POST['employee_number']}) for project #$project_id",
        'new_values'    => array_diff_key($_POST, array_flip(['password']))
    ]);

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'message'     => 'Staff member created and assigned to project successfully.',
        'employee_id' => $employee_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
