<?php
// API: Change Employee Contract Status (Tier 2, Phase 2.3 — D12)
// Transitions: draft -> active (activate) | active -> terminated (terminate).
// Renewal is automatic: activating a NEW contract for an employee who already
// has an 'active' one stamps the old row 'renewed' and links the new row via
// renewed_from_contract_id — there is no separate "renew" action.
// On activation, in the SAME transaction: at most one active contract per
// employee (row-locked), and employees.contract_end_date / probation_end_date
// are dual-written (D12) so every existing reader of those columns keeps working.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/employee_status.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Method check (permission gate is per-action below)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 3. CSRF + input validation
csrf_check();

$contract_id = intval($_POST['contract_id'] ?? 0);
$action      = trim($_POST['action'] ?? '');

if (!$contract_id) {
    echo json_encode(['success' => false, 'message' => 'Contract ID is required']);
    exit;
}
if (!in_array($action, ['activate', 'terminate'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// 4. Project-scope gate — follows the contract's employee to their project
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_contracts', 'contract_id', $contract_id);
}

// 5. Permission gate — both transitions are approval-grade actions
if (!canApprove('employee_contracts')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to ' . $action . ' contracts']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM employee_contracts WHERE contract_id = ? AND status != 'deleted' FOR UPDATE");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) throw new Exception('Contract not found');

    $emp = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
    $emp->execute([(int)$contract['employee_id']]);
    $emp_row  = $emp->fetch(PDO::FETCH_ASSOC);
    $emp_name = trim(($emp_row['first_name'] ?? '') . ' ' . ($emp_row['last_name'] ?? '')) ?: ('employee #' . $contract['employee_id']);

    if ($action === 'activate') {
        if ($contract['status'] !== 'draft') {
            throw new Exception('Only a draft contract can be activated — this one is ' . $contract['status']);
        }

        // At most one active contract per employee — lock any existing active row.
        $existing = $pdo->prepare("SELECT contract_id FROM employee_contracts
                                    WHERE employee_id = ? AND status = 'active' AND contract_id != ? FOR UPDATE");
        $existing->execute([(int)$contract['employee_id'], $contract_id]);
        $old = $existing->fetch(PDO::FETCH_ASSOC);

        if ($old) {
            $pdo->prepare("UPDATE employee_contracts SET status = 'renewed', updated_by = ? WHERE contract_id = ?")
                ->execute([$_SESSION['user_id'], (int)$old['contract_id']]);
            $pdo->prepare("UPDATE employee_contracts SET renewed_from_contract_id = ? WHERE contract_id = ?")
                ->execute([(int)$old['contract_id'], $contract_id]);
        }

        $pdo->prepare("UPDATE employee_contracts
                       SET status = 'active', activated_by = ?, activated_at = NOW(), updated_by = ?
                       WHERE contract_id = ?")
            ->execute([$_SESSION['user_id'], $_SESSION['user_id'], $contract_id]);

        // D12 dual-write — keeps every existing reader of these columns working.
        $fields = ['contract_end_date = ?'];
        $vals   = [$contract['end_date']];
        $new_probation_end = null;
        if ($contract['probation_months'] !== null) {
            $new_probation_end = date('Y-m-d', strtotime($contract['start_date'] . ' +' . (int)$contract['probation_months'] . ' months'));
            $fields[] = 'probation_end_date = ?';
            $vals[] = $new_probation_end;
        }
        $vals[] = $_SESSION['user_id'];
        $vals[] = (int)$contract['employee_id'];
        $pdo->prepare("UPDATE employees SET " . implode(', ', $fields) . ", updated_by = ? WHERE employee_id = ?")
            ->execute($vals);

        logAudit($pdo, $_SESSION['user_id'], 'activate', [
            'activity_type' => 'status_change',
            'entity_type'   => 'employee_contract',
            'entity_id'     => $contract_id,
            'description'   => "Activated {$contract['contract_type']} contract for $emp_name"
                              . ($old ? " (renews contract #{$old['contract_id']})" : ''),
            'old_values'    => ['status' => 'draft'],
            'new_values'    => ['status' => 'active'],
        ]);
        logAudit($pdo, $_SESSION['user_id'], 'update_status', [
            'activity_type' => 'status_change',
            'entity_type'   => 'employee',
            'entity_id'     => (int)$contract['employee_id'],
            'description'   => "Contract activation updated dates for $emp_name (contract #$contract_id)",
            'old_values'    => [],
            'new_values'    => array_filter([
                'contract_end_date'  => $contract['end_date'],
                'probation_end_date' => $new_probation_end,
            ], fn($v) => $v !== null),
        ]);
        logActivity($pdo, $_SESSION['user_id'], 'Activate employee contract',
            "activated {$contract['contract_type']} contract for \"$emp_name\"" . ($old ? " (renewal)" : ''));

        $message = 'Contract activated' . ($old ? ' — previous contract marked as renewed' : '');

    } else { // terminate
        if ($contract['status'] !== 'active') {
            throw new Exception('Only an active contract can be terminated — this one is ' . $contract['status']);
        }

        $pdo->prepare("UPDATE employee_contracts SET status = 'terminated', updated_by = ? WHERE contract_id = ?")
            ->execute([$_SESSION['user_id'], $contract_id]);

        logAudit($pdo, $_SESSION['user_id'], 'terminate', [
            'activity_type' => 'status_change',
            'entity_type'   => 'employee_contract',
            'entity_id'     => $contract_id,
            'description'   => "Terminated {$contract['contract_type']} contract for $emp_name",
            'old_values'    => ['status' => 'active'],
            'new_values'    => ['status' => 'terminated'],
        ]);
        logActivity($pdo, $_SESSION['user_id'], 'Terminate employee contract',
            "terminated {$contract['contract_type']} contract for \"$emp_name\"");

        $message = 'Contract terminated';

        // Cascade: if the employee has no other draft/active contract left,
        // they have no live employment contract — deactivate them so
        // attendance/payroll/leave/Operations (which all key off
        // employees.status) stop treating them as employed. Skip if a
        // renewal is already in flight (another draft/active contract exists).
        $remaining = $pdo->prepare("SELECT COUNT(*) FROM employee_contracts
                                     WHERE employee_id = ? AND status IN ('draft', 'active') AND contract_id != ?");
        $remaining->execute([(int)$contract['employee_id'], $contract_id]);
        $has_other_contract = (int)$remaining->fetchColumn() > 0;

        if (!$has_other_contract) {
            $emp_status_stmt = $pdo->prepare("SELECT status FROM employees WHERE employee_id = ?");
            $emp_status_stmt->execute([(int)$contract['employee_id']]);
            if ($emp_status_stmt->fetchColumn() === 'active') {
                $change = inactivateEmployee(
                    $pdo, (int)$contract['employee_id'], (int)$_SESSION['user_id'], 'terminated',
                    "Contract #$contract_id terminated — no remaining active/draft contract"
                );
                logAudit($pdo, $_SESSION['user_id'], 'update_status', [
                    'activity_type' => 'status_change',
                    'entity_type'   => 'employee',
                    'entity_id'     => (int)$contract['employee_id'],
                    'description'   => "Employee deactivated — contract #$contract_id terminated with no remaining contract",
                    'old_values'    => $change['old'],
                    'new_values'    => $change['new'],
                ]);
                logActivity($pdo, $_SESSION['user_id'], 'Deactivate employee',
                    "deactivated \"$emp_name\" — contract #$contract_id terminated, no remaining contract");

                $message .= ' — employee marked inactive (no remaining contract)';
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
