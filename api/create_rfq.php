<?php
// File: api/create_rfq.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $supplier_id  = intval($_POST['supplier_id'] ?? 0);
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $project_id   = intval($_POST['project_id'] ?? 0) ?: null;
    $rfq_date     = $_POST['rfq_date'] ?? date('Y-m-d');
    $deadline     = $_POST['deadline_date'] ?? null ?: null;
    $items        = json_decode($_POST['items'] ?? '[]', true);

    if (!$supplier_id)  throw new Exception('Supplier is required');
    if (!$warehouse_id) throw new Exception('Warehouse is required');
    if (empty($items))  throw new Exception('At least one item is required');

    // Generate RFQ number
    $year  = date('Y');
    $month = date('m');
    $last  = $pdo->query("SELECT MAX(CAST(SUBSTRING(rfq_number, -4) AS UNSIGNED)) FROM rfq WHERE rfq_number LIKE 'RFQ-{$year}{$month}-%'")->fetchColumn() ?: 0;
    $rfq_number = 'RFQ-' . $year . $month . '-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();

    // Build creator snapshot (frozen at time of creation)
    $prepared_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (!$prepared_name) $prepared_name = $_SESSION['username'] ?? 'Unknown';
    $prepared_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Staff';

    $stmt = $pdo->prepare("INSERT INTO rfq
        (rfq_number, supplier_id, warehouse_id, project_id, rfq_date, deadline_date,
         status, created_by, prepared_by_name, prepared_by_role)
        VALUES (?,?,?,?,?,?,'draft',?,?,?)");
    $stmt->execute([$rfq_number, $supplier_id, $warehouse_id, $project_id,
                    $rfq_date, $deadline, $_SESSION['user_id'],
                    $prepared_name, $prepared_role]);
    $rfq_id = $pdo->lastInsertId();

    $si = $pdo->prepare("INSERT INTO rfq_items (rfq_id, description, unit, qty, item_order, product_id) VALUES (?,?,?,?,?,?)");
    foreach ($items as $k => $item) {
        $si->execute([$rfq_id, $item['description'], $item['unit'] ?? '', $item['qty'] ?? 1, $k + 1, $item['product_id'] ?? null]);
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Created RFQ #{$rfq_number}");
    echo json_encode(['success' => true, 'message' => "RFQ #{$rfq_number} created successfully.", 'rfq_id' => $rfq_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}