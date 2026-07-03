<?php
/**
 * HR Talent & Engagement (Tier 4, Phase 4.1) foundation CLI test.
 *   php tests/test_hr_talent_foundation_cli.php
 *
 * Run AFTER the two 2026_07_04 Tier 4 migrations. Asserts the foundation is
 * complete and ADDITIVE: 12 tables + users.employee_id, FK/indexes, seeded
 * default checklist templates, 6 permission rows (my_hr = view for all),
 * routes, nav gating, router fallback, hardened upload dirs, both migrations
 * idempotent — and that the users writers keep working with AND without the
 * new optional employee_id link (D24).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$uid_no = 0; $uid_yes = 0; $emp = 0;
try {
    // ── 1. Migrations idempotent ─────────────────────────────────────────────
    foreach (['2026_07_04_hr_talent_foundation', '2026_07_04_hr_talent_permissions'] as $mig) {
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o1, $rc1);
        exec("php " . escapeshellarg("$root/migrations/$mig.php") . " 2>&1", $o2, $rc2);
        ok($rc1 === 0 && $rc2 === 0, "$mig runs twice cleanly (rc=$rc1/$rc2)");
    }

    // ── 2. Tables + engine ───────────────────────────────────────────────────
    $tables = ['announcements','announcement_reads','meetings','meeting_attendees','employee_trips',
               'checklist_templates','checklist_template_items','employee_checklists','employee_checklist_items',
               'job_openings','candidates','candidate_interviews'];
    foreach ($tables as $t) ok((bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetch(), "table $t exists");
    foreach ($tables as $t) {
        $e = $pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch()['Engine'] ?? '';
        ok($e === 'InnoDB', "$t engine is InnoDB");
    }

    // ── 3. users.employee_id (D24) ──────────────────────────────────────────
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'employee_id'")->fetch(PDO::FETCH_ASSOC);
    ok($col && stripos($col['Type'],'int') !== false && $col['Null'] === 'YES', "users.employee_id INT NULL added");

    // ── 4. FKs ───────────────────────────────────────────────────────────────
    $fk = function ($tbl, $ref) use ($pdo) {
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND REFERENCED_TABLE_NAME='$ref'")->fetchColumn();
    };
    ok($fk('employee_trips','employees') === 1, "employee_trips FK → employees");
    ok($fk('meeting_attendees','employees') === 1, "meeting_attendees FK → employees");
    ok($fk('employee_checklists','employees') === 1, "employee_checklists FK → employees");
    ok($fk('candidates','job_openings') === 1, "candidates FK → job_openings");
    ok($fk('candidate_interviews','candidates') === 1, "candidate_interviews FK → candidates");

    // ── 5. Seeded default templates (D28) ───────────────────────────────────
    foreach ([['Default Onboarding','onboarding'], ['Default Offboarding','offboarding']] as $t) {
        $row = $pdo->query("SELECT template_id, is_default FROM checklist_templates WHERE template_name='{$t[0]}' AND template_type='{$t[1]}'")->fetch(PDO::FETCH_ASSOC);
        ok($row && (int)$row['is_default'] === 1, "default {$t[1]} template seeded + flagged is_default");
        if ($row) {
            $items = (int)$pdo->query("SELECT COUNT(*) FROM checklist_template_items WHERE template_id={$row['template_id']}")->fetchColumn();
            ok($items > 0, "  {$t[1]} template has starter items ($items)");
        }
    }

    // ── 6. Permissions (6 pages) + my_hr view-for-all ───────────────────────
    $roleCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    foreach (['announcements','meetings','employee_trips','hr_checklists','recruitment','my_hr'] as $key) {
        $perm = $pdo->query("SELECT permission_id, module_name FROM permissions WHERE page_key='$key'")->fetch(PDO::FETCH_ASSOC);
        ok($perm && $perm['module_name']==='Human Resources', "permission '$key' in Human Resources");
        if (!$perm) continue;
        $pid = (int)$perm['permission_id'];
        $viewers = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$pid AND can_view=1")->fetchColumn();
        if ($key === 'my_hr') ok($viewers === $roleCount, "'my_hr': ALL $roleCount roles can view (self-service, D24)");
        else ok($viewers >= 1, "'$key': at least the full roles can view");
    }
    ok((bool)$pdo->query("SELECT 1 FROM notification_events WHERE event_key='hr_announcement'")->fetch(), "notification event 'hr_announcement' registered");

    // ── 7. Routes + nav + router fallback ───────────────────────────────────
    $routes = $GLOBALS['routes'] ?? [];
    foreach (['announcements','meetings','employee_trips','hr_checklists','recruitment','my_hr',
              'api/manage_announcement','api/get_announcements','api/mark_announcement_read',
              'api/manage_meeting','api/get_meetings','api/manage_trip','api/get_trips',
              'api/manage_checklist_template','api/get_checklists','api/spawn_checklist','api/tick_checklist_item',
              'api/manage_opening','api/get_openings','api/manage_candidate','api/get_candidates',
              'api/change_candidate_stage','api/download_candidate_cv','api/my_hr_data','api/my_leave_apply'] as $r) {
        ok(isset($routes[$r]) && isset($routes["$r.php"]), "route '$r' (+ .php) registered");
    }
    $header = file_get_contents("$root/header.php");
    ok(strpos($header, "canView('recruitment')")!==false && strpos($header, "canView('announcements')")!==false, "nav: HR dropdown gains Tier 4 items");
    ok(strpos($header, "getUrl('my_hr')")!==false && strpos($header, "\$_SESSION['employee_id']")!==false, "nav: My HR shown only when session has a linked employee");
    $mapping = getPagePermissionMapping();
    ok(($mapping['recruitment.php'] ?? '')==='recruitment' && ($mapping['my_hr.php'] ?? '')==='my_hr', "router fallback maps the new pages");

    // ── 8. Upload dirs hardened ──────────────────────────────────────────────
    foreach (['candidate_cvs','trips'] as $d) {
        $ht = @file_get_contents("$root/uploads/$d/.htaccess") ?: '';
        ok(is_dir("$root/uploads/$d") && strpos($ht,'Require all denied')!==false, "uploads/$d exists + .htaccess denies exec");
    }

    // ── 9. Users writers keep working with AND without the ESS link ─────────
    // employee to link
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__TAL','Emp','__TAL-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();
    $rid = (int)$pdo->query("SELECT role_id FROM roles LIMIT 1")->fetchColumn();
    // without the link (old-style insert path — column omitted entirely still works because it's nullable)
    $pdo->exec("INSERT INTO users (username,email,first_name,last_name,role_id,password,created_at) VALUES ('__tal_no','tal_no@x.test','No','Link',$rid,'x',NOW())");
    $uid_no = (int)$pdo->lastInsertId();
    ok($uid_no > 0 && $pdo->query("SELECT employee_id FROM users WHERE user_id=$uid_no")->fetchColumn() === null, "user created WITHOUT employee_id (back-compat) — link is NULL");
    // with the link (new field present)
    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,employee_id,password,created_at) VALUES ('__tal_yes','tal_yes@x.test','Yes','Link',?,?,'x',NOW())")->execute([$rid,$emp]);
    $uid_yes = (int)$pdo->lastInsertId();
    ok($uid_yes > 0 && (int)$pdo->query("SELECT employee_id FROM users WHERE user_id=$uid_yes")->fetchColumn() === $emp, "user created WITH employee_id — link stored");
    // add_user/edit_user form wiring present
    $addSrc = file_get_contents("$root/app/constant/settings/add_user.php");
    ok(strpos($addSrc, "name=\"employee_id\"")!==false && strpos($addSrc, 'employee_id, password')!==false, "add_user.php: Linked Employee field + INSERT column");
    $editSrc = file_get_contents("$root/app/constant/settings/edit_user.php");
    ok(strpos($editSrc, "name=\"employee_id\"")!==false && strpos($editSrc, 'employee_id = ?')!==false, "edit_user.php: Linked Employee field + UPDATE column");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($uid_no) $pdo->exec("DELETE FROM users WHERE user_id=$uid_no");
    if ($uid_yes) $pdo->exec("DELETE FROM users WHERE user_id=$uid_yes");
    if ($emp) $pdo->exec("DELETE FROM employees WHERE employee_id=$emp");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
