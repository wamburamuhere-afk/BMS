<?php
// scope-audit: skip — supplier_invoices lookup by supplier_id for payment voucher form; no project_id column on supplier_invoices
// Hardened JSON endpoint: suppress any stray notice/warning HTML so the response
// is ALWAYS valid JSON (prevents the invoice dropdown hanging on "Loading…").
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../../roots.php';
global $pdo;
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
        SELECT si.id, si.invoice_ref, si.amount, si.amount_paid, si.date_raised, si.invoice_type
        FROM supplier_invoices si
        WHERE si.supplier_id  = ?
          AND si.invoice_type = ?
          AND si.status       IN ('approved', 'partial')
        ORDER BY si.date_raised DESC
    ");
    $stmt->execute([$payee_id, $payee_type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($r) {
        $total     = (float)$r['amount'];
        $paid      = (float)($r['amount_paid'] ?? 0);
        $remaining = round($total - $paid, 2);
        $isPartial = $paid > 0.005;
        $label = $r['invoice_ref']
               . ($isPartial
                   ? ' — TZS ' . number_format($remaining, 2) . ' remaining of ' . number_format($total, 2)
                   : ' — TZS ' . number_format($total, 2));
        return [
            'id'        => $r['id'],
            'label'     => $label,
            'amount'    => $total,
            'remaining' => $remaining,
            'ref'       => $r['invoice_ref'],
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    error_log('get_payee_invoices: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
