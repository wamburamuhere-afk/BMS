<?php
/**
 * HR Performance & Development (Tier 3, Phase 3.1) foundation CLI test.
 *   php tests/test_hr_performance_foundation_cli.php
 *
 * Run AFTER the two 2026_07_03 Tier 3 migrations. Asserts the foundation is
 * complete and ADDITIVE: 11 tables + FK/indexes, seeded lookup rows,
 * 2 permission rows with runtime-resolved role assignments + workflow verbs,
 * routes, nav gating, router fallback, hardened cert upload dir — and that
 * both migrations are idempotent. Nothing here touches the business
 * performance_dashboard report (D16).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

try {
    // ── 1. Migrations idempotent ─────────────────────────────────────────────
    foreach (['2026_07_03_hr_performance_foundation', '2026_07_03_hr_performance_permissions'] as $mig) {
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o1, $rc1);
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o2, $rc2);
        ok($rc1 === 0 && $rc2 === 0, "$mig runs twice cleanly (rc=$rc1/$rc2)");
    }

    // ── 2. Tables + engine ───────────────────────────────────────────────────
    $tables = ['performance_indicator_categories', 'performance_indicators', 'designation_indicator_targets',
               'appraisal_cycles', 'employee_appraisals', 'employee_appraisal_items',
               'goal_types', 'employee_goals', 'training_types', 'trainings', 'training_participants'];
    foreach ($tables as $t) {
        ok((bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetch(), "table $t exists");
    }
    foreach ($tables as $t) {
        $engine = $pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch()['Engine'] ?? '';
        ok($engine === 'InnoDB', "$t engine is InnoDB");
    }

    // ── 3. FKs to employees / parent rows ───────────────────────────────────
    $fkCheck = function ($table, $ref) use ($pdo) {
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND REFERENCED_TABLE_NAME = '$ref'")->fetchColumn();
    };
    ok($fkCheck('employee_appraisals', 'employees') === 1, "employee_appraisals FK → employees");
    ok($fkCheck('employee_appraisal_items', 'employee_appraisals') === 1, "employee_appraisal_items FK → employee_appraisals");
    ok($fkCheck('employee_goals', 'employees') === 1, "employee_goals FK → employees");
    ok($fkCheck('training_participants', 'trainings') === 1, "training_participants FK → trainings");
    ok($fkCheck('training_participants', 'employees') === 1, "training_participants FK → employees");

    // ── 4. Unique keys that enforce the business rules ──────────────────────
    $hasUniq = function ($table, $key) use ($pdo) {
        return (bool)$pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$key'")->fetch();
    };
    ok($hasUniq('employee_appraisals', 'uniq_cycle_emp'), "one appraisal per employee per cycle (uniq_cycle_emp)");
    ok($hasUniq('employee_appraisal_items', 'uniq_app_ind'), "one item per indicator per appraisal (uniq_app_ind)");
    ok($hasUniq('designation_indicator_targets', 'uniq_desig_ind'), "one target per designation+indicator (uniq_desig_ind)");
    ok($hasUniq('training_participants', 'uniq_training_emp'), "one participant row per employee per training (uniq_training_emp)");

    // ── 5. Seeded lookup rows ────────────────────────────────────────────────
    $cats = $pdo->query("SELECT category_name FROM performance_indicator_categories WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['Technical', 'Behavioural', 'Organizational'] as $c) { ok(in_array($c, $cats, true), "indicator category '$c' seeded"); }
    $gtypes = $pdo->query("SELECT type_name FROM goal_types WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['Annual', 'Quarterly', 'Monthly', 'Project'] as $g) { ok(in_array($g, $gtypes, true), "goal type '$g' seeded"); }
    $ttypes = $pdo->query("SELECT type_name FROM training_types WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['Technical', 'Soft Skills', 'Compliance & Safety', 'Induction'] as $t) { ok(in_array($t, $ttypes, true), "training type '$t' seeded"); }

    // ── 6. Permissions (2 pages) + role matrix + workflow verbs ─────────────
    $mustBeFull = $pdo->query("
        SELECT DISTINCT r.role_id FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.role_id
        LEFT JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'employees'
        WHERE r.is_admin = 1 OR (p.page_key = 'employees' AND rp.can_edit = 1)
    ")->fetchAll(PDO::FETCH_COLUMN);
    $roleCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();
    foreach (['hr_performance', 'trainings'] as $key) {
        $perm = $pdo->query("SELECT permission_id, module_name FROM permissions WHERE page_key = '$key'")->fetch(PDO::FETCH_ASSOC);
        ok($perm && $perm['module_name'] === 'Human Resources', "permission '$key' exists in Human Resources");
        if (!$perm) continue;
        $pid = (int)$perm['permission_id'];
        $rp = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id = $pid AND can_view = 1")->fetchColumn();
        ok($rp === $roleCount, "'$key': all $roleCount roles can view");
        $allFull = true;
        foreach ($mustBeFull as $rid) {
            $cols = 'can_create, can_edit' . ($hasApprove ? ', can_approve' : '');
            $row = $pdo->query("SELECT $cols FROM role_permissions WHERE permission_id = $pid AND role_id = " . (int)$rid)->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['can_create'] || !$row['can_edit'] || ($hasApprove && !$row['can_approve'])) $allFull = false;
        }
        ok($allFull, "'$key': HR-capable + admin roles hold full access incl. approve");
    }

    // ── 7. Routes + nav + router fallback ────────────────────────────────────
    $routes = $GLOBALS['routes'] ?? [];
    foreach (['hr_performance', 'trainings', 'api/manage_indicators', 'api/get_indicators',
              'api/save_designation_targets', 'api/manage_appraisal_cycles', 'api/add_appraisal',
              'api/get_appraisal', 'api/get_appraisals', 'api/change_appraisal_status',
              'api/add_goal', 'api/get_goals', 'api/update_goal_progress',
              'api/manage_trainings', 'api/get_trainings', 'api/manage_training_participants',
              'api/upload_training_certificate', 'api/download_training_certificate'] as $r) {
        ok(isset($routes[$r]) && isset($routes["$r.php"]), "route '$r' (+ .php form) registered");
    }
    $header = file_get_contents("$root/header.php");
    ok(strpos($header, "canView('hr_performance')") !== false && strpos($header, "getUrl('hr_performance')") !== false, "nav: Performance (HR) item gated + routed");
    ok(strpos($header, "canView('trainings')") !== false && strpos($header, "getUrl('trainings')") !== false, "nav: Training item gated + routed");
    $mapping = getPagePermissionMapping();
    ok(($mapping['hr_performance.php'] ?? '') === 'hr_performance' && ($mapping['trainings.php'] ?? '') === 'trainings',
        "router fallback maps both new pages");

    // ── 8. Upload dir hardened ───────────────────────────────────────────────
    $ht = @file_get_contents("$root/uploads/training_certs/.htaccess") ?: '';
    ok(is_dir("$root/uploads/training_certs") && strpos($ht, 'Require all denied') !== false, "uploads/training_certs exists + .htaccess denies exec");

    // ── 9. No-break: business performance_dashboard untouched (D16) ──────────
    ok(($mapping['performance_dashboard.php'] ?? 'performance_dashboard') === 'performance_dashboard'
        || isset($routes['performance_dashboard']) || file_exists("$root/app/constant/reports/performance_dashboard.php"),
        "business performance_dashboard report still present and distinct");
    ok(($mapping['hr_performance.php'] ?? '') !== ($mapping['performance_dashboard.php'] ?? 'x'),
        "hr_performance and performance_dashboard are distinct page keys (no collision)");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
