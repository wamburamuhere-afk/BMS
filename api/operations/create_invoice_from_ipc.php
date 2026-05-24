<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

if (!canCreate('invoices')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to create invoices from IPC']);
    exit();
}

$ipc_id = $_POST['ipc_id'] ?? null;
if (!$ipc_id) { echo json_encode(['success'=>false,'message'=>'IPC ID required']); exit(); }

// Fetch IPC with project data
$stmt = $pdo->prepare("
    SELECT ipc.*, p.customer_id, p.project_name, p.contract_number
    FROM interim_payment_certificates ipc
    JOIN projects p ON ipc.project_id = p.project_id
    WHERE ipc.ipc_id = ?
");
$stmt->execute([$ipc_id]);
$ipc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ipc) { echo json_encode(['success'=>false,'message'=>'IPC not found']); exit(); }
if ($ipc['invoice_id']) { echo json_encode(['success'=>false,'message'=>'An invoice already exists for this IPC']); exit(); }
if ($ipc['status'] !== 'Approved') { echo json_encode(['success'=>false,'message'=>'Only Approved IPCs can generate an invoice']); exit(); }

// Auto invoice number
$last = $pdo->query("SELECT invoice_number FROM invoices ORDER BY invoice_id DESC LIMIT 1")->fetchColumn();
$next_no = 1;
if ($last && preg_match('/(\d+)$/', $last, $m)) $next_no = intval($m[1]) + 1;
$invoice_number = 'INV-' . str_pad($next_no, 5, '0', STR_PAD_LEFT);

$invoice_date = date('Y-m-d');
$due_date = date('Y-m-d', strtotime('+30 days'));
$subtotal = $ipc['net_payable'];
$notes = "Interim Payment Certificate: {$ipc['ipc_number']}\nPeriod: {$ipc['period_from']} to {$ipc['period_to']}\nProject: {$ipc['project_name']}";

try {
    $ins = $pdo->prepare("INSERT INTO invoices
        (invoice_number, customer_id, invoice_date, due_date, subtotal, tax_amount,
         discount_amount, shipping_cost, grand_total, paid_amount, balance_due,
         currency, notes, status, project_id, created_by)
        VALUES (?,?,?,?,?,0,0,0,?,0,?,?,?,?,?,?)");
    $ins->execute([
        $invoice_number, $ipc['customer_id'], $invoice_date, $due_date,
        $subtotal, $subtotal, $subtotal, 'TZS', $notes, 'unpaid',
        $ipc['project_id'], $_SESSION['user_id']
    ]);
    $invoice_id = $pdo->lastInsertId();

    // Link IPC to invoice
    $pdo->prepare("UPDATE interim_payment_certificates SET invoice_id=?, status='Paid', updated_at=NOW() WHERE ipc_id=?")->execute([$invoice_id, $ipc_id]);

    logActivity($pdo, $_SESSION['user_id'], "Created invoice {$invoice_number} from IPC {$ipc['ipc_number']}");
    echo json_encode(['success'=>true,'message'=>"Invoice {$invoice_number} created successfully",'invoice_id'=>$invoice_id,'invoice_number'=>$invoice_number]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
