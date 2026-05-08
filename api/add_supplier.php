<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
global $pdo;

// Check if user is logged in
if (!isAuthenticated()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check permission dynamically
if (!canCreate('suppliers')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to add suppliers']);
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
// Category "Other" – create new category on the fly if needed
if ($category_id === 'other' || empty($category_id)) {
    $cat_other = trim($_POST['category_other'] ?? '');
    if (!empty($cat_other)) {
        // Check if it already exists
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

// Validate required fields
if (empty($supplier_name)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier name is required']);
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

// Check if supplier already exists (by name)
$check_query = "SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE supplier_name = ? AND status != 'deleted'";
$check_params = [$supplier_name];

// Also check company name if provided
if (!empty($company_name)) {
    $check_query = "SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE (supplier_name = ? OR company_name = ?) AND status != 'deleted'";
    $check_params = [$supplier_name, $company_name];
}

$check_stmt = $pdo->prepare($check_query);
$check_stmt->execute($check_params);
$existing_supplier = $check_stmt->fetch();

if ($existing_supplier) {
    $matched_field = '';
    // Use case-insensitive comparison to identify what matched
    if (strtolower($existing_supplier['supplier_name']) === strtolower($supplier_name)) {
        $matched_field = 'name';
    } elseif (!empty($company_name) && strtolower($existing_supplier['company_name']) === strtolower($company_name)) {
        $matched_field = 'company name';
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => "Supplier already exists with this $matched_field",
        'debug_info' => [
            'matched_field' => $matched_field,
            'existing_id' => $existing_supplier['supplier_id'],
            'existing_name' => $existing_supplier['supplier_name'],
            'existing_company' => $existing_supplier['company_name']
        ]
    ]);
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

// Validate project_id if provided
if (!empty($project_id)) {
    $project_stmt = $pdo->prepare("SELECT project_id FROM projects WHERE project_id = ?");
    $project_stmt->execute([$project_id]);
    if (!$project_stmt->fetch()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid project ID']);
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

// Generate supplier code
$supplier_code = 'SUP' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT) . date('ym');

// Insert new supplier
$insert_stmt = $pdo->prepare("
    INSERT INTO suppliers (
        supplier_name, company_name, acronym, supplier_type, year, contact_person, contact_title,
        email, company_email, phone, mobile, fax, website, address, postal_address, council, ward,
        city, state, country, postal_code, tax_id, vat_number, payment_terms,
        currency, bank_name, bank_account, bank_address, category_id,
        project_id, credit_limit, description, status, supplier_code, created_by, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

try {
    $insert_stmt->execute([
        $supplier_name, $company_name, $acronym, $supplier_type, $year, $contact_person, $contact_title,
        $email, $company_email, $phone, $mobile, $fax, $website, $address, $postal_address, $council, $ward,
        $city, $state, $country, $postal_code, $tax_id, $vat_number, $payment_terms,
        $currency, $bank_name, $bank_account, $bank_address, $category_id,
        $project_id, $credit_limit, $description, $status, $supplier_code, $_SESSION['user_id']
    ]);
    
    $supplier_id = $pdo->lastInsertId();
    
    // Handle Logo Upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/suppliers/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . $supplier_id . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
            $logo_path = 'uploads/suppliers/' . $filename;
            $pdo->prepare("UPDATE suppliers SET logo_path = ? WHERE supplier_id = ?")->execute([$logo_path, $supplier_id]);
            registerFileInLibrary($pdo, $logo_path, $_FILES['logo']['name'], $_FILES['logo']['size'], 'Supplier Logo: ' . $supplier_name, 'supplier,logo', $_SESSION['user_id']);
        }
    }
    
    // Log the action using standard helper
    logActivity($pdo, $_SESSION['user_id'], "Created supplier: $supplier_name" . ($company_name ? " ($company_name)" : ""));
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Supplier added successfully',
        'supplier_id' => $supplier_id,
        'supplier_code' => $supplier_code
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}