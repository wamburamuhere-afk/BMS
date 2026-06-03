<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php'; // For permissions

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permission
if (!canCreate('customers')) {
     echo json_encode(['success' => false, 'message' => 'Permission denied']);
     exit;
}

try {
    // Validate required fields
    if (empty($_POST['customer_name'])) {
        throw new Exception('Customer name is required');
    }

    // Phase E — project-scope gate: can only add customer to a project in scope
    if (!empty($_POST['project_id']) && function_exists('userCan') && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
        exit();
    }

    // Generate Customer Code (if not provided or auto-generated)
    $stmt = $pdo->query("SELECT MAX(customer_id) FROM customers");
    $nextId = $stmt->fetchColumn() + 1;
    $customerCode = 'CUST-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

    // Prepare data
    $data = [
        'customer_code' => $customerCode,
        'customer_name' => $_POST['customer_name'],
        'company_name' => $_POST['company_name'] ?? null,
        'acronym' => $_POST['acronym'] ?? null,
        'category_id' => !empty($_POST['category_id']) ? $_POST['category_id'] : 1, // Default or required
        'customer_type' => $_POST['customer_type'] ?? 'business',
        'status' => $_POST['status'] ?? 'active',
        'credit_limit' => !empty($_POST['credit_limit']) ? $_POST['credit_limit'] : 0,
        'notes' => $_POST['description'] ?? null, // Map description to notes
        
        'contact_person' => $_POST['contact_person'] ?? null,
        'contact_title' => $_POST['contact_title'] ?? null,
        'email' => $_POST['email'] ?? null,
        'company_email' => $_POST['company_email'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'mobile' => $_POST['mobile'] ?? null,
        'fax' => $_POST['fax'] ?? null,
        'website' => $_POST['website'] ?? null,
        
        'address' => $_POST['address'] ?? null,
        'city'    => $_POST['city'] ?? null,
        'state'   => $_POST['state'] ?? null,
        'country' => $_POST['country'] ?? 'Tanzania',
        'council' => $_POST['council'] ?? null,
        'ward'    => $_POST['ward'] ?? null,
        'postal_code'    => $_POST['postal_code'] ?? null,
        'postal_address' => $_POST['postal_address'] ?? null,
        
        'tax_id' => $_POST['tax_id'] ?? null,
        'vat_number' => $_POST['vat_number'] ?? null,
        'default_wht_rate_id' => !empty($_POST['default_wht_rate_id']) ? (int)$_POST['default_wht_rate_id'] : null,
        'payment_terms' => $_POST['payment_terms'] ?? null,
        'currency' => $_POST['currency'] ?? 'TZS',
        'bank_name' => $_POST['bank_name'] ?? null,
        'bank_account' => $_POST['bank_account'] ?? null,
        'bank_address' => $_POST['bank_address'] ?? null,
        
        'year' => $_POST['year'] ?? date('Y'),
        'project_id' => !empty($_POST['project_id']) ? $_POST['project_id'] : null,

        'created_by' => $_SESSION['user_id']
    ];

    $fieldNames = array_map(function($f) { return "`$f`"; }, array_keys($data));
    $fields = implode(', ', $fieldNames);
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    
    $sql = "INSERT INTO customers ($fields) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    
    $customerId = $pdo->lastInsertId();

    // Handle Logo Upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/parties/customers/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . $customerId . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
            $logo_path = 'uploads/parties/customers/' . $filename;
            $pdo->prepare("UPDATE customers SET logo_path = ? WHERE customer_id = ?")->execute([$logo_path, $customerId]);
            registerFileInLibrary($pdo, $logo_path, $_FILES['logo']['name'], $_FILES['logo']['size'], 'Customer Logo: ' . $data['customer_name'], 'customer,logo', $_SESSION['user_id']);
        }
    }

    // Log activity
    logActivity($pdo, $_SESSION['user_id'], "Created customer: {$data['customer_name']} ({$customerCode})");

    echo json_encode([
        'success' => true, 
        'message' => 'Customer added successfully',
        'customer_id' => $customerId
    ]);

} catch (Exception $e) {
    error_log("Add Customer Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
