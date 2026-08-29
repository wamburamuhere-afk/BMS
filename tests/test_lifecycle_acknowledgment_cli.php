<?php
/**
 * BMS — Employee acknowledgment of HR warnings/complaints (ESS "My HR" Record tab).
 *
 * Announcements already have a read-receipt (announcement_reads); warnings and
 * complaints — formal HR actions with real compliance weight — had NO way to
 * record whether the employee was ever actually informed. This closes that gap
 * with the same session-linchpin security pattern as api/my_hr_data.php: an
 * employee can only ever acknowledge their OWN record, and HR/admin cannot set
 * it on the employee's behalf (it must be the employee's own action).
 *
 * Uses the same subprocess "worker" pattern as tests/test_my_hr_cli.php so the
 * session-resolution security linchpin is exercised for real, not mocked.
 *
 * Run:
 *   php tests/test_lifecycle_acknowledgment_cli.php
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    if (empty($_SESSION['is_admin']) && !empty($_SESSION['role_id'])) loadUserPermissions((int)$_SESSION['role_id']);
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/api/acknowledge_lifecycle_event.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

function call(string $root, array $post, array $session): array {
    $cfg = ['session' => $session, 'method' => 'POST', 'post' => $post];
    $f = tempnam(sys_get_temp_dir(), 'ack');
    file_put_contents($f, json_encode($cfg));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$out, '{');
    return $s === false ? ['_raw' => (string)$out] : (json_decode(substr($out, $s), true) ?? ['_raw' => $out]);
}

echo "\n\033[1m═══ Employee acknowledgment of HR warnings/complaints ═══\033[0m\n";

head('1. php -l — every new/changed file');
foreach ([
    'migrations/2026_08_29_lifecycle_event_acknowledgment.php',
    'api/acknowledge_lifecycle_event.php', 'api/my_hr_data.php',
    'app/bms/pos/my_hr.php', 'app/bms/pos/hr_actions.php', 'app/bms/pos/employee_details.php',
] as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? ok("$f lint-clean") : bad("$f: " . trim((string)$out));
}

head('2. Schema');
$col = $pdo->query("SHOW COLUMNS FROM employee_lifecycle_events LIKE 'acknowledged_at'")->fetch(PDO::FETCH_ASSOC);
$col ? ok('employee_lifecycle_events.acknowledged_at exists') : bad('missing');
$col2 = $pdo->query("SHOW COLUMNS FROM employee_lifecycle_events LIKE 'acknowledgment_note'")->fetch(PDO::FETCH_ASSOC);
$col2 ? ok('employee_lifecycle_events.acknowledgment_note exists') : bad('missing');

head('3. Wiring — every surface actually shows it, nothing left inert');
$myHrDataSrc = file_get_contents("$root/api/my_hr_data.php");
strpos($myHrDataSrc, 'acknowledged_at') !== false ? ok("my_hr_data.php 'record' section selects acknowledged_at") : bad('missing from SELECT — ESS would never see it');
$myHrSrc = file_get_contents("$root/app/bms/pos/my_hr.php");
strpos($myHrSrc, 'acknowledgeEvent') !== false ? ok('my_hr.php has the Acknowledge action') : bad('missing');
strpos($myHrSrc, 'api/acknowledge_lifecycle_event.php') !== false ? ok('my_hr.php calls the real endpoint') : bad('missing');
$hrActionsSrc = file_get_contents("$root/app/bms/pos/hr_actions.php");
strpos($hrActionsSrc, 'ackIndicator') !== false ? ok('hr_actions.php shows an acknowledgment indicator (list + card + detail)') : bad('missing');
$empDetailsSrc = file_get_contents("$root/app/bms/pos/employee_details.php");
strpos($empDetailsSrc, 'acknowledged_at') !== false ? ok('employee_details.php Service Record shows acknowledgment status') : bad('missing');

// ── Live security round trip ────────────────────────────────────────────────
$empTarget = 0; $empOther = 0; $uTarget = 0; $uOther = 0; $evWarning = 0; $evOtherWarning = 0; $evPromotion = 0;
try {
    head('4. Fixtures');
    $rid = (int)$pdo->query("SELECT role_id FROM roles WHERE is_admin = 0 LIMIT 1")->fetchColumn();

    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,status,hire_date,gender,date_of_birth,email,phone,address,emergency_contact,created_by,created_at)
                VALUES ('__ACK','Target','__ACK-E1','active','active',CURDATE(),'male','1990-01-01','ackt@x.test','000','x','x',1,NOW())");
    $empTarget = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,status,hire_date,gender,date_of_birth,email,phone,address,emergency_contact,created_by,created_at)
                VALUES ('__ACK','Other','__ACK-E2','active','active',CURDATE(),'male','1990-01-01','acko@x.test','000','x','x',1,NOW())");
    $empOther = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,employee_id,is_active,password,created_at) VALUES ('__ack_target','ackt2@x.test','T','U',?,?,1,'x',NOW())")->execute([$rid, $empTarget]);
    $uTarget = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,employee_id,is_active,password,created_at) VALUES ('__ack_other','acko2@x.test','O','U',?,?,1,'x',NOW())")->execute([$rid, $empOther]);
    $uOther = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO employee_lifecycle_events (employee_id, event_type, event_date, title, severity, status, created_by, created_at)
                   VALUES (?, 'warning', CURDATE(), '__ACK Test Warning', 'written', 'approved', 1, NOW())")->execute([$empTarget]);
    $evWarning = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO employee_lifecycle_events (employee_id, event_type, event_date, title, severity, status, created_by, created_at)
                   VALUES (?, 'warning', CURDATE(), '__ACK Other Warning', 'verbal', 'approved', 1, NOW())")->execute([$empOther]);
    $evOtherWarning = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO employee_lifecycle_events (employee_id, event_type, event_date, title, status, created_by, created_at)
                   VALUES (?, 'promotion', CURDATE(), '__ACK Promotion', 'pending', 1, NOW())")->execute([$empTarget]);
    $evPromotion = (int)$pdo->lastInsertId();

    ok($empTarget && $empOther && $uTarget && $uOther && $evWarning && $evOtherWarning && $evPromotion, 'fixtures ready');

    head('5. Ownership: the target user CANNOT acknowledge someone else\'s warning');
    $r1 = call($root, ['event_id' => $evOtherWarning], ['user_id' => $uTarget, 'is_admin' => false, 'role_id' => $rid]);
    (empty($r1['success']) && str_contains($r1['message'] ?? '', 'not your record'))
        ? ok('cross-employee acknowledgment rejected') : bad('SECURITY: cross-employee acknowledgment not blocked: ' . json_encode($r1));

    head('6. Event type: cannot acknowledge a non-warning/complaint event');
    $r2 = call($root, ['event_id' => $evPromotion], ['user_id' => $uTarget, 'is_admin' => false, 'role_id' => $rid]);
    (empty($r2['success']) && str_contains($r2['message'] ?? '', 'does not require acknowledgment'))
        ? ok('promotion event rejected (only warning/complaint apply)') : bad('wrong event type not rejected: ' . json_encode($r2));

    head('7. The target user CAN acknowledge their own approved warning');
    $r3 = call($root, ['event_id' => $evWarning, 'acknowledgment_note' => 'Received, noted.'], ['user_id' => $uTarget, 'is_admin' => false, 'role_id' => $rid]);
    (!empty($r3['success'])) ? ok('acknowledged successfully') : bad('own acknowledgment failed: ' . json_encode($r3));
    $row = $pdo->query("SELECT acknowledged_at, acknowledgment_note FROM employee_lifecycle_events WHERE event_id = $evWarning")->fetch(PDO::FETCH_ASSOC);
    !empty($row['acknowledged_at']) ? ok('acknowledged_at stamped') : bad('acknowledged_at not stamped');
    ($row['acknowledgment_note'] === 'Received, noted.') ? ok('acknowledgment_note persisted') : bad('note not persisted: ' . json_encode($row));

    head('8. Cannot acknowledge twice');
    $r4 = call($root, ['event_id' => $evWarning], ['user_id' => $uTarget, 'is_admin' => false, 'role_id' => $rid]);
    (empty($r4['success']) && str_contains($r4['message'] ?? '', 'Already acknowledged'))
        ? ok('double-acknowledgment rejected') : bad('double-acknowledgment not blocked: ' . json_encode($r4));

    head('9. An unlinked user (no employee_id) is refused, not silently no-op\'d');
    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,employee_id,is_active,password,created_at) VALUES ('__ack_unlinked','acku@x.test','U','U',?,NULL,1,'x',NOW())")->execute([$rid]);
    $uUnlinked = (int)$pdo->lastInsertId();
    $r5 = call($root, ['event_id' => $evOtherWarning], ['user_id' => $uUnlinked, 'is_admin' => false, 'role_id' => $rid]);
    (empty($r5['success']) && ($r5['message'] ?? '') === 'not_linked') ? ok('unlinked user refused') : bad('unlinked user not refused: ' . json_encode($r5));
    $pdo->exec("DELETE FROM users WHERE user_id = $uUnlinked");

} finally {
    if ($evWarning) $pdo->exec("DELETE FROM employee_lifecycle_events WHERE event_id IN ($evWarning, $evOtherWarning, $evPromotion)");
    if ($uTarget) $pdo->exec("DELETE FROM users WHERE user_id IN ($uTarget, $uOther)");
    if ($empTarget) $pdo->exec("DELETE FROM employees WHERE employee_id IN ($empTarget, $empOther)");
    echo "\n  (test fixtures cleaned up)\n";
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
