<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

$ipc_id            = $_POST['ipc_id'] ?? null;
$project_id        = $_POST['project_id'] ?? null;
$milestone_id      = !empty($_POST['milestone_id']) ? $_POST['milestone_id'] : null;
$period_from       = trim($_POST['period_from'] ?? '');
$period_to         = trim($_POST['period_to'] ?? '');
$work_done_percent = floatval($_POST['work_done_percent'] ?? 0);
$cumulative_percent= floatval($_POST['cumulative_percent'] ?? 0);
$contract_sum      = floatval($_POST['contract_sum'] ?? 0);
$certified_amount  = floatval($_POST['certified_amount'] ?? 0);
$retention_percent = floatval($_POST['retention_percent'] ?? 10);
$previous_payments = floatval($_POST['previous_payments'] ?? 0);
$notes             = trim($_POST['notes'] ?? '');
$status            = trim($_POST['status'] ?? 'Draft');

// Auto-calculate retention and net payable
$retention_amount = round($certified_amount * $retention_percent / 100, 2);
$net_payable = round($certified_amount - $retention_amount - $previous_payments, 2);

if (!$project_id || !$period_from || !$period_to) {
    echo json_encode(['success'=>false,'message'=>'Project, period from and period to are required']); exit();
}

try {
    if ($ipc_id) {
        $stmt = $pdo->prepare("UPDATE interim_payment_certificates SET
            milestone_id=?, period_from=?, period_to=?, work_done_percent=?,
            cumulative_percent=?, contract_sum=?, certified_amount=?,
            retention_percent=?, retention_amount=?, previous_payments=?,
            net_payable=?, status=?, notes=?, updated_at=NOW()
            WHERE ipc_id=? AND project_id=?");
        $stmt->execute([
            $milestone_id, $period_from, $period_to, $work_done_percent,
            $cumulative_percent, $contract_sum, $certified_amount,
            $retention_percent, $retention_amount, $previous_payments,
            $net_payable, $status, $notes, $ipc_id, $project_id
        ]);
        logActivity($pdo, $_SESSION['user_id'], "Updated IPC {$ipc_id} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'IPC updated successfully','net_payable'=>$net_payable]);
    } else {
        // Auto-generate IPC number
        $count = $pdo->prepare("SELECT COUNT(*) FROM interim_payment_certificates WHERE project_id=?");
        $count->execute([$project_id]);
        $no = 'IPC-' . str_pad($count->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT INTO interim_payment_certificates
            (project_id, milestone_id, ipc_number, period_from, period_to, work_done_percent,
             cumulative_percent, contract_sum, certified_amount, retention_percent,
             retention_amount, previous_payments, net_payable, status, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $project_id, $milestone_id, $no, $period_from, $period_to, $work_done_percent,
            $cumulative_percent, $contract_sum, $certified_amount, $retention_percent,
            $retention_amount, $previous_payments, $net_payable, $status, $notes, $_SESSION['user_id']
        ]);
        $new_id = $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created IPC {$no} on project {$project_id}");
        echo json_encode(['success'=>true,'message'=>'IPC created successfully','ipc_number'=>$no,'ipc_id'=>$new_id,'net_payable'=>$net_payable]);
    }
} catch (PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
