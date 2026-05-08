<?php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isset($_GET['role_id'])) {
    echo json_encode(['success' => false, 'message' => 'Role ID missing']);
    exit;
}

$role_id = $_GET['role_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = ?");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        echo json_encode(['success' => false, 'message' => 'Role not found']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT permission_id, can_view, can_create, can_edit, can_delete 
        FROM role_permissions 
        WHERE role_id = ?
    ");
    $stmt->execute([$role_id]);
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Map permissions for easier front-end consumption
    $permissions = [];
    foreach ($perms as $p) {
        $permissions[$p['permission_id']] = [
            'view' => (int)$p['can_view'],
            'create' => (int)$p['can_create'],
            'edit' => (int)$p['can_edit'],
            'delete' => (int)$p['can_delete']
        ];
    }

    echo json_encode(['success' => true, 'role' => $role, 'permissions' => $permissions]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
