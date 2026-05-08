<?php
require_once 'includes/config.php';
try {
    $pdo->beginTransaction();
    
    $modules_to_remove = ['Loans', 'Guarantors', 'Collections'];
    $keys_to_remove = ['overdue_loans', 'loan_portfolio', 'collections_dashboard', 'guarantors', 'guarantor_registration'];

    $where = "module_name IN ('" . implode("','", $modules_to_remove) . "')";
    $where .= " OR page_key LIKE '%loan%'";
    $where .= " OR page_key LIKE '%guarantor%'";
    
    $select = $pdo->query("SELECT permission_id FROM permissions WHERE $where");
    $ids = $select->fetchAll(PDO::FETCH_COLUMN);
    
    if($ids) {
        $id_list = implode(',', $ids);
        $pdo->exec("DELETE FROM role_permissions WHERE permission_id IN ($id_list)");
        $pdo->exec("DELETE FROM permissions WHERE permission_id IN ($id_list)");
    }
    
    // Rename remaining generic modules to be more BMS
    $pdo->exec("UPDATE permissions SET module_name = 'Sales & Revenue' WHERE module_name = 'Collections'"); // If any left
    
    $pdo->commit();
    echo "Double-pass cleanup done.\n";
} catch(Exception $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    echo "Error: " . $e->getMessage();
}
?>
