<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check permissions - Using suppliers permission as blueprint
if (!isAdmin() && !canEdit('suppliers')) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Get POST data
$supplier_id = $_POST['supplier_id'] ?? '';
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
$description = trim($_POST['description'] ?? '');
$year = trim($_POST['year'] ?? date('Y'));
$status = $_POST['status'] ?? 'active';
$project_id = $_POST['project_id'] ?? null;
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

// Handle unlinking (empty string means set to NULL)
if ($project_id === '') $project_id = null;

// Validate required fields
if (empty($supplier_id) || empty($supplier_name)) {
    echo json_encode(['success' => false, 'message' => 'Sub-Contractor ID and name are required']);
    exit();
}

// Phase E — project-scope gate on existing record and new project if reassigned
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('sub_contractors', 'supplier_id', (int)$supplier_id);
}
if (!empty($project_id) && function_exists('userCan') && !userCan('project', (int)$project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: target project not in your scope.']);
    exit();
}

// Check for duplicate sub-contractor name (excluding current)
$check_stmt = $pdo->prepare("SELECT supplier_id FROM sub_contractors WHERE supplier_id != ? AND supplier_name = ? AND status != 'deleted'");
$check_stmt->execute([$supplier_id, $supplier_name]);
if ($check_stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Sub-Contractor name already exists']);
    exit();
}

// Handle Logo Upload
$logo_path = null;
$logo_updated = false;
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = __DIR__ . '/../uploads/parties/sub_contractors/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    $filename = 'logo_' . $supplier_id . '_' . time() . '.' . $ext;
    $target_path = $upload_dir . $filename;
    
    if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
        $logo_path = 'uploads/parties/sub_contractors/' . $filename;
        $logo_updated = true;
    }
} elseif (isset($_POST['remove_logo']) && $_POST['remove_logo'] == '1') {
    $logo_path = null;
    $logo_updated = true;
}

// Update sub-contractor
$sql = "UPDATE sub_contractors SET
        supplier_name = ?,
        company_name = ?,
        acronym = ?,";

if ($logo_updated) {
    $sql .= " logo_path = ?,";
}

$sql .= " supplier_type = ?,
        year = ?,
        contact_person = ?,
        contact_title = ?,
        email = ?,
        company_email = ?,
        phone = ?,
        mobile = ?,
        fax = ?,
        website = ?,
        address = ?,
        postal_address = ?,
        council = ?,
        ward = ?,
        city = ?,
        state = ?,
        country = ?,
        postal_code = ?,
        tax_id = ?,
        vat_number = ?,
        default_wht_rate_id = ?,
        payment_terms = ?,
        currency = ?,
        bank_name = ?,
        bank_account = ?,
        bank_address = ?,
        category_id = ?,
        description = ?,
        status = ?,
        project_id = ?,
        credit_limit = ?,
        updated_by = ?,
        updated_at = NOW()
    WHERE supplier_id = ?";

$update_stmt = $pdo->prepare($sql);

$params = [
    $supplier_name, $company_name, $acronym,
];

if ($logo_updated) {
    $params[] = $logo_path;
}

$params = array_merge($params, [
    $supplier_type, $year, $contact_person, $contact_title,
    $email, $company_email, $phone, $mobile, $fax, $website, $address, $postal_address, $council, $ward,
    $city, $state, $country, $postal_code, $tax_id, $vat_number, $default_wht_rate_id, $payment_terms,
    $currency, $bank_name, $bank_account, $bank_address, $category_id,
    $description, $status, $project_id, $credit_limit, $_SESSION['user_id'], $supplier_id
]);

try {
    $update_stmt->execute($params);
    
    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], "Updated sub-contractor: $supplier_name");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Sub-Contractor updated successfully'
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
