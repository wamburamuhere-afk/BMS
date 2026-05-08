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
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to import suppliers']);
    exit();
}

// Check if file was uploaded
if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Please select a file to upload']);
    exit();
}

// Validate file type
$file_type = $_FILES['import_file']['type'];
$allowed_types = ['text/csv', 'application/vnd.ms-excel', 'text/plain'];
if (!in_array($file_type, $allowed_types)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed']);
    exit();
}

// Validate file size (max 5MB)
$max_size = 5 * 1024 * 1024; // 5MB in bytes
if ($_FILES['import_file']['size'] > $max_size) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
    exit();
}

// Get import action
$import_action = $_POST['import_action'] ?? 'add_new';
$skip_errors = isset($_POST['skip_errors']) && $_POST['skip_errors'] == 'on';

// Process CSV file
$file_path = $_FILES['import_file']['tmp_name'];
$handle = fopen($file_path, 'r');

if (!$handle) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unable to open file']);
    exit();
}

// Get header row
$headers = fgetcsv($handle);
if (!$headers) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Empty CSV file']);
    fclose($handle);
    exit();
}

// Expected headers
$expected_headers = [
    'supplier_name', 'company_name', 'contact_person', 'contact_title',
    'email', 'phone', 'mobile', 'fax', 'website', 'address', 'city',
    'state', 'country', 'postal_code', 'tax_id', 'vat_number',
    'payment_terms', 'currency', 'bank_name', 'bank_account',
    'bank_address', 'description', 'status'
];

// Validate headers
$missing_headers = array_diff($expected_headers, $headers);
if (!empty($missing_headers)) {
    fclose($handle);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid CSV format. Missing headers: ' . implode(', ', $missing_headers)]);
    exit();
}

// Map headers to indexes
$header_indexes = array_flip($headers);

// Process rows
$results = [
    'total_rows' => 0,
    'successful' => 0,
    'failed' => 0,
    'skipped' => 0,
    'errors' => []
];

// Prepare insert statement
$insert_stmt = $pdo->prepare("
    INSERT INTO suppliers (
        supplier_name, company_name, contact_person, contact_title,
        email, phone, mobile, fax, website, address, city, state,
        country, postal_code, tax_id, vat_number, payment_terms,
        currency, bank_name, bank_account, bank_address, description,
        status, supplier_code, created_by, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

// Prepare update statement
$update_stmt = $pdo->prepare("
    UPDATE suppliers SET
        company_name = ?, contact_person = ?, contact_title = ?,
        email = ?, phone = ?, mobile = ?, fax = ?, website = ?, 
        address = ?, city = ?, state = ?, country = ?, postal_code = ?, 
        tax_id = ?, vat_number = ?, payment_terms = ?, currency = ?, 
        bank_name = ?, bank_account = ?, bank_address = ?, description = ?,
        status = ?, updated_by = ?, updated_at = NOW()
    WHERE supplier_name = ? AND status != 'deleted'
");

// Helper function to clean phone numbers
if (!function_exists('clean_phone')) {
    function clean_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 9) {
            return '255' . $phone;
        }
        return $phone;
    }
}

$row_number = 0;
while (($row = fgetcsv($handle)) !== false) {
    $row_number++;
    $results['total_rows']++;
    
    // Skip empty rows
    if (empty(array_filter($row))) {
        $results['skipped']++;
        continue;
    }
    
    // Get values from row
    $supplier_name = trim($row[$header_indexes['supplier_name']] ?? '');
    $company_name = trim($row[$header_indexes['company_name']] ?? '');
    $contact_person = trim($row[$header_indexes['contact_person']] ?? '');
    $contact_title = trim($row[$header_indexes['contact_title']] ?? '');
    $email = trim($row[$header_indexes['email']] ?? '');
    $phone = clean_phone(trim($row[$header_indexes['phone']] ?? ''));
    $mobile = clean_phone(trim($row[$header_indexes['mobile']] ?? ''));
    $fax = trim($row[$header_indexes['fax']] ?? '');
    $website = trim($row[$header_indexes['website']] ?? '');
    $address = trim($row[$header_indexes['address']] ?? '');
    $city = trim($row[$header_indexes['city']] ?? '');
    $state = trim($row[$header_indexes['state']] ?? '');
    $country = trim($row[$header_indexes['country']] ?? 'Tanzania');
    $postal_code = trim($row[$header_indexes['postal_code']] ?? '');
    $tax_id = trim($row[$header_indexes['tax_id']] ?? '');
    $vat_number = trim($row[$header_indexes['vat_number']] ?? '');
    $payment_terms = trim($row[$header_indexes['payment_terms']] ?? '');
    $currency = trim($row[$header_indexes['currency']] ?? 'TZS');
    $bank_name = trim($row[$header_indexes['bank_name']] ?? '');
    $bank_account = trim($row[$header_indexes['bank_account']] ?? '');
    $bank_address = trim($row[$header_indexes['bank_address']] ?? '');
    $description = trim($row[$header_indexes['description']] ?? '');
    $status = trim($row[$header_indexes['status']] ?? 'active');
    
    // Validate required field
    if (empty($supplier_name)) {
        $error = "Row $row_number: Supplier name is required";
        $results['errors'][] = $error;
        
        if ($skip_errors) {
            $results['skipped']++;
            continue;
        } else {
            break;
        }
    }
    
    // Validate email if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Row $row_number: Invalid email address: $email";
        $results['errors'][] = $error;
        
        if ($skip_errors) {
            $results['skipped']++;
            continue;
        } else {
            break;
        }
    }
    
    // Generate supplier code
    $supplier_code = 'SUP' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT) . date('ym');
    
    try {
        // Check if supplier exists
        $check_stmt = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name = ? AND status != 'deleted'");
        $check_stmt->execute([$supplier_name]);
        $existing_supplier = $check_stmt->fetch();
        
        if ($existing_supplier && $import_action !== 'add_new') {
            // Update existing supplier
            if ($import_action === 'update_existing' || $import_action === 'add_update') {
                $update_stmt->execute([
                    $company_name, $contact_person, $contact_title,
                    $email, $phone, $mobile, $fax, $website, $address,
                    $city, $state, $country, $postal_code, $tax_id,
                    $vat_number, $payment_terms, $currency, $bank_name,
                    $bank_account, $bank_address, $description, $status,
                    $_SESSION['user_id'], $supplier_name
                ]);
                $results['successful']++;
            } else {
                $results['skipped']++;
            }
        } else {
            // Insert new supplier
            if ($import_action === 'add_new' || $import_action === 'add_update') {
                $insert_stmt->execute([
                    $supplier_name, $company_name, $contact_person, $contact_title,
                    $email, $phone, $mobile, $fax, $website, $address, $city, $state,
                    $country, $postal_code, $tax_id, $vat_number, $payment_terms,
                    $currency, $bank_name, $bank_account, $bank_address, $description,
                    $status, $supplier_code, $_SESSION['user_id']
                ]);
                $results['successful']++;
            } else {
                $results['skipped']++;
            }
        }
        
    } catch (PDOException $e) {
        $error = "Row $row_number: Database error - " . $e->getMessage();
        $results['errors'][] = $error;
        $results['failed']++;
        
        if (!$skip_errors) {
            break;
        }
    }
}

fclose($handle);

// Log the import action
$log_stmt = $pdo->prepare("
    INSERT INTO activity_logs (user_id, action, ip_address, user_agent, description) 
    VALUES (?, 'import_suppliers', ?, ?, ?)
");
$log_details = "Imported suppliers: " . $results['successful'] . " successful, " . 
               $results['failed'] . " failed, " . $results['skipped'] . " skipped";
$log_stmt->execute([
    $_SESSION['user_id'],
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    $log_details
]);

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Import completed successfully',
    'results' => $results
]);