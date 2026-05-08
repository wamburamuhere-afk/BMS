<?php
require_once 'c:/wamp64/www/bms/includes/config.php';

echo "--- ROLES ---\n";
$roles = $pdo->query("SELECT role_id, role_name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
print_r($roles);

echo "\n--- SAMPLE USERS ---\n";
$users = $pdo->query("SELECT user_id, username, role_id FROM users LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

echo "\n--- ALL PERMISSIONS POOL ---\n";
$perms = $pdo->query("SELECT permission_id, page_key FROM permissions")->fetchAll(PDO::FETCH_ASSOC);
print_r($perms);

echo "\n--- ROLE PERMISSIONS (ACCOUNTANT - ID 7) ---\n";
$roleId = 7;
$rp = $pdo->query("SELECT p.page_key, rp.can_view, rp.can_create, rp.can_edit, rp.can_delete 
                   FROM role_permissions rp 
                   JOIN permissions p ON p.permission_id = rp.permission_id 
                   WHERE rp.role_id = $roleId")->fetchAll(PDO::FETCH_ASSOC);
print_r($rp);
?>
