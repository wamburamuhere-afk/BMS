<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

// Ensure user is authenticated
if (!isAuthenticated()) {
    header('Location: ../login.php');
    exit;
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=expenses_export_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add CSV headers
fputcsv($output, [
    'Expense ID',
    'Date',
    'Expense Account',
    'Amount',
    'Bank Account',
    'Description',
    'Reference Number',
    'Vendor',
    'Status',
    'Notes',
    'Created By',
    'Created At'
]);

try {
    // Build query with filters
    $sql = "
        SELECT 
            e.expense_id,
            e.expense_date,
            ea.account_name as expense_account_name,
            ba.account_name as bank_account_name,
            e.amount,
            e.description,
            e.reference_number,
            e.vendor,
            e.status,
            e.notes,
            u.username as created_by_name,
            e.created_at
        FROM expenses e
        LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
        LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
        LEFT JOIN users u ON e.created_by = u.user_id
        WHERE 1=1
    ";
    
    $params = [];

    // Apply filters
    if (!empty($_GET['expense_account_id'])) {
        $sql .= " AND e.expense_account_id = ?";
        $params[] = $_GET['expense_account_id'];
    }

    if (!empty($_GET['status'])) {
        $sql .= " AND e.status = ?";
        $params[] = $_GET['status'];
    }

    if (!empty($_GET['date_from'])) {
        $sql .= " AND e.expense_date >= ?";
        $params[] = $_GET['date_from'];
    }

    if (!empty($_GET['date_to'])) {
        $sql .= " AND e.expense_date <= ?";
        $params[] = $_GET['date_to'];
    }

    $sql .= " ORDER BY e.expense_date DESC, e.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Format data for CSV
        $csv_row = [
            $row['expense_id'],
            $row['expense_date'],
            $row['expense_account_name'],
            $row['amount'],
            $row['bank_account_name'],
            $row['description'],
            $row['reference_number'],
            $row['vendor'],
            ucfirst($row['status']),
            $row['notes'],
            $row['created_by_name'],
            $row['created_at']
        ];
        
        fputcsv($output, $csv_row);
    }

} catch (PDOException $e) {
    error_log("Export error: " . $e->getMessage());
    fputcsv($output, ['Error exporting data']);
}

fclose($output);
exit;
?>
