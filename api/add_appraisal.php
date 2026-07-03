<?php
// API: Create an employee appraisal (Tier 3, Phase 3.3).
// Snapshots the employee's designation (D19) and, per rated indicator, the
// designation's expected_rating target at creation time — later target or
// designation changes never rewrite this appraisal. Saves as draft or submitted.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('hr_performance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create appraisals']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $cycle_id    = intval($_POST['cycle_id'] ?? 0);
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $appraisal_date = trim($_POST['appraisal_date'] ?? date('Y-m-d'));
    $remarks     = trim($_POST['remarks'] ?? '');
    $ratings     = $_POST['rating'] ?? [];    // [indicator_id => 1..5]
    $comments    = $_POST['comment'] ?? [];   // [indicator_id => text]
    $mode        = ($_POST['mode'] ?? 'draft') === 'submit' ? 'submitted' : 'draft';

    if (!$cycle_id) throw new Exception('Cycle is required');
    if (!$employee_id) throw new Exception('Employee is required');
    if (!strtotime($appraisal_date)) throw new Exception('A valid appraisal date is required');
    if (!is_array($ratings) || !count(array_filter($ratings, fn($v) => (int)$v >= 1 && (int)$v <= 5))) {
        throw new Exception('Rate at least one indicator (1–5)');
    }

    // Scope gate — the employee must be in the caller's scope
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);

    // Cycle must exist and be open
    $cyc = $pdo->prepare("SELECT status FROM appraisal_cycles WHERE cycle_id = ? AND status != 'deleted'");
    $cyc->execute([$cycle_id]);
    $cstatus = $cyc->fetchColumn();
    if ($cstatus === false) throw new Exception('Cycle not found');
    if ($cstatus === 'closed') throw new Exception('That cycle is closed — no new appraisals can be added to it');

    // Employee must exist; snapshot designation
    $emp = $pdo->prepare("SELECT first_name, last_name, designation_id FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
    $emp->execute([$employee_id]);
    $employee = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$employee) throw new Exception('Employee not found');
    $emp_name = trim($employee['first_name'] . ' ' . $employee['last_name']);
    $designation_id = $employee['designation_id'] !== null ? (int)$employee['designation_id'] : null;

    // Not a duplicate for this cycle (uniq_cycle_emp, excluding soft-deleted)
    $dup = $pdo->prepare("SELECT appraisal_id FROM employee_appraisals WHERE cycle_id = ? AND employee_id = ? AND status != 'deleted'");
    $dup->execute([$cycle_id, $employee_id]);
    if ($dup->fetch()) throw new Exception('An appraisal for this employee already exists in this cycle');

    // Expected-rating snapshot from the designation's targets (may be absent)
    $targets = [];
    if ($designation_id) {
        $ts = $pdo->prepare("SELECT indicator_id, expected_rating FROM designation_indicator_targets WHERE designation_id = ?");
        $ts->execute([$designation_id]);
        foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) $targets[(int)$t['indicator_id']] = (int)$t['expected_rating'];
    }

    // Validate the rated indicators are real + active
    $validInd = $pdo->query("SELECT indicator_id FROM performance_indicators WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    $validInd = array_map('intval', $validInd);

    $pdo->beginTransaction();

    $pdo->prepare("
        INSERT INTO employee_appraisals (cycle_id, employee_id, designation_id, appraisal_date, remarks, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$cycle_id, $employee_id, $designation_id, $appraisal_date, ($remarks !== '' ? $remarks : null), $mode, $_SESSION['user_id']]);
    $appraisal_id = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO employee_appraisal_items (appraisal_id, indicator_id, expected_rating, actual_rating, comment) VALUES (?, ?, ?, ?, ?)");
    $itemCount = 0;
    foreach ($ratings as $ind => $val) {
        $ind = (int)$ind; $val = (int)$val;
        if ($val < 1 || $val > 5) continue;
        if (!in_array($ind, $validInd, true)) continue;
        $expected = $targets[$ind] ?? null;
        $cmt = trim((string)($comments[$ind] ?? ''));
        $ins->execute([$appraisal_id, $ind, $expected, $val, ($cmt !== '' ? $cmt : null)]);
        $itemCount++;
    }
    if ($itemCount === 0) throw new Exception('No valid indicators were rated');

    logActivity($pdo, $_SESSION['user_id'], 'Add appraisal', "created appraisal for \"$emp_name\" ($itemCount item(s), $mode)");
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type'   => 'employee_appraisal',
        'entity_id'     => $appraisal_id,
        'description'   => "Created appraisal for $emp_name (cycle #$cycle_id, $mode)",
        'new_values'    => ['cycle_id' => $cycle_id, 'employee_id' => $employee_id, 'items' => $itemCount, 'status' => $mode],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Appraisal ' . ($mode === 'submitted' ? 'submitted' : 'saved as draft'), 'appraisal_id' => $appraisal_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
