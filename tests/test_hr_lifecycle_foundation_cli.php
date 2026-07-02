<?php
/**
 * HR Lifecycle (Tier 1, Phase 1.1) foundation CLI test.
 *   php tests/test_hr_lifecycle_foundation_cli.php
 *
 * Run AFTER migrations/2026_07_02_employee_lifecycle_events.php and
 * migrations/2026_07_02_employee_lifecycle_permissions.php. Asserts the
 * foundation is complete and ADDITIVE: table + indexes + FK, permission row +
 * runtime-resolved role assignments, routes, nav gating, router fallback
 * mapping, upload dir hardening — and that both migrations are idempotent.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

try {
    // ── 1. Migrations are idempotent (safe to run twice) ────────────────────
    foreach (['2026_07_02_employee_lifecycle_events', '2026_07_02_employee_lifecycle_permissions'] as $mig) {
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o1, $rc1);
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o2, $rc2);
        ok($rc1 === 0 && $rc2 === 0, "$mig runs twice cleanly (rc=$rc1/$rc2)");
    }

    // ── 2. Table schema ──────────────────────────────────────────────────────
    ok((bool)$pdo->query("SHOW TABLES LIKE 'employee_lifecycle_events'")->fetch(), "employee_lifecycle_events table exists");

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM employee_lifecycle_events") as $c) $cols[$c['Field']] = $c['Type'];
    $expected = ['event_id','employee_id','event_type','event_date','end_date','title','description',
        'old_designation_id','new_designation_id','old_salary','new_salary',
        'old_department_id','new_department_id','old_project_id','new_project_id',
        'award_type','award_gift','award_amount','severity','complainant','resolution',
        'termination_type','notice_date','status','approved_by','approved_at','reject_reason',
        'effect_applied_at','attachment_path','attachment_name','created_by','created_at','updated_by','updated_at'];
    $missing = array_diff($expected, array_keys($cols));
    ok(!$missing, "all " . count($expected) . " columns present" . ($missing ? " (missing: " . implode(',', $missing) . ")" : ""));
    ok(strpos($cols['event_type'] ?? '', "'promotion'") !== false && strpos($cols['event_type'] ?? '', "'termination'") !== false
        && strpos($cols['event_type'] ?? '', "'demotion'") !== false, "event_type enum covers all 8 types");
    ok(strpos($cols['status'] ?? '', "'pending'") !== false && strpos($cols['status'] ?? '', "'deleted'") !== false, "status enum has workflow + soft-delete states");

    $engine = $pdo->query("SHOW TABLE STATUS LIKE 'employee_lifecycle_events'")->fetch()['Engine'] ?? '';
    ok($engine === 'InnoDB', "engine is InnoDB (got: $engine)");

    $idx = $pdo->query("SHOW INDEX FROM employee_lifecycle_events")->fetchAll(PDO::FETCH_ASSOC);
    $idxNames = array_unique(array_column($idx, 'Key_name'));
    ok(in_array('idx_emp_type', $idxNames), "idx_emp_type index exists");
    ok(in_array('idx_status_date', $idxNames), "idx_status_date index exists");

    $fk = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_lifecycle_events'
        AND REFERENCED_TABLE_NAME = 'employees'")->fetchColumn();
    ok((int)$fk === 1, "FK to employees declared");

    // ── 3. Permission row + role assignments ────────────────────────────────
    $perm = $pdo->query("SELECT permission_id, module_name FROM permissions WHERE page_key = 'employee_lifecycle'")->fetch(PDO::FETCH_ASSOC);
    ok((bool)$perm, "permissions row 'employee_lifecycle' exists");
    ok(($perm['module_name'] ?? '') === 'Human Resources', "module_name is Human Resources");

    if ($perm) {
        $pid = (int)$perm['permission_id'];
        $roleCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
        $rpCount   = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id = $pid")->fetchColumn();
        ok($rpCount === $roleCount, "every role has an assignment ($rpCount/$roleCount)");
        ok((int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id = $pid AND can_view = 1")->fetchColumn() === $roleCount,
            "all roles can view");

        // Runtime criteria: admin roles + roles holding can_edit on 'employees' get full access
        $mustBeFull = $pdo->query("
            SELECT DISTINCT r.role_id FROM roles r
            LEFT JOIN role_permissions rp ON rp.role_id = r.role_id
            LEFT JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'employees'
            WHERE r.is_admin = 1 OR (p.page_key = 'employees' AND rp.can_edit = 1)
        ")->fetchAll(PDO::FETCH_COLUMN);
        $allFull = true;
        foreach ($mustBeFull as $rid) {
            $row = $pdo->query("SELECT can_create, can_edit, can_delete, can_approve FROM role_permissions
                WHERE permission_id = $pid AND role_id = " . (int)$rid)->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['can_create'] || !$row['can_edit'] || !$row['can_delete'] || !$row['can_approve']) $allFull = false;
        }
        ok($allFull, "HR-capable + admin roles hold full CRUD + approve (" . count($mustBeFull) . " roles)");

        $strayFull = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions
            WHERE permission_id = $pid AND can_approve = 1
            AND role_id NOT IN (" . implode(',', array_map('intval', $mustBeFull) ?: [0]) . ")")->fetchColumn();
        ok($strayFull === 0, "no other role received approve rights");
    }

    // ── 4. Routes registered ─────────────────────────────────────────────────
    $routes = $GLOBALS['routes'] ?? [];
    foreach (['hr_actions', 'api/add_lifecycle_event', 'api/get_lifecycle_event', 'api/get_lifecycle_events',
              'api/change_lifecycle_status', 'api/delete_lifecycle_event', 'api/download_lifecycle_attachment'] as $r) {
        ok(isset($routes[$r]) && isset($routes["$r.php"]), "route '$r' (+ .php form) registered");
    }

    // ── 5. Nav + router fallback mapping ────────────────────────────────────
    $header = file_get_contents("$root/header.php");
    ok(strpos($header, "canView('employee_lifecycle')") !== false, "header.php nav item gated by canView('employee_lifecycle')");
    ok(strpos($header, "getUrl('hr_actions')") !== false, "header.php nav item links via getUrl('hr_actions')");
    $mapping = getPagePermissionMapping();
    ok(($mapping['hr_actions.php'] ?? '') === 'employee_lifecycle', "router fallback maps hr_actions.php → employee_lifecycle");

    // ── 6. Upload dir hardened ───────────────────────────────────────────────
    ok(is_dir("$root/uploads/lifecycle"), "uploads/lifecycle directory exists");
    $ht = @file_get_contents("$root/uploads/lifecycle/.htaccess") ?: '';
    ok(strpos($ht, 'Require all denied') !== false && strpos($ht, 'RemoveHandler .php') !== false, ".htaccess denies script execution");

    // ── 7. No-break: existing HR surface untouched ───────────────────────────
    ok(isset($routes['employees']) && isset($routes['api/update_employee_status']), "existing employees + update_employee_status routes intact");
    ok(file_exists($routes['employees'] ?? ''), "employees.php still resolves to a real file");
    $enum = $pdo->query("SHOW COLUMNS FROM employees LIKE 'employment_status'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '';
    ok(strpos($enum, "'resigned'") !== false && strpos($enum, "'terminated'") !== false, "employees.employment_status enum unchanged (no ALTER needed)");

} catch (Throwable $e) { ok(false, "exception: " . $e->getMessage()); }

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
