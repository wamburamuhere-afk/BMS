<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/actor_account.php';
global $pdo;

// Check if user is logged in
if (!isAuthenticated()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check permission dynamically (Using suppliers permission as blueprint)
if (!canCreate('suppliers')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to add sub-contractors']);
    exit();
}

// Get POST data
$supplier_name = trim($_POST['supplier_name'] ?? '');
$company_name = trim($_POST['company_name'] ?? '');
$acronym = trim($_POST['acronym'] ?? '');
$supplier_type = trim($_POST['supplier_type'] ?? '');
$contact_person = trim($_POST['contact_person'] ?? '');
$contact_title = trim($_POST['contact_title'] ?? '');
$email = trim($_POST['email'] ?? '');
$company_email = trim($_POST['company_email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$fax = trim($_POST['fax'] ?? '');
$website = trim($_POST['website'] ?? '');
$address = trim($_POST['address'] ?? '');
$postal_address = trim($_POST['postal_address'] ?? '');
$council = trim($_POST['council'] ?? '');
$ward = trim($_POST['ward'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
$country = trim($_POST['country'] ?? 'Tanzania');
$postal_code = trim($_POST['postal_code'] ?? '');
$tax_id = trim($_POST['tax_id'] ?? '');
$vat_number = trim($_POST['vat_number'] ?? '');
$default_wht_rate_id = !empty($_POST['default_wht_rate_id']) ? (int)$_POST['default_wht_rate_id'] : null;
$payment_terms = trim($_POST['payment_terms'] ?? '');
$currency = trim($_POST['currency'] ?? 'TZS');
$bank_name = trim($_POST['bank_name'] ?? '');
$bank_account = trim($_POST['bank_account'] ?? '');
$bank_address = trim($_POST['bank_address'] ?? '');
$category_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
$project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
$description = trim($_POST['description'] ?? '');
$year = trim($_POST['year'] ?? date('Y'));
$status = $_POST['status'] ?? 'active';
$credit_limit = trim($_POST['credit_limit'] ?? '0.00');

// Handle "Other" custom values
if (empty($year) || $year === 'other') {
    $year = trim($_POST['year_other'] ?? date('Y'));
}
if (empty($payment_terms) || $payment_terms === 'other') {
    $payment_terms = trim($_POST['payment_terms_other'] ?? '');
}
if (empty($currency) || $currency === 'other') {
    $currency = trim($_POST['currency_other'] ?? 'TZS');
}

// Validate required fields
if (empty($supplier_name)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Sub-Contractor name is required']);
    exit();
}

// Phase E — project-scope gate
if (!empty($project_id) && function_exists('userCan') && !userCan('project', (int)$project_id)) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
    exit();
}

// Check if sub-contractor already exists (by name)
$check_query = "SELECT supplier_id, supplier_name, company_name FROM sub_contractors WHERE supplier_name = ? AND status != 'deleted'";
$check_params = [$supplier_name];

// Also check company name if provided
if (!empty($company_name)) {
    $check_query = "SELECT supplier_id, supplier_name, company_name FROM sub_contractors WHERE (supplier_name = ? OR company_name = ?) AND status != 'deleted'";
    $check_params = [$supplier_name, $company_name];
}

$check_stmt = $pdo->prepare($check_query);
$check_stmt->execute($check_params);
$existing = $check_stmt->fetch();

if ($existing) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "Sub-Contractor already exists with this name or company name"]);
    exit();
}

// Generate sub-contractor code
$supplier_code = 'SBC' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT) . date('ym');

// Insert new sub-contractor
$insert_stmt = $pdo->prepare("
    INSERT INTO sub_contractors (
        supplier_name, company_name, acronym, supplier_type, year, contact_person, contact_title,
        email, company_email, phone, mobile, fax, website, address, postal_address, council, ward,
        city, state, country, postal_code, tax_id, vat_number, default_wht_rate_id, payment_terms,
        currency, bank_name, bank_account, bank_address, category_id,
        project_id, credit_limit, description, status, supplier_code, created_by, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

try {
    $pdo->beginTransaction();
    $insert_stmt->execute([
        $supplier_name, $company_name, $acronym, $supplier_type, $year, $contact_person, $contact_title,
        $email, $company_email, $phone, $mobile, $fax, $website, $address, $postal_address, $council, $ward,
        $city, $state, $country, $postal_code, $tax_id, $vat_number, $default_wht_rate_id, $payment_terms,
        $currency, $bank_name, $bank_account, $bank_address, $category_id,
        $project_id, $credit_limit, $description, $status, $supplier_code, $_SESSION['user_id']
    ]);

    $supplier_id = $pdo->lastInsertId();
    ensureActorLedgerAccount($pdo, 'sub_contractor', (int) $supplier_id, $supplier_name);
    $pdo->commit();

    // Handle Logo Upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/parties/sub_contractors/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . $supplier_id . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
            $logo_path = 'uploads/parties/sub_contractors/' . $filename;
            $pdo->prepare("UPDATE sub_contractors SET logo_path = ? WHERE supplier_id = ?")->execute([$logo_path, $supplier_id]);
        }
    }
    
    // Log the action
    logActivity($pdo, $_SESSION['user_id'], "Created sub-contractor: $supplier_name");
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Sub-Contractor added successfully',
        'sub_contractor_id' => $supplier_id,
        'sub_contractor_code' => $supplier_code
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
