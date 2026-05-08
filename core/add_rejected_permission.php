<?php
require_once 'includes/config.php';

try {
    // 1. Add 'view_rejected_loans' permission
    $stmt = $pdo->prepare("INSERT IGNORE INTO permissions (page_key, page_name, description, module_name) VALUES (?, ?, ?, ?)");
    $stmt->execute(['view_rejected_loans', 'View Rejected Loans', 'Permission to view rejected loans section', 'Loans']);
    
    // Get the permission_id
    $stmt = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = 'view_rejected_loans'");
    $stmt->execute();
    $permission_id = $stmt->fetchColumn();
    
    if ($permission_id) {
        // 2. Assign to Admin (role_id = 1)
        $stmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 1, 1, 1)");
        $stmt->execute([1, $permission_id]);
        echo "Permission 'view_rejected_loans' added and assigned to Admin.\n";
    } else {
        echo "Failed to retrieve permission_id for 'view_rejected_loans'.\n";
    }

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
?>
