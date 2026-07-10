<?php
/**
 * Employee re-hire uniqueness CLI test.
 *   php tests/test_employee_rehire_uniqueness_cli.php
 *
 * Reproduces the reported bug: create an employee, "delete" him (soft delete =>
 * status='terminated'), then re-create with the SAME email. Before the fix the
 * add_employee uniqueness check counted the terminated row and threw
 * "Employee code, employee number, or email already exists". After the fix the
 * check ignores soft-deleted rows, so the re-hire is allowed through — while a
 * duplicate against a still-ACTIVE employee is still blocked.
 *
 * add_employee.php requires a CV upload, which can't be simulated in CLI. But the
 * uniqueness check runs BEFORE that document gate, so we assert on WHICH error the
 * endpoint returns:
 *   - active duplicate  -> "already exists"          (uniqueness blocks)
 *   - terminated match  -> "Document is compulsory"  (uniqueness PASSED, next gate)
 * That difference is the fix.
 */
$root = dirname(__DIR__);

// ── worker mode: run one API endpoint with a given session + POST, echo its JSON
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
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $payload, $session, $method = 'POST') {
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload];
    $f = tempnam(sys_get_temp_dir(), 'ehr'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true);
}

$adminSession = [
    'user_id' => 4, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'role' => 'admin',
    'scope' => ['is_admin' => true, 'projects' => ['*'], 'warehouses' => [], 'suppliers' => [],
                'customers' => [], 'employees' => [], 'computed_at' => time()],
];

echo "\n=== Employee re-hire uniqueness (soft-delete) ===\n";

// add_employee requires department_id + designation_id as POST fields
$deptId  = $pdo->query("SELECT department_id FROM departments LIMIT 1")->fetchColumn();
$desigId = $pdo->query("SELECT designation_id FROM designations LIMIT 1")->fetchColumn();
if (!$deptId || !$desigId) {
    echo "  \033[33m⚠ SKIP\033[0m — no department/designation seeded to attach a test employee to.\n";
    exit(0);
}

$ts     = time();
$email  = "rehiretest+$ts@example.test";
$code   = "RHT-$ts";
$number = "RHT-$ts";
$emailPattern = 'rehiretest+%@example.test';

// tidy any leftovers from a previous aborted run
$pdo->prepare("DELETE FROM employees WHERE email LIKE ?")->execute([$emailPattern]);

// ── seed one ACTIVE base employee directly (fills every NOT NULL / no-default col)
$cols = $pdo->query("
    SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
       AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL
       AND EXTRA NOT LIKE '%auto_increment%'
")->fetchAll(PDO::FETCH_ASSOC);

$forced = [
    'email' => $email, 'employee_code' => $code, 'employee_number' => $number,
    'first_name' => 'RehireTest', 'last_name' => 'Employee', 'phone' => '0700000000',
    'status' => 'active', 'created_by' => 4, 'department_id' => $deptId, 'designation_id' => $desigId,
];
$vals = $forced;
foreach ($cols as $c) {
    $n = $c['COLUMN_NAME'];
    if (array_key_exists($n, $vals)) continue;
    $t = strtolower($c['DATA_TYPE']);
    if ($t === 'enum') {                                   // first allowed value
        $vals[$n] = preg_match("/'([^']*)'/", $c['COLUMN_TYPE'], $m) ? $m[1] : '';
    } elseif (in_array($t, ['int','bigint','tinyint','smallint','mediumint','decimal','double','float','year'])) {
        $vals[$n] = 0;
    } elseif ($t === 'date') {
        $vals[$n] = date('Y-m-d');
    } elseif (in_array($t, ['datetime','timestamp'])) {
        $vals[$n] = date('Y-m-d H:i:s');
    } else {
        $vals[$n] = 'x';
    }
}
$names = array_keys($vals);
$pdo->prepare("INSERT INTO employees (" . implode(',', $names) . ") VALUES (" .
    implode(',', array_fill(0, count($names), '?')) . ")")->execute(array_values($vals));
$baseId = (int)$pdo->lastInsertId();
ok($baseId > 0, "0. Seeded a base ACTIVE employee (#$baseId)");

$payload = [
    'first_name' => 'RehireTest', 'last_name' => 'Employee', 'email' => $email,
    'phone' => '0700000000', 'employee_number' => 'EMP-1',   // auto-generates a fresh number
    'department_id' => $deptId, 'designation_id' => $desigId,
];

// 1. While the base is ACTIVE, re-using its email must still be blocked by uniqueness
$rActive = call('add_employee', $payload, $adminSession);
ok(($rActive['success'] ?? null) === false, '1. Duplicate email vs ACTIVE employee is rejected');
ok(isset($rActive['message']) && stripos($rActive['message'], 'already exists') !== false,
   '1b. …with the uniqueness error (got: "' . ($rActive['message'] ?? '') . '")');

// 2. Inactivate the base employee (mirrors api/inactivate_employee.php)
$pdo->prepare("UPDATE employees SET status='inactive', employment_status='terminated' WHERE employee_id=?")
    ->execute([$baseId]);
$status = $pdo->query("SELECT status FROM employees WHERE employee_id=$baseId")->fetchColumn();
ok($status === 'inactive', '2. Base employee inactivated (status=inactive)');

// 3. THE FIX — the same email now passes the uniqueness check and reaches the NEXT gate
$rRehire = call('add_employee', $payload, $adminSession);
$msg = $rRehire['message'] ?? '';
ok(stripos($msg, 'already exists') === false,
   '3. Re-hire no longer hits the uniqueness error (got: "' . $msg . '")');
ok(stripos($msg, 'compulsory') !== false || stripos($msg, 'document') !== false || stripos($msg, 'cv') !== false,
   '3b. Uniqueness PASSED — endpoint advanced to the document gate');

// 4. Direct view of the data the fixed check relies on
$all    = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE email=" . $pdo->quote($email))->fetchColumn();
$active = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE email=" . $pdo->quote($email) .
                           " AND (status IS NULL OR status = 'active')")->fetchColumn();
ok($all === 1,    "4. One row holds the email (the inactive base), got $all");
ok($active === 0, "4b. Zero ACTIVE rows hold it — so the fixed check allows the re-hire, got $active");

// ── cleanup
$pdo->prepare("DELETE FROM employees WHERE email LIKE ?")->execute([$emailPattern]);

echo "\n" . ($fail === 0 ? "\033[32mALL PASSED" : "\033[31m$fail FAILED") . "\033[0m — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
