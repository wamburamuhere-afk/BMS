<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
    $user_id = $_SESSION['user_id'];

    if (!canDelete('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete material lists');
    }

    $id = intval($_POST['id'] ?? 0);
    if (!$id) throw new Exception('Invalid ID');

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('nip_material_lists', 'id', $id);
    }

    $row = $pdo->prepare("SELECT name, warehouse_id FROM nip_material_lists WHERE id=?");
    $row->execute([$id]);
    $ml = $row->fetch(PDO::FETCH_ASSOC);
    if (!$ml) throw new Exception('Material list not found.');

    // Warehouse-scope gate — a user restricted to one warehouse must not
    // delete a list belonging to a different warehouse in the same project.
    if (!empty($ml['warehouse_id']) && function_exists('userCan') && !userCan('warehouse', (int)$ml['warehouse_id'])) {
        http_response_code(403);
        throw new Exception('Access denied: this material list is not in your warehouse scope.');
    }

    // nip_material_list_nips has ON DELETE CASCADE — so deleting the list removes its NIPs
    $pdo->prepare("DELETE FROM nip_material_lists WHERE id=?")->execute([$id]);

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $user_id, "Deleted Material List: " . $ml['name']);

    echo json_encode(['success' => true, 'message' => 'Material list deleted.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
