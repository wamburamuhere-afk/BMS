<?php
// api/create_return_dn.php
// Creates a pending outbound DN pre-filled with damaged/expired items from an approved inbound DN.
// scope-audit: skip — source DN access is guarded by assertScopeForRecord below
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('dn')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

global $pdo;

$source_dn_id = intval($_POST['delivery_id'] ?? 0);
if ($source_dn_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid DN ID']);
    exit;
}

assertScopeForRecord('deliveries', 'delivery_id', $source_dn_id);

try {
    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $stmt->execute([$source_dn_id]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        echo json_encode(['success' => false, 'message' => 'Delivery Note not found']);
        exit;
    }
    if ($source['dn_type'] !== 'inbound') {
        echo json_encode(['success' => false, 'message' => 'Returns can only be created from inbound DNs']);
        exit;
    }
    if ($source['status'] !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'DN must be approved before creating a return']);
        exit;
    }

    // Only damaged or expired items qualify for return
    $itemStmt = $pdo->prepare("
        SELECT di.*, p.product_name AS p_name, p.sku AS p_sku
        FROM delivery_items di
        LEFT JOIN products p ON di.product_id = p.product_id
        WHERE di.delivery_id = ? AND di.`condition` IN ('damaged','expired')
    ");
    $itemStmt->execute([$source_dn_id]);
    $return_items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($return_items)) {
        echo json_encode(['success' => false, 'message' => 'No damaged or expired items found to return']);
        exit;
    }

    // Company-prefixed sequential outbound DN number (BFS-DN-0001).
    require_once __DIR__ . '/../core/code_generator.php';
    $delivery_number = nextCode($pdo, 'DN');

    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $user_role = $_SESSION['user_role'] ?? 'Staff';
    $source_ref = $source['dn_number'] ?: $source['delivery_number'];

    $pdo->beginTransaction();

    $ins = $pdo->prepare("
        INSERT INTO deliveries
            (delivery_number, dn_number, dn_type, party_type, supplier_id, subcontractor_id,
             delivery_date, status, created_by, project_id, warehouse_id, purchase_order_id,
             contact_person, contact_phone, delivery_address, notes,
             prepared_by_name, prepared_by_role, prepared_at)
        VALUES (?, ?, 'outbound', ?, ?, ?, ?, 'pending', ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([
        $delivery_number, $delivery_number,
        $source['party_type'], $source['supplier_id'], $source['subcontractor_id'],
        date('Y-m-d'), $_SESSION['user_id'],
        $source['project_id']       ?: null,
        $source['warehouse_id'],
        $source['purchase_order_id'] ?: null,
        $source['contact_person']   ?: null,
        $source['contact_phone']    ?: null,
        $source['delivery_address'] ?: null,
        'Return of damaged/expired items from DN #' . $source_ref,
        $user_name, $user_role,
    ]);
    $new_dn_id = (int)$pdo->lastInsertId();

    $itemIns = $pdo->prepare("
        INSERT INTO delivery_items
            (delivery_id, product_id, product_name, sku, quantity_delivered, unit, `condition`, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($return_items as $ri) {
        $itemIns->execute([
            $new_dn_id,
            $ri['product_id'],
            $ri['product_name'] ?? $ri['p_name'] ?? '',
            $ri['sku']          ?? $ri['p_sku']  ?? '',
            $ri['quantity_delivered'],
            $ri['unit'] ?: 'pcs',
            $ri['condition'],
            'Return (' . $ri['condition'] . ') from DN #' . $source_ref,
        ]);
    }

    $actor = workflowActorSnapshot();
    workflowCaptureSignature($pdo, 'delivery', $new_dn_id, 'created',
        (int)$_SESSION['user_id'], $actor['name'], $actor['role']);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'],
        "Created return DN #{$delivery_number} from approved inbound DN #{$source_ref}");

    echo json_encode([
        'success'     => true,
        'message'     => "Return DN #{$delivery_number} created with " . count($return_items) . " item(s).",
        'delivery_id' => $new_dn_id,
        'dn_number'   => $delivery_number,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('create_return_dn error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
