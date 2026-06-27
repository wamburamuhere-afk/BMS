<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/form_lookups.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('suppliers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
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
if ($supplier_type === 'other') {
    $supplier_type = trim($_POST['supplier_type_other'] ?? '');
}

// Self-growing dropdowns: persist any newly-typed value so it appears next time.
$lk_uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
upsertFormLookup($pdo, 'supplier_type', $supplier_type, $lk_uid);
upsertFormLookup($pdo, 'payment_terms', $payment_terms, $lk_uid);
upsertFormLookup($pdo, 'currency',      $currency,      $lk_uid);

// Category "Other" – create new category on the fly if needed
if ($category_id === 'other' || ($category_id === null && !empty(trim($_POST['category_other'] ?? '')))) {
    $cat_other = trim($_POST['category_other'] ?? '');
    if (!empty($cat_other)) {
        $cat_check = $pdo->prepare("SELECT category_id FROM supplier_categories WHERE LOWER(category_name) = LOWER(?) AND status = 'active'");
        $cat_check->execute([$cat_other]);
        $existing_cat = $cat_check->fetch(PDO::FETCH_ASSOC);
        if ($existing_cat) {
            $category_id = $existing_cat['category_id'];
        } else {
            $cat_insert = $pdo->prepare("INSERT INTO supplier_categories (category_name, status, created_at) VALUES (?, 'active', NOW())");
            $cat_insert->execute([$cat_other]);
            $category_id = $pdo->lastInsertId();
        }
    } else {
        $category_id = null;
    }
}

// Handle unlinking (empty string means set to NULL)
if ($project_id === '') $project_id = null;

// Validate required fields
if (empty($supplier_id) || empty($supplier_name)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier ID and name are required']);
    exit();
}

// Phase E — project-scope gate on existing supplier record
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('suppliers', 'supplier_id', (int)$supplier_id);
}
// Also gate the target project if being reassigned
if (!empty($project_id) && function_exists('userCan') && !userCan('project', (int)$project_id)) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: target project not in your scope.']);
    exit();
}

// Get existing supplier
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
$stmt->execute([$supplier_id]);
$existing_supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing_supplier) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit();
}

// Validate email if provided
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit();
}

// Validate URL if provided
if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid website URL']);
    exit();
}

// Check for duplicate supplier name (excluding current supplier)
$check_stmt = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id != ? AND supplier_name = ? AND status != 'deleted'");
$check_stmt->execute([$supplier_id, $supplier_name]);
if ($check_stmt->fetch()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier name already exists']);
    exit();
}

// Validate category if provided
if (!empty($category_id)) {
    $category_stmt = $pdo->prepare("SELECT category_id FROM supplier_categories WHERE category_id = ? AND status = 'active'");
    $category_stmt->execute([$category_id]);
    if (!$category_stmt->fetch()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid category']);
        exit();
    }
}

// Clean phone numbers
if (!function_exists('clean_phone')) {
    function clean_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 9) {
            return '255' . $phone;
        }
        return $phone;
    }
}

$phone = clean_phone($phone);
$mobile = clean_phone($mobile);

// Handle Logo Upload
$logo_path = null;
$logo_updated = false;
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = __DIR__ . '/../uploads/parties/suppliers/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    $filename = 'logo_' . $supplier_id . '_' . time() . '.' . $ext;
    $target_path = $upload_dir . $filename;
    
    if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
        $logo_path = 'uploads/parties/suppliers/' . $filename;
        $logo_updated = true;
        registerFileInLibrary($pdo, $logo_path, $_FILES['logo']['name'], $_FILES['logo']['size'], 'Supplier Logo: ' . $supplier_name, 'supplier,logo', $_SESSION['user_id']);
    }
} elseif (isset($_POST['remove_logo']) && $_POST['remove_logo'] == '1') {
    $logo_path = null;
    $logo_updated = true;
}

// Update supplier
$sql = "UPDATE suppliers SET
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
    
    logActivity($pdo, $_SESSION['user_id'], 'Edit supplier', "User edited supplier: $supplier_name (ID $supplier_id)");
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Supplier updated successfully'
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}