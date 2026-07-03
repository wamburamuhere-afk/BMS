<?php
/**
 * Training module (Tier 3, Phase 3.5) CLI test.
 *   php tests/test_employee_training_cli.php
 *
 * Proves: training CRUD + status flow with the completion gate (all
 * participants terminal), participant add/uniqueness/update/remove,
 * the D22 certificate → central-library wiring (expire_date mirrored so the
 * existing document-expiry cron alerts with zero new alert code), gatekeeper
 * containment + permission denial, and page/details renders. The real
 * move_uploaded_file() upload path can't run from a CLI subprocess (same
 * limitation as the Tier 2 docs test), so the D22 library wiring is proven
 * through the same SQL path the API runs + the live cron.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[3] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if ($argv[2] === 'trainings') { $_SERVER['REQUEST_URI'] = '/trainings'; require "$root/app/bms/pos/trainings.php"; }
    else { $_SERVER['REQUEST_URI'] = '/employee_details'; $_GET['id'] = (int)($argv[4] ?? 0); require "$root/app/bms/pos/employee_details.php"; }
    exit;
}
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? []; $_FILES = $cfg['files'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $payload, $session, $method = 'POST') {
    global $root;
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload];
    $f = tempnam(sys_get_temp_dir(), 'trn'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($page, $uid, $emp = 0) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $page $uid $emp 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }
function makePdf($p) { file_put_contents($p, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"); }

$type = 0; $emp = 0; $emp2 = 0; $training = 0; $part = 0; $lib = 0; $files = [];
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999940, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $type = (int)$pdo->query("SELECT training_type_id FROM training_types WHERE type_name='Technical'")->fetchColumn();
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__TR','One','__TR-E1','active',NOW())"); $emp = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__TR','Two','__TR-E2','active',NOW())"); $emp2 = (int)$pdo->lastInsertId();
    ok($type && $emp && $emp2, "fixtures ready (type #$type, emps #$emp/#$emp2)");

    // ── 1. Training CRUD + validation ────────────────────────────────────────
    $r = call('manage_trainings', ['action'=>'add','title'=>'','training_type_id'=>$type,'start_date'=>date('Y-m-d')], $ADMIN);
    ok(empty($r['success']), "rejects: missing title");
    $r = call('manage_trainings', ['action'=>'add','title'=>'Safety 101','training_type_id'=>$type,'trainer_kind'=>'external','start_date'=>date('Y-m-d')], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'trainer')!==false, "external trainer requires a name");
    $r = call('manage_trainings', ['action'=>'add','title'=>'x','training_type_id'=>$type,'start_date'=>date('Y-m-d')], $NOPERM);
    ok(empty($r['success']), "create denied without canCreate('trainings')");

    $r = call('manage_trainings', ['action'=>'add','title'=>'Welding Basics','training_type_id'=>$type,'trainer_kind'=>'external','trainer_name'=>'Ext Co','venue'=>'HQ','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+2 days')),'cost'=>500000], $ADMIN);
    $training = (int)($r['training_id'] ?? 0);
    ok(!empty($r['success']) && $training, "training created (planned)");

    // ── 2. Status flow + completion gate ─────────────────────────────────────
    $r = call('manage_trainings', ['action'=>'change_status','training_id'=>$training,'status'=>'completed'], $ADMIN);
    ok(empty($r['success']), "cannot jump planned → completed");
    $r = call('manage_trainings', ['action'=>'change_status','training_id'=>$training,'status'=>'in_progress'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM trainings WHERE training_id=$training")->fetchColumn()==='in_progress', "planned → in_progress");

    // ── 3. Participants ──────────────────────────────────────────────────────
    $r = call('manage_training_participants', ['action'=>'add','training_id'=>$training,'employee_ids'=>[$emp,$emp2]], $ADMIN);
    ok(!empty($r['success']), "two participants enrolled");
    $r = call('manage_training_participants', ['action'=>'add','training_id'=>$training,'employee_ids'=>[$emp]], $ADMIN);
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM training_participants WHERE training_id=$training")->fetchColumn();
    ok($cnt === 2, "re-adding the same employee does not duplicate (uniq_training_emp)");
    $part = (int)$pdo->query("SELECT participant_id FROM training_participants WHERE training_id=$training AND employee_id=$emp")->fetchColumn();

    // completion blocked while participants are non-terminal
    $r = call('manage_trainings', ['action'=>'change_status','training_id'=>$training,'status'=>'completed'], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'final state')!==false, "completion blocked while participants are enrolled");

    // move both to terminal states
    call('manage_training_participants', ['action'=>'update','participant_id'=>$part,'status'=>'completed','score'=>'90%'], $ADMIN);
    $part2 = (int)$pdo->query("SELECT participant_id FROM training_participants WHERE training_id=$training AND employee_id=$emp2")->fetchColumn();
    call('manage_training_participants', ['action'=>'update','participant_id'=>$part2,'status'=>'withdrawn'], $ADMIN);
    $r = call('manage_trainings', ['action'=>'change_status','training_id'=>$training,'status'=>'completed'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM trainings WHERE training_id=$training")->fetchColumn()==='completed', "completion allowed once all participants are terminal");

    $r = call('manage_training_participants', ['action'=>'update','participant_id'=>$part,'status'=>'completed'], $NOPERM);
    ok(empty($r['success']), "participant update denied without canEdit");

    // ── 4. D22 — certificate registers into the library with expire_date; cron alerts ──
    $safe = bin2hex(random_bytes(8)) . '.pdf'; $rel = 'uploads/training_certs/' . $safe;
    makePdf("$root/$rel"); $files[] = "$root/$rel";
    $lib = registerFileInLibrary($pdo, $rel, 'cert.pdf', 100, 'Training Certificate — __TR One', 'hr,training,certificate', $admin_uid, null);
    ok($lib > 0, "certificate registered into the central library");
    $expiry = date('Y-m-d', strtotime('+7 days'));
    $pdo->prepare("UPDATE documents SET issue_date=?, expire_date=? WHERE id=?")->execute([date('Y-m-d'), $expiry, $lib]);
    $pdo->prepare("UPDATE training_participants SET certificate_path=?, certificate_name='cert.pdf', certificate_expire_date=?, library_document_id=? WHERE participant_id=?")
        ->execute([$rel, $expiry, $lib, $part]);

    $before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE document_id=$lib")->fetchColumn();
    require_once "$root/cron/check_document_expiry.php";
    $after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE document_id=$lib")->fetchColumn();
    ok($after > $before, "D22: existing document-expiry cron fired for the training certificate (zero new alert code)");

    // ── 5. Gatekeeper download ───────────────────────────────────────────────
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker download_training_certificate " .
        escapeshellarg((function () use ($ADMIN, $part) { $f = tempnam(sys_get_temp_dir(), 'trd'); file_put_contents($f, json_encode(['session'=>$ADMIN,'method'=>'GET','get'=>['participant_id'=>$part]])); return $f; })()));
    ok(strpos($o, '%PDF') !== false, "gatekeeper streams the certificate for an authorised user");
    $r = call('download_training_certificate', ['participant_id'=>$part], $NOPERM, 'GET');
    ok(strpos($r['_raw'] ?? '', '%PDF') === false, "gatekeeper refuses a user without canView('trainings')");

    // remove a participant
    $r = call('manage_training_participants', ['action'=>'remove','participant_id'=>$part2], $ADMIN);
    ok(!empty($r['success']) && (int)$pdo->query("SELECT COUNT(*) FROM training_participants WHERE participant_id=$part2")->fetchColumn()===0, "participant removed");

    // ── 6. Renders ───────────────────────────────────────────────────────────
    $html = render('trainings', $admin_uid);
    ok(noErr($html) && strpos($html,'Training &amp; Development')!==false, "trainings.php renders");
    $html = render('details', $admin_uid, $emp);
    ok(noErr($html) && strpos($html,'Training')!==false && strpos($html,'Welding Basics')!==false, "employee_details Training card shows the training history");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($training) $pdo->exec("DELETE FROM training_participants WHERE training_id=$training");
    if ($training) $pdo->exec("DELETE FROM trainings WHERE training_id=$training");
    if ($lib) { $pdo->exec("DELETE FROM notifications WHERE document_id=$lib"); $pdo->exec("DELETE FROM document_expiry_reminders WHERE document_id=$lib"); $pdo->exec("DELETE FROM documents WHERE id=$lib"); }
    if ($emp) $pdo->exec("DELETE FROM employees WHERE employee_id IN ($emp,$emp2)");
    foreach ($files as $f) @unlink($f);
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
