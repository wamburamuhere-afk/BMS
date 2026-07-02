<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$ipc_id            = $_POST['ipc_id'] ?? null;

if (!empty($ipc_id) ? !canEdit('projects') : !canCreate('projects')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to ' . (!empty($ipc_id) ? 'edit' : 'create') . ' IPCs']);
    exit();
}

$project_id        = $_POST['project_id'] ?? null;
$sales_order_id    = intval($_POST['sales_order_id'] ?? 0) ?: null;
$ipc_date          = trim($_POST['ipc_date'] ?? date('Y-m-d'));
$period_from       = trim($_POST['period_from'] ?? '');
$period_to         = trim($_POST['period_to'] ?? '');
$retention_percent = floatval($_POST['retention_percent'] ?? 10);
$previous_payments = floatval($_POST['previous_payments'] ?? 0);
$notes             = trim($_POST['notes'] ?? '');
$status            = trim($_POST['status'] ?? 'Draft');

// Items: array of {product_name, quantity, unit, unit_price, tax_percent}
$items_raw = $_POST['items'] ?? [];
$items = [];
$subtotal  = 0;
$tax_total = 0;
if (is_array($items_raw)) {
    foreach ($items_raw as $item) {
        $product_name = trim($item['product_name'] ?? '');
        $quantity     = floatval($item['quantity'] ?? 0);
        $unit         = trim($item['unit'] ?? '');
        $unit_price   = floatval($item['unit_price'] ?? 0);
        $tax_percent  = floatval($item['tax_percent'] ?? 0);
        $line_sub     = round($quantity * $unit_price, 2);
        $tax_amount   = round($line_sub * $tax_percent / 100, 2);
        $total        = round($line_sub + $tax_amount, 2);
        if ($product_name !== '' || $quantity > 0 || $unit_price > 0) {
            $items[] = [
                'product_name' => $product_name,
                'quantity'     => $quantity,
                'unit'         => $unit,
                'unit_price'   => $unit_price,
                'tax_percent'  => $tax_percent,
                'tax_amount'   => $tax_amount,
                'total'        => $total,
            ];
            $subtotal  += $line_sub;
            $tax_total += $tax_amount;
        }
    }
}
$subtotal         = round($subtotal, 2);
$tax_total        = round($tax_total, 2);
$certified_amount = round($subtotal + $tax_total, 2);
$items_json       = json_encode($items);

$retention_amount = round($certified_amount * $retention_percent / 100, 2);
$net_payable      = round($certified_amount - $retention_amount - $previous_payments, 2);

if (!$project_id) {
    echo json_encode(['success'=>false,'message'=>'Project ID required']); exit();
}

// Phase B (scope) — block writes against projects not in user scope
if (!userCan('project', (int)$project_id)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your scope.']); exit();
}

try {
    if ($ipc_id) {
        $stmt = $pdo->prepare("UPDATE interim_payment_certificates SET
            ipc_date=?, period_from=?, period_to=?, sales_order_id=?,
            certified_amount=?, retention_percent=?, retention_amount=?,
            previous_payments=?, net_payable=?, status=?, notes=?,
            items_json=?, updated_at=NOW()
            WHERE ipc_id=? AND project_id=?");
        $stmt->execute([
            $ipc_date, $period_from ?: null, $period_to ?: null, $sales_order_id,
            $certified_amount, $retention_percent, $retention_amount,
            $previous_payments, $net_payable, $status, $notes,
            $items_json, $ipc_id, $project_id
        ]);
        logActivity($pdo, $_SESSION['user_id'], "Updated IPC {$ipc_id} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'IPC updated successfully','net_payable'=>$net_payable]);
    } else {
        // Number allocation + IPC INSERT + creation signature are one atomic
        // unit: a failed save can't burn a sequential IPC number, and an IPC
        // can't exist without its "created" signature.
        require_once __DIR__ . '/../../core/code_generator.php';
        if (!function_exists('workflowCaptureSignature')) {
            require_once __DIR__ . '/../../core/workflow.php';
        }

        $pdo->beginTransaction();
        try {
            // Company-wide sequential IPC number (BFS-IPC-0001).
            $no = nextCode($pdo, 'IPC');

            $stmt = $pdo->prepare("INSERT INTO interim_payment_certificates
                (project_id, sales_order_id, ipc_number, ipc_date, period_from, period_to,
                 certified_amount, retention_percent, retention_amount,
                 previous_payments, net_payable, status, notes, items_json, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $project_id, $sales_order_id, $no, $ipc_date, $period_from ?: null, $period_to ?: null,
                $certified_amount, $retention_percent, $retention_amount,
                $previous_payments, $net_payable, $status, $notes,
                $items_json, $_SESSION['user_id']
            ]);
            $new_id = $pdo->lastInsertId();

            // ── e-signature capture (Created By) ─ Issue 1 fix
            $wfActor = workflowActorSnapshot();
            workflowCaptureSignature(
                $pdo, 'ipc', (int)$new_id, 'created',
                (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        logActivity($pdo, $_SESSION['user_id'], "Created IPC {$no} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'IPC created successfully','ipc_number'=>$no,'ipc_id'=>$new_id,'net_payable'=>$net_payable]);
    }
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
