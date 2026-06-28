<?php
// File: api/create_do.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

if (!canCreate('do')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to create delivery orders']);
    exit;
}

try {
    $dn_id          = intval($_POST['dn_id']          ?? 0);
    $project_id     = intval($_POST['project_id']     ?? 0);
    $warehouse_id   = intval($_POST['warehouse_id']   ?? 0);
    $supplier_id    = intval($_POST['supplier_id']    ?? 0);
    $do_date        = trim($_POST['do_date']          ?? date('Y-m-d'));
    $expected_date  = trim($_POST['expected_date']    ?? '') ?: null;
    $driver_name    = trim($_POST['driver_name']      ?? '') ?: null;
    $vehicle_number = trim($_POST['vehicle_number']   ?? '') ?: null;
    $contact_person = trim($_POST['contact_person']   ?? '') ?: null;
    $contact_phone  = trim($_POST['contact_phone']    ?? '') ?: null;
    $notes          = trim($_POST['notes']            ?? '') ?: null;
    $user_id        = $_SESSION['user_id'];

    if ($dn_id <= 0)      throw new Exception('DN ID is required.');
    if ($project_id <= 0) throw new Exception('Project ID is required.');

    // Phase C — block creates against projects not in user scope
    if (!userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your scope.');
    }

    // Validate DN is approved
    $dn = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ? AND project_id = ? AND status = 'approved'");
    $dn->execute([$dn_id, $project_id]);
    $dn = $dn->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception('DN not found or not approved.');

    // Check no DO exists
    $exists = $pdo->prepare("SELECT do_id FROM delivery_orders WHERE dn_id = ?");
    $exists->execute([$dn_id]);
    if ($exists->fetch()) throw new Exception('A Delivery Order already exists for this DN.');

    // Company-prefixed sequential DO number (BFS-DO-0001).
    require_once __DIR__ . '/../core/code_generator.php';
    $do_number = nextCode($pdo, 'DO');

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO delivery_orders
            (do_number, dn_id, project_id, warehouse_id, supplier_id, do_date,
             expected_date, driver_name, vehicle_number, contact_person, contact_phone,
             notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([
        $do_number, $dn_id, $project_id, $warehouse_id ?: $dn['warehouse_id'],
        $supplier_id ?: $dn['supplier_id'], $do_date,
        $expected_date, $driver_name, $vehicle_number,
        $contact_person, $contact_phone, $notes, $user_id
    ]);
    $do_id = $pdo->lastInsertId();

    // Update DN status to dispatched
    $pdo->prepare("UPDATE deliveries SET status='dispatched' WHERE delivery_id=?")->execute([$dn_id]);

    logActivity($pdo, $user_id, "Created Delivery Order #$do_number from DN #{$dn['delivery_number']}");
    $pdo->commit();

    echo json_encode([
        'success'   => true,
        'message'   => "Delivery Order #$do_number created successfully.",
        'do_id'     => $do_id,
        'do_number' => $do_number
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
