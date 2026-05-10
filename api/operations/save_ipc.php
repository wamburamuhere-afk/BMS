<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$ipc_id            = $_POST['ipc_id'] ?? null;
$project_id        = $_POST['project_id'] ?? null;
$ipc_date          = trim($_POST['ipc_date'] ?? date('Y-m-d'));
$period_from       = trim($_POST['period_from'] ?? '');
$period_to         = trim($_POST['period_to'] ?? '');
$retention_percent = floatval($_POST['retention_percent'] ?? 10);
$previous_payments = floatval($_POST['previous_payments'] ?? 0);
$notes             = trim($_POST['notes'] ?? '');
$status            = trim($_POST['status'] ?? 'Draft');

// Items: array of {description, amount}
$items_raw = $_POST['items'] ?? [];
$items = [];
$certified_amount = 0;
if (is_array($items_raw)) {
    foreach ($items_raw as $item) {
        $desc = trim($item['description'] ?? '');
        $amt  = floatval($item['amount'] ?? 0);
        if ($desc !== '' || $amt > 0) {
            $items[] = ['description' => $desc, 'amount' => $amt];
            $certified_amount += $amt;
        }
    }
}
$certified_amount  = round($certified_amount, 2);
$items_json        = json_encode($items);

// Auto-calculate
$retention_amount = round($certified_amount * $retention_percent / 100, 2);
$net_payable      = round($certified_amount - $retention_amount - $previous_payments, 2);

if (!$project_id) {
    echo json_encode(['success'=>false,'message'=>'Project ID required']); exit();
}

try {
    if ($ipc_id) {
        $stmt = $pdo->prepare("UPDATE interim_payment_certificates SET
            ipc_date=?, period_from=?, period_to=?,
            certified_amount=?, retention_percent=?, retention_amount=?,
            previous_payments=?, net_payable=?, status=?, notes=?,
            items_json=?, updated_at=NOW()
            WHERE ipc_id=? AND project_id=?");
        $stmt->execute([
            $ipc_date, $period_from ?: null, $period_to ?: null,
            $certified_amount, $retention_percent, $retention_amount,
            $previous_payments, $net_payable, $status, $notes,
            $items_json, $ipc_id, $project_id
        ]);
        logActivity($pdo, $_SESSION['user_id'], "Updated IPC {$ipc_id} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'IPC updated successfully','net_payable'=>$net_payable]);
    } else {
        $count = $pdo->prepare("SELECT COUNT(*) FROM interim_payment_certificates WHERE project_id=?");
        $count->execute([$project_id]);
        $no = 'IPC-' . str_pad($count->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT INTO interim_payment_certificates
            (project_id, ipc_number, ipc_date, period_from, period_to,
             certified_amount, retention_percent, retention_amount,
             previous_payments, net_payable, status, notes, items_json, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $project_id, $no, $ipc_date, $period_from ?: null, $period_to ?: null,
            $certified_amount, $retention_percent, $retention_amount,
            $previous_payments, $net_payable, $status, $notes,
            $items_json, $_SESSION['user_id']
        ]);
        $new_id = $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created IPC {$no} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'IPC created successfully','ipc_number'=>$no,'ipc_id'=>$new_id,'net_payable'=>$net_payable]);
    }
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
