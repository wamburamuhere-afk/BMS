<?php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isset($_GET['role_id'])) {
    echo json_encode(['success' => false, 'message' => 'Role ID missing']);
    exit;
}

$role_id = $_GET['role_id'];
$current_permissions = [];

try {
    $stmt = $pdo->prepare("
        SELECT permission_id, can_view, can_create, can_edit, can_delete 
        FROM role_permissions 
        WHERE role_id = ?
    ");
    $stmt->execute([$role_id]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_permissions[$row['permission_id']] = [
            'view' => (int)$row['can_view'],
            'create' => (int)$row['can_create'],
            'edit' => (int)$row['can_edit'],
            'delete' => (int)$row['can_delete']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $current_permissions]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
