<?php
// File: api/operations/delete_do.php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    csrf_check();

    if (!canDelete('do')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete delivery orders');
    }

    $do_id = intval($_POST['do_id'] ?? 0);
    if (!$do_id) throw new Exception('DO ID is required.');

    // Phase C — block deletes against DOs on projects not in user scope
    assertScopeForRecord('delivery_orders', 'do_id', $do_id);

    $do = $pdo->prepare("SELECT do_id, do_number, status FROM delivery_orders WHERE do_id = ?");
    $do->execute([$do_id]);
    $do = $do->fetch(PDO::FETCH_ASSOC);
    if (!$do) throw new Exception('Delivery Order not found.');
    if ($do['status'] !== 'pending') {
        throw new Exception("Only a pending Delivery Order can be deleted (this one is {$do['status']}).");
    }

    $pdo->beginTransaction();

    // Attachments — unlink files best-effort, then drop rows.
    $atts = $pdo->prepare("SELECT file_path FROM do_attachments WHERE do_id = ?");
    $atts->execute([$do_id]);
    foreach ($atts->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $full = __DIR__ . '/../../' . $a['file_path'];
        if (is_file($full)) @unlink($full);
    }
    $pdo->prepare("DELETE FROM do_attachments WHERE do_id = ?")->execute([$do_id]);
    $pdo->prepare("DELETE FROM delivery_order_items WHERE do_id = ?")->execute([$do_id]);
    $pdo->prepare("DELETE FROM delivery_orders WHERE do_id = ?")->execute([$do_id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], 'Delete DO', "User deleted Delivery Order #{$do['do_number']} (ID $do_id)");

    echo json_encode(['success' => true, 'message' => "Delivery Order #{$do['do_number']} deleted."]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
