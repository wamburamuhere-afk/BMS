<?php
require_once 'includes/config.php';
$role_name = 'Managing Director';
$stmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ?");
$stmt->execute([$role_name]);
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    echo "Role not found: $role_name\n";
    // List all roles
    $stmt = $pdo->query("SELECT role_name FROM roles");
    echo "Existing roles: " . implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN)) . "\n";
    exit;
}

$role_id = $role['role_id'];
echo "Role ID for '$role_name': $role_id\n";

$stmt = $pdo->prepare("
    SELECT p.page_key, rp.can_view, rp.can_create, rp.can_edit, rp.can_delete 
    FROM role_permissions rp
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE rp.role_id = ?
");
$stmt->execute([$role_id]);
$perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Permissions for '$role_name':\n";
foreach ($perms as $p) {
    if ($p['can_view']) {
        echo "- {$p['page_key']}\n";
    }
}
?>
