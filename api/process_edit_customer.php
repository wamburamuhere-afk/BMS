<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/form_lookups.php';
// Note: roots.php already includes config.php, helpers.php and permissions.php

header('Content-Type: application/json');

// Detect if POST was silently wiped by PHP (e.g. upload exceeds post_max_size)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $maxPost = ini_get('post_max_size');
    echo json_encode(['success' => false, 'message' => "Upload failed: POST data is empty. Ensure total file size is under PHP's post_max_size ($maxPost)."]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permission
if (!canEdit('customers')) {
     echo json_encode(['success' => false, 'message' => 'Permission denied']);
     exit;
}

try {
    $customerId = $_POST['customer_id'] ?? null;
    if (!$customerId) {
        throw new Exception('Customer ID is required');
    }

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('customers', 'customer_id', (int)$customerId);
    }
    // Also gate the target project if being reassigned
    if (!empty($_POST['project_id']) && function_exists('userCan') && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: target project not in your scope.']);
        exit();
    }

    // Validate required fields
    if (empty($_POST['customer_name'])) {
        throw new Exception('Customer name is required');
    }

    // ── Self-growing dropdowns: resolve "Other" → typed value, then persist. ──
    $customer_type = trim($_POST['customer_type'] ?? 'individual');
    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $currency      = trim($_POST['currency'] ?? 'TZS');
    $year          = trim($_POST['year'] ?? date('Y'));
    if ($customer_type === 'other') $customer_type = trim($_POST['customer_type_other'] ?? '');
    if ($payment_terms === 'other') $payment_terms = trim($_POST['payment_terms_other'] ?? '');
    if ($currency === 'other')      $currency      = trim($_POST['currency_other'] ?? 'TZS');
    if ($year === 'other')          $year          = trim($_POST['year_other'] ?? date('Y'));

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

    // Prepare update data
    $data = [
        'customer_name' => $_POST['customer_name'],
        'company_name' => $_POST['company_name'] ?? null,
        'acronym' => $_POST['acronym'] ?? null,
        'registration_number' => $_POST['registration_number'] ?? null,
        'tin_number' => $_POST['tin_number'] ?? null,
        'vat_number' => $_POST['vat_number'] ?? null,
        'default_wht_rate_id' => !empty($_POST['default_wht_rate_id']) ? (int)$_POST['default_wht_rate_id'] : null,
        'website' => $_POST['website'] ?? null,
        'occupation_business' => $_POST['business_type'] ?? null,
        
        'category_id' => !empty($_POST['category_id']) ? $_POST['category_id'] : 1,
        'customer_type' => $_POST['customer_type'] ?? 'individual',
        'status' => $_POST['status'] ?? 'active',
        'credit_limit' => !empty($_POST['credit_limit']) ? $_POST['credit_limit'] : 0,
        'notes' => $_POST['description'] ?? null,
        
        'contact_person' => $_POST['contact_person'] ?? null,
        'contact_title' => $_POST['contact_title'] ?? null,
        'email' => $_POST['email'] ?? null,
        'company_email' => $_POST['company_email'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'mobile' => $_POST['mobile'] ?? null,
        'fax' => $_POST['fax'] ?? null,
        
        'address' => $_POST['address'] ?? null,
        'city'    => $_POST['city'] ?? null,
        'state'   => $_POST['state'] ?? null,
        'country' => $_POST['country'] ?? 'Tanzania',
        'council' => $_POST['council'] ?? null,
        'ward'    => $_POST['ward'] ?? null,
        'postal_code'    => $_POST['postal_code'] ?? null,
        'postal_address' => $_POST['postal_address'] ?? null,
        
        'tax_id' => $_POST['tax_id'] ?? null,
        'payment_terms' => $_POST['payment_terms'] ?? null,
        'currency' => $_POST['currency'] ?? 'TZS',
        'bank_name' => $_POST['bank_name'] ?? null,
        'bank_account' => $_POST['bank_account'] ?? null,
        'bank_address' => $_POST['bank_address'] ?? null,
        'year' => $_POST['year'] ?? date('Y'),
        'project_id' => !empty($_POST['project_id']) ? $_POST['project_id'] : null,
        
        'updated_by' => $_SESSION['user_id']
    ];

    // Preserve the WHT default if this form didn't send the field (e.g. the
    // standalone edit page) — only an explicit submission may change/clear it.
    if (!array_key_exists('default_wht_rate_id', $_POST)) {
        unset($data['default_wht_rate_id']);
    }

    // Handle File Uploads
    $upload_dir = __DIR__ . '/../uploads/parties/customers/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Handle Photo
    if (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'photo_' . $customerId . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['customer_photo']['tmp_name'], $target_path)) {
            $data['photo_path'] = 'uploads/parties/customers/' . $filename;
            registerFileInLibrary($pdo, $data['photo_path'], $_FILES['customer_photo']['name'], $_FILES['customer_photo']['size'], 'Customer Photo: ' . $data['customer_name'], 'customer,photo', $_SESSION['user_id']);
        }
    } elseif (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
        $data['photo_path'] = null;
    }

    // Handle Logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . $customerId . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
            $data['logo_path'] = 'uploads/parties/customers/' . $filename;
            registerFileInLibrary($pdo, $data['logo_path'], $_FILES['logo']['name'], $_FILES['logo']['size'], 'Customer Logo: ' . $data['customer_name'], 'customer,logo', $_SESSION['user_id']);
        }
    } elseif (isset($_POST['remove_logo']) && $_POST['remove_logo'] == '1') {
        $data['logo_path'] = null;
    }

    // Handle ID Attachment
    if (isset($_FILES['id_attachment']) && $_FILES['id_attachment']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['id_attachment']['name'], PATHINFO_EXTENSION);
        $filename = 'id_' . $customerId . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['id_attachment']['tmp_name'], $target_path)) {
            $data['id_attachment_path'] = 'uploads/parties/customers/' . $filename;
            registerFileInLibrary($pdo, $data['id_attachment_path'], $_FILES['id_attachment']['name'], $_FILES['id_attachment']['size'], 'Customer ID: ' . $data['customer_name'], 'customer,id,identity', $_SESSION['user_id']);
        }
    } elseif (isset($_POST['remove_id_attachment']) && $_POST['remove_id_attachment'] == '1') {
        $data['id_attachment_path'] = null;
    }

    // Handle Standard Business Attachments
    $attachmentSlots = [
        'incorporation_cert', 'tin_cert', 'vat_cert', 'tax_clearance', 
        'business_license', 'memart_cert', 'board_resolution', 
        'application_letter', 'intro_letter', 'bank_statement', 
        'financial_statement', 'lease_agreement', 'local_gov_letter', 
        'brela_certificate'
    ];

    foreach ($attachmentSlots as $slot) {
        if (isset($_FILES[$slot]) && $_FILES[$slot]['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES[$slot]['name'], PATHINFO_EXTENSION);
            $filename = $slot . '_' . $customerId . '_' . time() . '.' . $ext;
            $target_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES[$slot]['tmp_name'], $target_path)) {
                $data[$slot . '_path'] = 'uploads/parties/customers/' . $filename;
                $docName = ucwords(str_replace(['_', 'cert'], [' ', ' Certificate'], $slot)) . ': ' . $data['customer_name'];
                registerFileInLibrary($pdo, $data[$slot . '_path'], $_FILES[$slot]['name'], $_FILES[$slot]['size'], $docName, 'customer,business,' . $slot, $_SESSION['user_id']);
            }
        } elseif (isset($_POST['remove_' . $slot]) && $_POST['remove_' . $slot] == '1') {
            $data[$slot . '_path'] = null;
        }
    }

    // Handle Dynamic Additional Attachments
    for ($i = 1; $i <= 4; $i++) {
        $fileKey = "other_attachment_{$i}";
        $labelKey = "other_attachment_{$i}_label";
        
        // Save label if provided
        if (isset($_POST[$labelKey])) {
            $data["other_attachment_{$i}_label"] = $_POST[$labelKey];
        }

        // Handle file
        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION);
            $filename = "other_{$i}_" . $customerId . '_' . time() . '.' . $ext;
            $target_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target_path)) {
                $data["other_attachment_{$i}_path"] = 'uploads/parties/customers/' . $filename;
                $docLabel = $_POST[$labelKey] ?? "Additional Attachment {$i}";
                registerFileInLibrary($pdo, $data["other_attachment_{$i}_path"], $_FILES[$fileKey]['name'], $_FILES[$fileKey]['size'], $docLabel . ': ' . $data['customer_name'], 'customer,additional', $_SESSION['user_id']);
            }
        } elseif (isset($_POST['remove_other_attachment_' . $i]) && $_POST['remove_other_attachment_' . $i] == '1') {
            $data["other_attachment_{$i}_path"] = null;
        }
    }

    // Build SQL
    $update_parts = [];
    $params = [];
    foreach ($data as $key => $value) {
        $update_parts[] = "`$key` = ?";
        $params[] = $value;
    }
    $params[] = $customerId;

    $sql = "UPDATE customers SET " . implode(', ', $update_parts) . " WHERE customer_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Log Activity
    logActivity($pdo, $_SESSION['user_id'], "Updated customer: {$data['customer_name']} (ID: $customerId)");

    echo json_encode([
        'success' => true,
        'message' => 'Customer updated successfully'
    ]);

} catch (Exception $e) {
    error_log("Customer Update Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
