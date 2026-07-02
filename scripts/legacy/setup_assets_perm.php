<?php
require 'roots.php';

try {
    // 1. Add to permissions table
    $stmt = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = 'assets'");
    $stmt->execute();
    $perm = $stmt->fetch();
    
    if (!$perm) {
        $stmt = $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, module_name) VALUES ('Assets Management', 'assets', 'assets.php', 'Operations')");
        $stmt->execute();
        $perm_id = $pdo->lastInsertId();
        echo "Created permission 'assets' (ID: $perm_id)\n";
    } else {
        $perm_id = $perm['permission_id'];
        echo "Permission 'assets' already exists (ID: $perm_id)\n";
    }
    
    // 2. Assign to Admin role (role_id = 1)
    $stmt = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id = 1 AND permission_id = ?");
    $stmt->execute([$perm_id]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (1, ?, 1, 1, 1, 1)");
        $stmt->execute([$perm_id]);
        echo "Assigned 'assets' permission to Admin role\n";
    } else {
        echo "Admin role already has 'assets' permission\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
