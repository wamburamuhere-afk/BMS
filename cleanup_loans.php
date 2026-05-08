<?php
require_once 'includes/config.php';

try {
    $pdo->beginTransaction();

    // 1. Identify loan-related permissions
    $keywords = ['loan', 'repayment', 'penalty', 'collateral', 'disburse', 'borrower', 'saver', 'next_payment'];
    $where_clauses = ["module_name = 'Loans'"];
    foreach ($keywords as $word) {
        $where_clauses[] = "page_key LIKE '%$word%'";
        $where_clauses[] = "page_name LIKE '%$word%'";
    }
    $where_sql = implode(' OR ', $where_clauses);

    $select_stmt = $pdo->query("SELECT permission_id, page_key, page_name FROM permissions WHERE $where_sql");
    $to_delete = $select_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($to_delete) . " loan-related permissions to remove.\n";

    if (count($to_delete) > 0) {
        $ids = array_column($to_delete, 'permission_id');
        $id_list = implode(',', $ids);

        // 2. Delete from role_permissions first to satisfy foreign key constraints
        $pdo->exec("DELETE FROM role_permissions WHERE permission_id IN ($id_list)");
        echo "Deleted mappings from role_permissions.\n";

        // 3. Delete from permissions table
        $pdo->exec("DELETE FROM permissions WHERE permission_id IN ($id_list)");
        echo "Deleted entries from permissions table.\n";
    }

    // 4. Fix module names for remaining permissions to be BMS-centric
    $updates = [
        'Marketing' => 'Marketing & CRM',
        'Inventory' => 'Inventory & Products',
        'Accounts' => 'Finance & Accounts',
        'Operations' => 'Business Operations',
        'Settings' => 'System Settings'
    ];

    foreach ($updates as $old => $new) {
        $stmt = $pdo->prepare("UPDATE permissions SET module_name = ? WHERE module_name = ?");
        $stmt->execute([$new, $old]);
    }
    
    // 5. Ensure BMS core pages have proper modules if they are 'Other' or NULL
    $pdo->exec("UPDATE permissions SET module_name = 'Sales & Revenue' WHERE page_key IN ('invoices', 'sales_orders', 'pos', 'quotations', 'sales_returns')");
    $pdo->exec("UPDATE permissions SET module_name = 'Inventory & Products' WHERE page_key IN ('products', 'categories', 'warehouses', 'stock_adjustments', 'brands')");

    $pdo->commit();
    echo "Cleanup completed successfully!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
