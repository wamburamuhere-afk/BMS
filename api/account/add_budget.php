<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

// Check if user is logged in
if (!isAuthenticated()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check permission dynamically
if (!canCreate('budget')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create budgets']);
    exit();
}

// Get POST data
$budget_year = !empty($_POST['budget_year']) ? $_POST['budget_year'] : date('Y');
$budget_month = !empty($_POST['budget_month']) ? $_POST['budget_month'] : date('m');
$category_id = '';
$budget_name = $_POST['budget_name'] ?? ($_POST['category_other'] ?? '');
$allocated_amount = $_POST['allocated_amount'] ?? 0;
$notes = $_POST['notes'] ?? '';
$status = 'pending'; // Always start as pending — awaiting approval
$project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
$payment_reference = $_POST['payment_reference'] ?? '';

// Phase C — when project_id is supplied, it must be in user scope.
if ($project_id && !userCan('project', $project_id)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
    exit;
}

// Handle Budget Name (Manual Entry)
if (!empty($budget_name)) {
    $stmt = $pdo->prepare("SELECT id FROM expense_categories WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([trim($budget_name)]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $category_id = $existing;
    } else {
        $ins = $pdo->prepare("INSERT INTO expense_categories (name, status, created_at, updated_at) VALUES (?, 'active', NOW(), NOW())");
        $ins->execute([trim($budget_name)]);
        $category_id = $pdo->lastInsertId();
    }
}

// Handle Line Items
$is_service = isset($_POST['budget_is_service_value']) && $_POST['budget_is_service_value'] == '1' ? 1 : 0;
$line_items = [];
if (isset($_POST['item_desc']) && is_array($_POST['item_desc'])) {
    foreach ($_POST['item_desc'] as $i => $desc) {
        $line_items[] = [
            'desc'     => $desc,
            'units'    => $_POST['item_units'][$i] ?? '',
            'qty'      => $_POST['item_qty'][$i] ?? 1,
            'price'    => $_POST['item_price'][$i] ?? 0,
            'tax_rate' => floatval($_POST['item_tax'][$i] ?? 0)
        ];
    }
}
$line_items_json = json_encode(['is_service' => $is_service, 'items' => $line_items]);

// Validate required fields
if (empty($category_id) || empty($allocated_amount)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Budget Name and Amount are required']);
    exit();
}

// Check for duplicate — same category + year + month + project (project is optional)
if (!is_null($project_id)) {
    $dup_check = $pdo->prepare("SELECT budget_id FROM budgets WHERE category_id = ? AND budget_year = ? AND budget_month = ? AND project_id = ?");
    $dup_check->execute([$category_id, $budget_year, $budget_month, $project_id]);
} else {
    $dup_check = $pdo->prepare("SELECT budget_id FROM budgets WHERE category_id = ? AND budget_year = ? AND budget_month = ? AND project_id IS NULL");
    $dup_check->execute([$category_id, $budget_year, $budget_month]);
}
if ($dup_check->fetchColumn()) {
    $project_note = !is_null($project_id) ? ' under the selected project' : ' (no project)';
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'A budget for "' . $budget_name . '" already exists for the selected month and year' . $project_note . '. Please edit the existing one instead.']);
    exit();
}

// Attachment handling
$attachment_path = null;
if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] == 0) {
    $upload_dir = __DIR__ . '/../../uploads/projects/' . ($project_id ?: 'general') . '/budgets/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $file_ext = pathinfo($_FILES['attachment_file']['name'], PATHINFO_EXTENSION);
    $file_name = 'budget_' . time() . '_' . uniqid() . '.' . $file_ext;
    $target_file = $upload_dir . $file_name;
    if (move_uploaded_file($_FILES['attachment_file']['tmp_name'], $target_file)) {
        $attachment_path = 'uploads/projects/' . ($project_id ?: 'general') . '/budgets/' . $file_name;
    }
}

// Get actual expenses (if any)
$actual_expenses = 0; // For new ones, usually 0 unless we calculating retrospectively
// ... (Variance calc remains same)

// Insert
$insert_stmt = $pdo->prepare("
    INSERT INTO budgets (
        category_id, budget_year, budget_month, allocated_amount, actual_amount, 
        status, notes, created_by, variance, variance_percentage, 
        project_id, line_items, payment_reference, attachment, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

try {
    $insert_stmt->execute([
        $category_id, $budget_year, $budget_month, $allocated_amount, 0,
        $status, $notes, $_SESSION['user_id'], $allocated_amount, 100,
        $project_id, $line_items_json, $payment_reference, $attachment_path
    ]);
    
    $budget_id = $pdo->lastInsertId();
    $log_period = date('F Y', mktime(0, 0, 0, $budget_month, 1, $budget_year));
    $log_project = $project_id ? " (Project #$project_id)" : '';
    logActivity($pdo, $_SESSION['user_id'], "Created budget: '$budget_name' — TZS " . number_format($allocated_amount, 2) . " for $log_period$log_project");
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Budget added successfully', 'budget_id' => $budget_id]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
