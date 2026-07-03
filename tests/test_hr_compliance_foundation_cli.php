<?php
/**
 * HR Compliance (Tier 2, Phase 2.1) foundation CLI test.
 *   php tests/test_hr_compliance_foundation_cli.php
 *
 * Run AFTER the three 2026_07_02 Tier 2 migrations. Asserts the foundation is
 * complete and ADDITIVE: three tables + FK/indexes, seeded document types,
 * reporting_to_id column, safe name backfill (exact-unique only, proven with
 * fixtures), 4 permission rows with runtime-resolved role assignments,
 * notification_events rows, routes, nav gating, router fallback, hardened
 * upload dirs — and that all three migrations are idempotent.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$fix = [];   // fixture employee ids
try {
    // ── 1. Migrations idempotent ─────────────────────────────────────────────
    foreach (['2026_07_02_hr_compliance_foundation', '2026_07_02_hr_compliance_permissions', '2026_07_02_hr_compliance_reporting_backfill'] as $mig) {
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o1, $rc1);
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o2, $rc2);
        ok($rc1 === 0 && $rc2 === 0, "$mig runs twice cleanly (rc=$rc1/$rc2)");
    }

    // ── 2. Tables + engine + FK ──────────────────────────────────────────────
    foreach (['employee_document_types', 'employee_documents', 'employee_contracts'] as $t) {
        ok((bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetch(), "table $t exists");
        $engine = $pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch()['Engine'] ?? '';
        ok($engine === 'InnoDB', "$t engine is InnoDB");
    }
    foreach (['employee_documents', 'employee_contracts'] as $t) {
        $fk = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND REFERENCED_TABLE_NAME = 'employees'")->fetchColumn();
        ok((int)$fk === 1, "$t has FK to employees");
    }

    // ── 3. Seeded document types (D11) ───────────────────────────────────────
    $types = $pdo->query("SELECT type_name, requires_expiry FROM employee_document_types WHERE status='active'")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(count($types) >= 8, "8 document types seeded (found " . count($types) . ")");
    foreach (['Employment Contract', 'Work Permit', 'Professional License'] as $t) {
        ok((int)($types[$t] ?? 0) === 1, "'$t' requires expiry");
    }
    ok((int)($types['CV/Resume'] ?? 1) === 0, "'CV/Resume' does not require expiry");

    // ── 4. reporting_to_id column + legacy column untouched ─────────────────
    $col = $pdo->query("SHOW COLUMNS FROM employees LIKE 'reporting_to_id'")->fetch(PDO::FETCH_ASSOC);
    ok($col && stripos($col['Type'], 'int') !== false && $col['Null'] === 'YES', "employees.reporting_to_id INT NULL added");
    $legacy = $pdo->query("SHOW COLUMNS FROM employees LIKE 'reporting_to'")->fetch(PDO::FETCH_ASSOC);
    ok($legacy && stripos($legacy['Type'], 'varchar') !== false, "legacy employees.reporting_to varchar untouched");

    // ── 5. Backfill safety, proven with fixtures ─────────────────────────────
    // unique-match case: one manager 'Zz Unique', one report naming them exactly
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('Zzq', 'Uniqmgr', '__T2-MGR', 'active', NOW())");
    $mgr = (int)$pdo->lastInsertId(); $fix[] = $mgr;
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, reporting_to, created_at)
                VALUES ('Zzq', 'Report1', '__T2-R1', 'active', 'Zzq Uniqmgr', NOW())");
    $r1 = (int)$pdo->lastInsertId(); $fix[] = $r1;
    // ambiguous case: two employees share a name; a report names that name
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('Zzq', 'Dupmgr', '__T2-D1', 'active', NOW())");
    $fix[] = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('Zzq', 'Dupmgr', '__T2-D2', 'active', NOW())");
    $fix[] = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, reporting_to, created_at)
                VALUES ('Zzq', 'Report2', '__T2-R2', 'active', 'Zzq Dupmgr', NOW())");
    $r2 = (int)$pdo->lastInsertId(); $fix[] = $r2;

    exec("php " . escapeshellarg("$root/migrations/2026_07_02_hr_compliance_reporting_backfill.php") . " 2>&1", $bo, $brc);
    ok($brc === 0, "backfill re-run over fixtures succeeds");
    $v1 = $pdo->query("SELECT reporting_to_id FROM employees WHERE employee_id = $r1")->fetchColumn();
    ok((int)$v1 === $mgr, "unique full-name match backfilled correctly");
    $v2 = $pdo->query("SELECT reporting_to_id FROM employees WHERE employee_id = $r2")->fetchColumn();
    ok($v2 === null, "ambiguous name left untouched (two 'Zzq Dupmgr' exist)");
    $legacyVal = $pdo->query("SELECT reporting_to FROM employees WHERE employee_id = $r1")->fetchColumn();
    ok($legacyVal === 'Zzq Uniqmgr', "legacy varchar not modified by backfill");

    // ── 6. Permissions (4 pages) + role matrix ───────────────────────────────
    $mustBeFull = $pdo->query("
        SELECT DISTINCT r.role_id FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.role_id
        LEFT JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'employees'
        WHERE r.is_admin = 1 OR (p.page_key = 'employees' AND rp.can_edit = 1)
    ")->fetchAll(PDO::FETCH_COLUMN);
    $roleCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    foreach (['employee_documents', 'employee_contracts', 'org_chart', 'hr_expiry_alerts'] as $key) {
        $perm = $pdo->query("SELECT permission_id, module_name FROM permissions WHERE page_key = '$key'")->fetch(PDO::FETCH_ASSOC);
        ok($perm && $perm['module_name'] === 'Human Resources', "permission '$key' exists in Human Resources");
        if (!$perm) continue;
        $pid = (int)$perm['permission_id'];
        $rp = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id = $pid AND can_view = 1")->fetchColumn();
        ok($rp === $roleCount, "'$key': all $roleCount roles can view");
        $allFull = true;
        foreach ($mustBeFull as $rid) {
            $row = $pdo->query("SELECT can_create, can_edit FROM role_permissions WHERE permission_id = $pid AND role_id = " . (int)$rid)->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['can_create'] || !$row['can_edit']) $allFull = false;
        }
        ok($allFull, "'$key': HR-capable + admin roles hold full access");
    }

    // ── 7. Notification events registered ───────────────────────────────────
    foreach (['hr_contract_expiry', 'hr_probation_end'] as $ek) {
        $ev = $pdo->query("SELECT page_key, is_active FROM notification_events WHERE event_key = '$ek'")->fetch(PDO::FETCH_ASSOC);
        ok($ev && $ev['page_key'] === 'hr_expiry_alerts' && (int)$ev['is_active'] === 1, "notification event '$ek' registered → hr_expiry_alerts");
    }

    // ── 8. Routes + nav + router fallback ────────────────────────────────────
    $routes = $GLOBALS['routes'] ?? [];
    foreach (['employee_contracts', 'org_chart', 'api/add_employee_document', 'api/get_employee_documents',
              'api/delete_employee_document', 'api/download_employee_document', 'api/manage_document_types',
              'api/add_contract', 'api/get_contract', 'api/get_contracts', 'api/change_contract_status',
              'api/get_org_chart', 'api/update_reporting_line'] as $r) {
        ok(isset($routes[$r]) && isset($routes["$r.php"]), "route '$r' (+ .php form) registered");
    }
    $header = file_get_contents("$root/header.php");
    ok(strpos($header, "canView('employee_contracts')") !== false && strpos($header, "getUrl('employee_contracts')") !== false, "nav: Contracts item gated + routed");
    ok(strpos($header, "canView('org_chart')") !== false && strpos($header, "getUrl('org_chart')") !== false, "nav: Org Chart item gated + routed");
    $mapping = getPagePermissionMapping();
    ok(($mapping['employee_contracts.php'] ?? '') === 'employee_contracts' && ($mapping['org_chart.php'] ?? '') === 'org_chart',
        "router fallback maps both new pages");

    // ── 9. Upload dirs hardened ──────────────────────────────────────────────
    foreach (['employee_docs', 'contracts'] as $d) {
        $ht = @file_get_contents("$root/uploads/$d/.htaccess") ?: '';
        ok(is_dir("$root/uploads/$d") && strpos($ht, 'Require all denied') !== false, "uploads/$d exists + .htaccess denies exec");
    }

    // ── 10. No-break ─────────────────────────────────────────────────────────
    ok(isset($routes['employees']) && isset($routes['hr_actions']), "existing employees + hr_actions routes intact");
    $cnt = $pdo->query("SHOW COLUMNS FROM employees LIKE 'contract_end_date'")->fetch();
    ok((bool)$cnt, "employees.contract_end_date untouched (readers keep working)");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($fix) {
        $pdo->exec("DELETE FROM employees WHERE employee_id IN (" . implode(',', $fix) . ")");
    }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
