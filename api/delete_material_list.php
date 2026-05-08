<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
    $user_id = $_SESSION['user_id'];

    $id = intval($_POST['id'] ?? 0);
    if (!$id) throw new Exception('Invalid ID');

    $row = $pdo->prepare("SELECT name FROM nip_material_lists WHERE id=?");
    $row->execute([$id]);
    $ml = $row->fetch(PDO::FETCH_ASSOC);
    if (!$ml) throw new Exception('Material list not found.');

    // nip_material_list_nips has ON DELETE CASCADE — so deleting the list removes its NIPs
    $pdo->prepare("DELETE FROM nip_material_lists WHERE id=?")->execute([$id]);

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $user_id, "Deleted Material List: " . $ml['name']);

    echo json_encode(['success' => true, 'message' => 'Material list deleted.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
