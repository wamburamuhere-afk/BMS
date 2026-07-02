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

// Phase E — project-scope gate
if (!empty($ipc['project_id']) && function_exists('userCan') && !userCan('project', (int)$ipc['project_id'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied: project not in your scope.']);
    exit();
}

require_once __DIR__ . '/../../core/code_generator.php';

$invoice_date = date('Y-m-d');
$due_date = date('Y-m-d', strtotime('+30 days'));
$subtotal = $ipc['net_payable'];
$notes = "Interim Payment Certificate: {$ipc['ipc_number']}\nPeriod: {$ipc['period_from']} to {$ipc['period_to']}\nProject: {$ipc['project_name']}";

// Invoice number allocation + invoice INSERT + IPC link are one atomic unit:
// a failure can't leave an invoice without its IPC link (which allowed a retry
// to create a duplicate invoice for the same certificate), and a failed save
// can't burn a sequential invoice number.
try {
    $pdo->beginTransaction();
    try {
        // Auto invoice number — company-prefixed sequential (BFS-INV-0001).
        $invoice_number = nextCode($pdo, 'INV');

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

        // Link IPC to invoice — guarded on invoice_id still being NULL so two
        // concurrent requests can't both invoice the same certificate.
        $link = $pdo->prepare("UPDATE interim_payment_certificates
                                  SET invoice_id=?, status='Paid', updated_at=NOW()
                                WHERE ipc_id=? AND invoice_id IS NULL");
        $link->execute([$invoice_id, $ipc_id]);
        if ($link->rowCount() === 0) {
            throw new Exception('An invoice already exists for this IPC');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    logActivity($pdo, $_SESSION['user_id'], "Created invoice {$invoice_number} from IPC {$ipc['ipc_number']}");
    echo json_encode(['success'=>true,'message'=>"Invoice {$invoice_number} created successfully",'invoice_id'=>$invoice_id,'invoice_number'=>$invoice_number]);
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
