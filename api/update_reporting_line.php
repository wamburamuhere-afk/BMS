<?php
// API: Update Reporting Line (Tier 2, Phase 2.4 — D14 dual-write, D15 cycle guard)
// Sets employees.reporting_to_id and dual-writes the manager's full name into
// the legacy reporting_to varchar so all existing readers keep working.
// Rejects an assignment where the chosen manager is the employee themself or
// any descendant in the reporting chain (would create a cycle that hangs any
// org-chart/rollup traversal) — walk up reporting_to_id, depth-capped.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canEdit('employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit employees']);
    exit;
}

// 3. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 4. CSRF + input validation
csrf_check();

$employee_id = intval($_POST['employee_id'] ?? 0);
$manager_id  = ($_POST['manager_id'] ?? '') !== '' ? intval($_POST['manager_id']) : null;

if (!$employee_id) {
    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
    exit;
}

// Project-scope gate
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('employees', 'employee_id', $employee_id);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT employee_id, reporting_to_id, first_name, last_name FROM employees
                            WHERE employee_id = ? AND (status IS NULL OR status != 'deleted') FOR UPDATE");
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) throw new Exception('Employee not found');
    $emp_name = trim($emp['first_name'] . ' ' . $emp['last_name']);
    $old_manager_id = $emp['reporting_to_id'] !== null ? (int)$emp['reporting_to_id'] : null;

    $new_manager_name = null;
    if ($manager_id !== null) {
        if ($manager_id === $employee_id) {
            throw new Exception('An employee cannot report to themselves');
        }

        // D15 cycle guard — walk up the chain from the proposed manager; if we
        // ever reach $employee_id, this employee is an ancestor of the manager
        // and the assignment would create a cycle.
        $cursor = $manager_id;
        $depth = 0;
        $seen = [];
        while ($cursor !== null && $depth < 500) {
            if ($cursor === $employee_id) {
                throw new Exception('This assignment would create a reporting cycle');
            }
            if (isset($seen[$cursor])) break; // pre-existing cycle elsewhere — stop, not ours to fix here
            $seen[$cursor] = true;
            $row = $pdo->prepare("SELECT reporting_to_id FROM employees WHERE employee_id = ?");
            $row->execute([$cursor]);
            $next = $row->fetchColumn();
            $cursor = ($next === false || $next === null) ? null : (int)$next;
            $depth++;
        }

        $mgrStmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ? AND status = 'active'");
        $mgrStmt->execute([$manager_id]);
        $mgrRow = $mgrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mgrRow) throw new Exception('Selected manager does not exist');
        $new_manager_name = trim($mgrRow['first_name'] . ' ' . $mgrRow['last_name']);
    }

    $pdo->prepare("UPDATE employees SET reporting_to_id = ?, reporting_to = ?, updated_by = ? WHERE employee_id = ?")
        ->execute([$manager_id, $new_manager_name, $_SESSION['user_id'], $employee_id]);

    logActivity($pdo, $_SESSION['user_id'], 'Update reporting line',
        "set manager for \"$emp_name\" to " . ($new_manager_name ?? 'none'));
    logAudit($pdo, $_SESSION['user_id'], 'update', [
        'activity_type' => 'update',
        'entity_type'   => 'employee',
        'entity_id'     => $employee_id,
        'description'   => "Reporting line changed for $emp_name",
        'old_values'    => ['reporting_to_id' => $old_manager_id],
        'new_values'    => ['reporting_to_id' => $manager_id, 'reporting_to' => $new_manager_name],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Reporting line updated']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
