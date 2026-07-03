<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/actor_account.php';
require_once __DIR__ . '/../core/form_lookups.php';
require_once __DIR__ . '/../core/code_generator.php';

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

    // ── Self-growing dropdowns: resolve "Other" → typed value, then persist so it
    //    reappears next time. customer_type owns its list; payment_terms + currency
    //    are shared. Mutates $_POST so the $data array below picks up resolved values.
    $customer_type = trim($_POST['customer_type'] ?? 'business');
    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $currency      = trim($_POST['currency'] ?? 'TZS');
    $year          = trim($_POST['year'] ?? date('Y'));
    if ($customer_type === 'other') $customer_type = trim($_POST['customer_type_other'] ?? '');
    if ($payment_terms === 'other') $payment_terms = trim($_POST['payment_terms_other'] ?? '');
    if ($currency === 'other')      $currency      = trim($_POST['currency_other'] ?? 'TZS');
    if ($year === 'other')          $year          = trim($_POST['year_other'] ?? date('Y'));

    // Category "Other" — create the customer_categories row on the fly if typed.
    $category_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
    if ($category_id === 'other' || (empty($category_id) && !empty(trim($_POST['category_other'] ?? '')))) {
        $cat_other = trim($_POST['category_other'] ?? '');
        if ($cat_other !== '') {
            $cc = $pdo->prepare("SELECT category_id FROM customer_categories WHERE LOWER(category_name)=LOWER(?) AND status='active'");
            $cc->execute([$cat_other]);
            $cid = $cc->fetchColumn();
            if ($cid) { $category_id = $cid; }
            else {
                $pdo->prepare("INSERT INTO customer_categories (category_name, status, created_at) VALUES (?, 'active', NOW())")->execute([$cat_other]);
                $category_id = $pdo->lastInsertId();
            }
        } else { $category_id = null; }
    }
    $_POST['customer_type'] = $customer_type;
    $_POST['payment_terms'] = $payment_terms;
    $_POST['currency']      = $currency;
    $_POST['year']          = $year;
    $_POST['category_id']   = $category_id;

    $lk_uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    upsertFormLookup($pdo, 'customer_type', $customer_type, $lk_uid);
    upsertFormLookup($pdo, 'payment_terms', $payment_terms, $lk_uid);
    upsertFormLookup($pdo, 'currency',      $currency,      $lk_uid);

    // Company-prefixed sequential Customer Code, e.g. BFS-CUST-0001
    // (gap-free via core/code_generator.php).
    $customerCode = nextCode($pdo, 'CUST');

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
        'ward'    => $_POST['ward'] ?? null,
        'village' => $_POST['village'] ?? null,
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
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    $customerId = $pdo->lastInsertId();

    ensureActorLedgerAccount($pdo, 'customer', (int) $customerId, $data['customer_name']);
    $pdo->commit();

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
    logActivity($pdo, $_SESSION['user_id'], 'Create customer', "User created a new customer: {$data['customer_name']} ($customerCode)");

    echo json_encode([
        'success' => true, 
        'message' => 'Customer added successfully',
        'customer_id' => $customerId
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Add Customer Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
