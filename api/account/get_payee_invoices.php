<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('expenses')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$payee_type = $_GET['payee_type'] ?? '';
$payee_id   = intval($_GET['payee_id'] ?? 0);

if (!$payee_id || !in_array($payee_type, ['supplier', 'sub_contractor'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT si.id, si.invoice_ref, si.amount, si.date_raised, si.invoice_type
        FROM supplier_invoices si
        WHERE si.supplier_id  = ?
          AND si.invoice_type = ?
          AND si.status       = 'approved'
        ORDER BY si.date_raised DESC
    ");
    $stmt->execute([$payee_id, $payee_type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(fn($r) => [
        'id'     => $r['id'],
        'label'  => $r['invoice_ref'] . ' — TZS ' . number_format((float)$r['amount'], 2),
        'amount' => $r['amount'],
        'ref'    => $r['invoice_ref'],
    ], $rows);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    error_log('get_payee_invoices: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
