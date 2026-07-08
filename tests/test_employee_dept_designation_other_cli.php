<?php
/**
 * test_employee_dept_designation_other_cli.php
 * Verifies the self-growing "Other (specify)" Department/Designation on the
 * employee wizard (Step 2) + the Department→Designation cascade wiring.
 *
 * Backend: exercises resolveEmployeeDeptDesignation() — the exact code path
 * add_employee.php / update_employee.php call — creating real rows, asserting
 * the new designation is linked to the (new) department, and that re-resolving
 * the same names is idempotent (no duplicates). Self-cleans its test rows.
 * Frontend: source-guards the markup/JS so the cascade + Other option can't be
 * silently removed.
 */
$root = dirname(__DIR__);
require_once $root . '/roots.php';
require_once $root . '/core/hr_lookups.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($m){ global $pass; $pass++; echo "  [PASS] $m\n"; }
function no($m){ global $fail; $fail++; echo "  [FAIL] $m\n"; }
function section($t){ echo "\n== $t ==\n"; }

$mkDept = 'ZZ_DEPT_' . substr(md5(uniqid()), 0, 8);
$mkDesig = 'ZZ_DESIG_' . substr(md5(uniqid()), 0, 8);
$createdDeptId = null; $createdDesigId = null;

// ── 1. findOrCreateDepartment: creates + idempotent ──────────────────────
section('1. findOrCreateDepartment');
$createdDeptId = findOrCreateDepartment($pdo, $mkDept, 1);
($createdDeptId > 0) ? ok("created department id=$createdDeptId") : no('department not created');
(findOrCreateDepartment($pdo, $mkDept, 1) === $createdDeptId) ? ok('re-resolve returns same id (idempotent)') : no('duplicate department created');
(findOrCreateDepartment($pdo, strtoupper($mkDept), 1) === $createdDeptId) ? ok('case-insensitive match') : no('case-insensitive match failed');
(findOrCreateDepartment($pdo, '  ', 1) === null) ? ok('blank name → null') : no('blank name not handled');

// ── 2. findOrCreateDesignation: created under the department ──────────────
section('2. findOrCreateDesignation (linked to department)');
$createdDesigId = findOrCreateDesignation($pdo, $mkDesig, $createdDeptId, 1);
($createdDesigId > 0) ? ok("created designation id=$createdDesigId") : no('designation not created');
$link = $pdo->prepare("SELECT department_id FROM designations WHERE designation_id = ?");
$link->execute([$createdDesigId]);
((int)$link->fetchColumn() === $createdDeptId) ? ok('designation linked to the new department') : no('designation not linked to department');
(findOrCreateDesignation($pdo, $mkDesig, $createdDeptId, 1) === $createdDesigId) ? ok('re-resolve returns same id (idempotent)') : no('duplicate designation created');

// ── 3. resolveEmployeeDeptDesignation: swaps the 'other' sentinels ───────
section('3. resolveEmployeeDeptDesignation swaps sentinels');
$dept2 = 'ZZ_DEPT2_' . substr(md5(uniqid()), 0, 8);
$desig2 = 'ZZ_DESIG2_' . substr(md5(uniqid()), 0, 8);
$post = ['department_id' => 'other', 'department_other' => $dept2,
         'designation_id' => 'other', 'designation_other' => $desig2];
resolveEmployeeDeptDesignation($pdo, $post, 1);
(is_numeric($post['department_id']) && $post['department_id'] > 0) ? ok('department_id resolved to a real id') : no('department_id not resolved');
(is_numeric($post['designation_id']) && $post['designation_id'] > 0) ? ok('designation_id resolved to a real id') : no('designation_id not resolved');
$chk = $pdo->prepare("SELECT department_id FROM designations WHERE designation_id = ?");
$chk->execute([$post['designation_id']]);
((int)$chk->fetchColumn() === (int)$post['department_id']) ? ok('new designation linked to the new department') : no('resolved designation not linked to resolved department');

// existing-id passthrough (no sentinel) leaves values untouched
$post3 = ['department_id' => (string)$createdDeptId, 'designation_id' => (string)$createdDesigId];
resolveEmployeeDeptDesignation($pdo, $post3, 1);
($post3['department_id'] == $createdDeptId && $post3['designation_id'] == $createdDesigId) ? ok('existing ids pass through unchanged') : no('existing ids were altered');

// 'other' with empty text → throws
try {
    $bad = ['department_id' => 'other', 'department_other' => ''];
    resolveEmployeeDeptDesignation($pdo, $bad, 1);
    no('empty other text should have thrown');
} catch (Exception $e) { ok('empty other text is rejected'); }

// ── 4. Frontend source guards ────────────────────────────────────────────
section('4. Frontend markup + JS');
$src = file_get_contents($root . '/app/bms/pos/employees.php');
(strpos($src, 'id="department_other"') !== false) ? ok('department "Other" input present') : no('department Other input missing');
(strpos($src, 'id="designation_other"') !== false) ? ok('designation "Other" input present') : no('designation Other input missing');
(strpos($src, 'data-department-id="') !== false) ? ok('designation options carry data-department-id (cascade)') : no('cascade data attribute missing');
(strpos($src, 'function rebuildDesignationOptions') !== false) ? ok('rebuildDesignationOptions() present') : no('cascade JS missing');
(strpos($src, "value=\"other\">➕ Other (specify)") !== false) ? ok('"Other (specify)" option present') : no('Other option markup missing');

// ── cleanup ──────────────────────────────────────────────────────────────
section('cleanup');
$pdo->prepare("DELETE FROM designations WHERE designation_name IN (?, ?)")->execute([$mkDesig, $desig2]);
$pdo->prepare("DELETE FROM departments  WHERE department_name  IN (?, ?)")->execute([$mkDept, $dept2]);
echo "  (removed test departments/designations)\n";

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
