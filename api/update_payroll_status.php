<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/auto_post_hook.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change payroll status']);
    exit();
}

$payroll_id = $_POST['payroll_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$payroll_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID and status required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
}

try {
    // DB Hardening
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending'");
    try { $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount"); } catch(Exception $e) {}
} catch (Exception $e) {}

try {
    // Phase 4.6 — fetch payroll snapshot BEFORE the UPDATE so the auto-post
    // has clean net_salary + payroll_date data. (Payroll has no project_id
    // column; the entry is company-wide overhead.)
    $snap_stmt = $pdo->prepare("SELECT net_salary, payroll_date, payroll_number
                                  FROM payroll WHERE payroll_id = ?");
    $snap_stmt->execute([$payroll_id]);
    $payroll_snap = $snap_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payroll_snap) throw new Exception('Payroll record not found');

    // Wrap status change + audit + auto-post in one transaction so a ledger
    // posting failure rolls back the status change too.
    $pdo->beginTransaction();

    // Additional fields based on status
    $sql = "UPDATE payroll SET payment_status = ?, updated_by = ?, updated_at = NOW()";

    if ($status === 'approved') {
        $sql .= ", approved_by = " . $_SESSION['user_id'] . ", date_approved = NOW()";
    } elseif ($status === 'paid') {
         $sql .= ", payment_date = NOW()";
    }

    $sql .= " WHERE payroll_id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $_SESSION['user_id'], $payroll_id]);

    // Log status update action
    logAudit($pdo, $_SESSION['user_id'], 'update_payroll_status', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Updated payroll status to '$status' for record ID: $payroll_id"
    ]);

    // Phase 4.6 — auto-post to canonical ledger via journal_mappings.
    // Only the 'paid' transition writes to the ledger. 'approved' is HR
    // signoff (no cash movement yet); only 'paid' moves money.
    // Uses net_salary (what the employee actually receives after tax + deductions).
    // Quiet no-op while 'payroll_paid' mapping is_active=0 (default).
    $post_result = ['posted' => false, 'reason' => 'status_not_paid'];
    if ($status === 'paid' && (float)$payroll_snap['net_salary'] > 0) {
        $post_result = autoPostEvent(
            $pdo,
            'payroll_paid',
            'payroll',
            (int)$payroll_id,
            (float)$payroll_snap['net_salary'],
            null,  // payroll is company-wide; no project_id
            $payroll_snap['payroll_date'],
            (int)$_SESSION['user_id'],
            "Payroll {$payroll_snap['payroll_number']} paid (net)"
        );
    }

    $pdo->commit();

    $response = ['success' => true, 'message' => 'Status updated successfully.'];
    if (!empty($post_result['posted'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
    } elseif (($post_result['reason'] ?? '') === 'mapping_not_configured') {
        $response['ledger_warning'] = "Payroll marked paid, but no ledger entry was created — admin has not "
                                    . "set both Dr/Cr accounts for 'payroll_paid' in Journal Mappings.";
    }
    echo json_encode($response);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
