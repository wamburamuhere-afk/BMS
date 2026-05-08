<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    $budget_id = $_POST['budget_id'] ?? 0;
    $budget_year = !empty($_POST['budget_year']) ? $_POST['budget_year'] : date('Y');
    $budget_month = !empty($_POST['budget_month']) ? $_POST['budget_month'] : date('m');
    $category_id = '';
    $budget_name = $_POST['budget_name'] ?? ($_POST['category_other'] ?? '');
    $allocated_amount = $_POST['allocated_amount'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $payment_reference = $_POST['payment_reference'] ?? '';

    // Handle Budget Name (Manual Entry)
    if (!empty($budget_name)) {
        $stmt = $pdo->prepare("SELECT category_id FROM expense_categories WHERE LOWER(category_name) = LOWER(?)");
        $stmt->execute([trim($budget_name)]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $category_id = $existing;
        } else {
            $ins = $pdo->prepare("INSERT INTO expense_categories (category_name, status, created_at, updated_at) VALUES (?, 'active', NOW(), NOW())");
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

    if ($budget_id <= 0 || empty($category_id) || empty($allocated_amount)) {
        throw new Exception('Budget Name and Amount are required');
    }

    // Check if budget exists
    $stmt = $pdo->prepare("SELECT * FROM budgets WHERE budget_id = ?");
    $stmt->execute([$budget_id]);
    $current_budget = $stmt->fetch();
    if (!$current_budget) {
        throw new Exception('Budget not found');
    }

    // Attachment handling
    $attachment_path = $current_budget['attachment'];
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

    // Get actual expenses for variance calculation
    $expensesSql = "
        SELECT SUM(e.amount) as total_expenses 
        FROM expenses e
        JOIN accounts a ON e.expense_account_id = a.account_id
        JOIN expense_categories ec ON (a.category_id = ec.category_id OR a.account_name = ec.category_name)
        WHERE ec.category_id = ? 
        AND e.status IN ('approved', 'paid')
        AND (e.project_id = ? OR (e.project_id IS NULL AND ? IS NULL))
    ";
    $expenses_stmt = $pdo->prepare($expensesSql);
    $expenses_stmt->execute([$category_id, $project_id, $project_id]);
    $actual_expenses = $expenses_stmt->fetchColumn() ?? 0;

    $variance = $allocated_amount - $actual_expenses;
    $variance_percentage = $allocated_amount > 0 ? ($variance / $allocated_amount) * 100 : 0;

    // If current status is rejected, reset it to pending when fixing/updating
    if ($current_budget['status'] === 'rejected') {
        $status = 'pending';
    }

    $sql = "UPDATE budgets SET 
            category_id = ?, 
            budget_year = ?, 
            budget_month = ?, 
            allocated_amount = ?, 
            actual_amount = ?, 
            status = ?, 
            notes = ?, 
            variance = ?, 
            variance_percentage = ?, 
            project_id = ?,
            line_items = ?,
            payment_reference = ?,
            attachment = ?,
            updated_at = NOW() 
            WHERE budget_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $category_id, $budget_year, $budget_month, $allocated_amount, 
        $actual_expenses, $status, $notes, $variance, $variance_percentage, 
        $project_id, $line_items_json, $payment_reference, $attachment_path, $budget_id
    ]);

    logActivity($pdo, $_SESSION['user_id'], "Updated detailed budget for project ID: $project_id, Category ID: $category_id, Amount: $allocated_amount");

    echo json_encode(['success' => true, 'message' => 'Budget updated successfully']);

} catch (Exception $e) {
    error_log("Error in update_budget.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
