<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
    $user_id = $_SESSION['user_id'];

    if (!canEdit('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to edit material lists');
    }

    $id           = intval($_POST['id']           ?? 0);
    $name         = trim($_POST['name']           ?? '');
    $project_id   = !empty($_POST['project_id'])  ? intval($_POST['project_id'])   : null;
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;

    if (!$id)       throw new Exception('Invalid list ID.');
    if (!$name)     throw new Exception('Material List Name is required.');

    // Phase E — project-scope gate on existing list and new project if reassigned
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('nip_material_lists', 'id', $id);
    }
    if (!empty($project_id) && function_exists('userCan') && !userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: target project not in your scope.');
    }

    // Warehouse-scope gate — a user restricted to one warehouse must not
    // touch a list belonging to a different warehouse (current or target).
    $curWh = $pdo->prepare("SELECT warehouse_id FROM nip_material_lists WHERE id = ?");
    $curWh->execute([$id]);
    $curWarehouseId = $curWh->fetchColumn();
    if (!empty($curWarehouseId) && function_exists('userCan') && !userCan('warehouse', (int)$curWarehouseId)) {
        http_response_code(403);
        throw new Exception('Access denied: this material list is not in your warehouse scope.');
    }
    if (!empty($warehouse_id) && function_exists('userCan') && !userCan('warehouse', $warehouse_id)) {
        http_response_code(403);
        throw new Exception('Access denied: target warehouse not in your scope.');
    }

    $nip_rows = [];
    if (isset($_POST['nips']) && is_array($_POST['nips'])) {
        foreach ($_POST['nips'] as $row) {
            $pid = intval($row['product_id'] ?? 0);
            $qty = floatval($row['quantity']  ?? 0);
            if (!$pid) continue;
            if ($qty <= 0) $qty = 1;
            $nip_rows[] = ['product_id' => $pid, 'quantity' => $qty];
        }
    }
    if (empty($nip_rows)) throw new Exception('Select at least one Non-Inventory Product.');

    $pdo->beginTransaction();

    // Re-code a legacy list number on edit (material lists don't post to the GL).
    require_once __DIR__ . '/../core/code_generator.php';
    $curMl = $pdo->prepare("SELECT list_no FROM nip_material_lists WHERE id = ?");
    $curMl->execute([$id]);
    $oldMl = (string)$curMl->fetchColumn();
    $newMl = codeForEdit($pdo, 'ML', $oldMl, 'ML-[0-9].*', 'nip_material_lists', (int)$id);
    if ($newMl !== $oldMl) {
        $pdo->prepare("UPDATE nip_material_lists SET list_no = ? WHERE id = ?")->execute([$newMl, $id]);
    }

    $pdo->prepare("
        UPDATE nip_material_lists
        SET name=?, project_id=?, warehouse_id=?, updated_at=NOW()
        WHERE id=?
    ")->execute([$name, $project_id, $warehouse_id, $id]);

    $pdo->prepare("DELETE FROM nip_material_list_nips WHERE material_list_id=?")->execute([$id]);

    $ins = $pdo->prepare("
        INSERT INTO nip_material_list_nips (material_list_id, nip_product_id, quantity)
        VALUES (?, ?, ?)
    ");
    foreach ($nip_rows as $row) {
        $ins->execute([$id, $row['product_id'], $row['quantity']]);
    }

    $pdo->commit();

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $user_id, "Updated Material List: $name");

    echo json_encode(['success' => true, 'message' => 'Material list updated successfully!']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
