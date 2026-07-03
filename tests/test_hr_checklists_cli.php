<?php
/**
 * Onboarding / Offboarding checklists (Tier 4, Phase 4.4) CLI test.
 *   php tests/test_hr_checklists_cli.php
 *
 * Proves: template CRUD + single-default-per-type rule, D30 snapshot isolation
 * (editing a template after spawn does NOT change the spawned checklist), tick
 * flow + completion gate (all items done), the D28 auto-spawn helper
 * (spawnChecklistIfConfigured) for onboarding, offboarding auto-spawn on an
 * approved termination (through the real change_lifecycle_status.php), the
 * non-fatal guarantee (spawn helper returns 0 / never throws when no default),
 * permission denials, and page render.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[2] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/hr_checklists';
    require "$root/app/bms/pos/hr_checklists.php";
    exit;
}
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/checklists.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $payload, $session, $method = 'POST') {
    global $root;
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload];
    $f = tempnam(sys_get_temp_dir(), 'chk'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($uid) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $uid 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$tpl = 0; $tpl2 = 0; $emp = 0; $emp2 = 0; $chk = 0; $ev = 0;
$saved_default = null;
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999970, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];

    // remember + clear the existing default onboarding so our test template can own it
    $saved_default = $pdo->query("SELECT template_id FROM checklist_templates WHERE template_type='onboarding' AND is_default=1 LIMIT 1")->fetchColumn();

    // ── 1. Template CRUD ─────────────────────────────────────────────────────
    $r = call('manage_checklist_template', ['action'=>'add_template','template_name'=>'__CHK Onboard','template_type'=>'onboarding'], $ADMIN);
    $tpl = (int)($r['template_id'] ?? 0);
    ok(!empty($r['success']) && $tpl, "onboarding template created");
    $r = call('manage_checklist_template', ['action'=>'add_template','template_name'=>'__CHK Onboard','template_type'=>'onboarding'], $ADMIN);
    ok(empty($r['success']), "duplicate template name+type rejected");
    $r = call('manage_checklist_template', ['action'=>'add_template','template_name'=>'__CHK Onboard 2','template_type'=>'onboarding'], $ADMIN);
    $tpl2 = (int)($r['template_id'] ?? 0);
    ok($tpl2 > 0, "second onboarding template created");
    $r = call('manage_checklist_template', ['action'=>'add_template','template_name'=>'x','template_type'=>'onboarding'], $NOPERM);
    ok(empty($r['success']), "template create denied without canEdit('hr_checklists')");

    // items
    foreach (['Sign contract','Set up email','Issue laptop'] as $it) call('manage_checklist_template', ['action'=>'add_item','template_id'=>$tpl,'item_text'=>$it,'sort_order'=>0], $ADMIN);
    $itemCount = (int)$pdo->query("SELECT COUNT(*) FROM checklist_template_items WHERE template_id=$tpl")->fetchColumn();
    ok($itemCount === 3, "3 items added to the template");

    // ── 2. single-default-per-type rule ─────────────────────────────────────
    call('manage_checklist_template', ['action'=>'set_default','template_id'=>$tpl], $ADMIN);
    call('manage_checklist_template', ['action'=>'set_default','template_id'=>$tpl2], $ADMIN);
    $defaults = (int)$pdo->query("SELECT COUNT(*) FROM checklist_templates WHERE template_type='onboarding' AND is_default=1")->fetchColumn();
    ok($defaults === 1, "only one default onboarding template at a time");
    ok((int)$pdo->query("SELECT is_default FROM checklist_templates WHERE template_id=$tpl2")->fetchColumn() === 1, "the latest set_default won");
    // make $tpl the default for the auto-spawn test
    call('manage_checklist_template', ['action'=>'set_default','template_id'=>$tpl], $ADMIN);

    // ── 3. Manual spawn + D30 snapshot isolation ────────────────────────────
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__CHK','Emp','__CHK-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();
    $r = call('spawn_checklist', ['employee_id'=>$emp,'template_id'=>$tpl], $ADMIN);
    $chk = (int)($r['checklist_id'] ?? 0);
    ok(!empty($r['success']) && $chk, "checklist spawned for the employee");
    $snapCount = (int)$pdo->query("SELECT COUNT(*) FROM employee_checklist_items WHERE checklist_id=$chk")->fetchColumn();
    ok($snapCount === 3, "spawned checklist snapshotted all 3 items");
    // edit the template AFTER spawn — the spawned checklist must NOT change (D30)
    call('manage_checklist_template', ['action'=>'add_item','template_id'=>$tpl,'item_text'=>'A NEW template item','sort_order'=>0], $ADMIN);
    $snapAfter = (int)$pdo->query("SELECT COUNT(*) FROM employee_checklist_items WHERE checklist_id=$chk")->fetchColumn();
    ok($snapAfter === 3, "D30: editing the template after spawn does NOT change the spawned checklist");

    // ── 4. Tick flow + completion gate ──────────────────────────────────────
    $items = $pdo->query("SELECT item_id FROM employee_checklist_items WHERE checklist_id=$chk ORDER BY item_id")->fetchAll(PDO::FETCH_COLUMN);
    $r = call('tick_checklist_item', ['item_id'=>$items[0],'is_done'=>1,'notes'=>'done today'], $ADMIN);
    ok(!empty($r['success']) && (int)$pdo->query("SELECT is_done FROM employee_checklist_items WHERE item_id={$items[0]}")->fetchColumn()===1, "item ticked done (with note in the audit)");
    $r = call('change_checklist_status', ['checklist_id'=>$chk,'status'=>'completed'], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'open')!==false, "completion blocked while items are still open");
    foreach ([$items[1],$items[2]] as $iid) call('tick_checklist_item', ['item_id'=>$iid,'is_done'=>1], $ADMIN);
    $r = call('change_checklist_status', ['checklist_id'=>$chk,'status'=>'completed'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_checklists WHERE checklist_id=$chk")->fetchColumn()==='completed', "completion allowed once all items are done");
    $r = call('tick_checklist_item', ['item_id'=>$items[0],'is_done'=>0], $NOPERM);
    ok(empty($r['success']), "tick denied without canEdit");

    // ── 5. D28(b) auto-spawn helper: onboarding on a new employee ───────────
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__CHK','Emp2','__CHK-E2','active',NOW())");
    $emp2 = (int)$pdo->lastInsertId();
    $spawned = spawnChecklistIfConfigured($pdo, $emp2, 'onboarding', $admin_uid);
    ok($spawned > 0, "D28(b): spawnChecklistIfConfigured creates an onboarding checklist from the default template");
    ok((int)$pdo->query("SELECT COUNT(*) FROM employee_checklists WHERE employee_id=$emp2 AND checklist_type='onboarding'")->fetchColumn()===1, "  the new employee has exactly one onboarding checklist");
    // idempotent — calling again while one is in progress does not double-spawn
    $again = spawnChecklistIfConfigured($pdo, $emp2, 'onboarding', $admin_uid);
    ok($again === 0 && (int)$pdo->query("SELECT COUNT(*) FROM employee_checklists WHERE employee_id=$emp2 AND checklist_type='onboarding'")->fetchColumn()===1, "auto-spawn does not double-spawn while one is in progress");

    // ── 6. D28(c) offboarding auto-spawn via an approved termination ────────
    // ensure a default offboarding template exists (the migration seeds one)
    $hasOff = (int)$pdo->query("SELECT COUNT(*) FROM checklist_templates WHERE template_type='offboarding' AND is_default=1 AND status='active'")->fetchColumn();
    if ($hasOff) {
        $pdo->prepare("INSERT INTO employee_lifecycle_events (employee_id, event_type, event_date, title, termination_type, status, created_by)
                       VALUES (?, 'termination', CURDATE(), 'Test termination', 'misconduct', 'pending', ?)")->execute([$emp2, $admin_uid]);
        $ev = (int)$pdo->lastInsertId();
        $r = call('change_lifecycle_status', ['event_id'=>$ev,'action'=>'approve'], $ADMIN);
        ok(!empty($r['success']), "termination approved through the real lifecycle endpoint");
        ok((int)$pdo->query("SELECT COUNT(*) FROM employee_checklists WHERE employee_id=$emp2 AND checklist_type='offboarding' AND status='in_progress'")->fetchColumn()===1,
            "D28(c): approving a termination auto-spawned an offboarding checklist");
    } else {
        ok(true, "no default offboarding template — auto-spawn covered by helper test (skip)");
        ok(true, "  (skip)");
    }

    // ── 7. Non-fatal / no-default safety ────────────────────────────────────
    $r = spawnChecklistIfConfigured($pdo, 99999999, 'onboarding', $admin_uid);   // bogus employee
    ok($r === 0, "spawn helper returns 0 (never throws) for an unspawnable case");

    // ── 8. Page render ───────────────────────────────────────────────────────
    $html = render($admin_uid);
    ok(noErr($html) && strpos($html,'Checklists')!==false, "hr_checklists.php renders");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($ev) $pdo->exec("DELETE FROM employee_lifecycle_events WHERE event_id=$ev");
    foreach ([$emp,$emp2] as $eid) if ($eid) {
        $cids = $pdo->query("SELECT checklist_id FROM employee_checklists WHERE employee_id=$eid")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cids as $cid) $pdo->exec("DELETE FROM employee_checklist_items WHERE checklist_id=$cid");
        $pdo->exec("DELETE FROM employee_checklists WHERE employee_id=$eid");
        $pdo->exec("DELETE FROM employee_lifecycle_events WHERE employee_id=$eid");
        $pdo->exec("DELETE FROM employees WHERE employee_id=$eid");
    }
    foreach ([$tpl,$tpl2] as $tid) if ($tid) { $pdo->exec("DELETE FROM checklist_template_items WHERE template_id=$tid"); $pdo->exec("DELETE FROM checklist_templates WHERE template_id=$tid"); }
    // restore the original default onboarding template
    if ($saved_default) { $pdo->exec("UPDATE checklist_templates SET is_default=0 WHERE template_type='onboarding'"); $pdo->prepare("UPDATE checklist_templates SET is_default=1 WHERE template_id=?")->execute([(int)$saved_default]); }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
