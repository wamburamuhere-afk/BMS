<?php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('purchase_returns')) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$supplier_id = intval($_GET['supplier_id'] ?? 0);
if (!$supplier_id) { echo json_encode(['success'=>true,'data',[]]); exit; }

// Return approved/paid supplier invoices for this supplier
$stmt = $pdo->prepare("
    SELECT si.id, si.invoice_ref, si.date_raised, si.amount,
           COALESCE(si.subtotal, si.amount) AS subtotal,
           COALESCE(si.tax_amount, 0) AS tax_amount, si.status
    FROM supplier_invoices si
    WHERE si.supplier_id = ?
      AND si.status IN ('approved','paid')
    ORDER BY si.date_raised DESC
    LIMIT 200
");
$stmt->execute([$supplier_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success'=>true,'data'=>$invoices]);
