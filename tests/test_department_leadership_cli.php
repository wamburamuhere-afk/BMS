<?php
/**
 * test_department_leadership_cli.php
 * End-to-end for the "Department Leadership" HR Action + department-scoped
 * Reporting To. Drives the REAL endpoints through subprocess runners (admin
 * session + CSRF), then asserts the approval effect landed on the departments
 * table. Fully self-cleaning — restores every department it touches and deletes
 * its test lifecycle event.
 */
$root = dirname(__DIR__);
require_once $root . '/roots.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($m){ global $pass; $pass++; echo "  [PASS] $m\n"; }
function no($m){ global $fail; $fail++; echo "  [FAIL] $m\n"; }
function section($t){ echo "\n== $t ==\n"; }

// Run an endpoint in a fresh admin-session subprocess; returns decoded JSON.
function run_endpoint($root, $endpoint, $post = [], $get = []) {
    $runner = $root . '/tests/_tmp_ldr_runner.php';
    $code = '<?php
require_once ' . var_export($root . '/roots.php', true) . ';
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["is_admin"]=true; $_SESSION["role_id"]=1;
$_SERVER["REQUEST_METHOD"]=' . var_export($post ? 'POST' : 'GET', true) . ';
parse_str(' . var_export(http_build_query($get), true) . ', $_GET);
parse_str(' . var_export(http_build_query($post), true) . ', $_POST);
if (function_exists("csrf_token")) { $_POST["_csrf"] = csrf_token(); }
require ' . var_export($endpoint, true) . ';
';
    file_put_contents($runner, $code);
    $out = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
    @unlink($runner);
    return [json_decode(trim((string)$out), true), trim((string)$out)];
}

// ── Fixtures ──────────────────────────────────────────────────────────────
$DEPT = 1;                 // Finance
$LEADER = 2; $ASSISTANT = 3;
$origDept = $pdo->query("SELECT manager_id, assistant_manager_id FROM departments WHERE department_id = $DEPT")->fetch(PDO::FETCH_ASSOC);
$event_id = null;
$touchedDept2 = null;

try {
    // ── 1. Create the leadership action — applies IMMEDIATELY ──────────────
    section('1. Create "Department Leadership" — immediate effect');
    [$res, $raw] = run_endpoint($root, $root . '/api/add_lifecycle_event.php', [
        'employee_id' => $LEADER, 'event_type' => 'leadership',
        'event_date' => date('Y-m-d'), 'title' => 'ZZ_TEST leadership',
        'new_department_id' => $DEPT, 'leadership_assistant_id' => $ASSISTANT,
    ]);
    (is_array($res) && !empty($res['success'])) ? ok('event created') : no('create failed: ' . substr($raw, 0, 200));
    $event_id = $res['event_id'] ?? null;
    $event_id ? ok("got event_id=$event_id") : no('no event_id returned');

    // Applied at once — status approved, effect stamped, department updated now
    $evStatus = $pdo->query("SELECT status FROM employee_lifecycle_events WHERE event_id = $event_id")->fetchColumn();
    ($evStatus === 'approved') ? ok('event auto-approved (immediate)') : no("status not approved: $evStatus");

    // ── 2. Department reflects the change straight away ────────────────────
    section('2. Department updated immediately (no approval step)');
    $after = $pdo->query("SELECT manager_id, assistant_manager_id FROM departments WHERE department_id = $DEPT")->fetch(PDO::FETCH_ASSOC);
    ((int)$after['manager_id'] === $LEADER) ? ok('leader (manager_id) set') : no('manager_id wrong: ' . json_encode($after));
    ((int)$after['assistant_manager_id'] === $ASSISTANT) ? ok('assistant set') : no('assistant wrong: ' . json_encode($after));
    $applied = $pdo->query("SELECT effect_applied_at FROM employee_lifecycle_events WHERE event_id = $event_id")->fetchColumn();
    (!empty($applied)) ? ok('effect_applied_at stamped') : no('effect not stamped');

    // ── 3. Reporting-To options: leadership mode ───────────────────────────
    section('3. get_reporting_to_options — leadership mode');
    [$r3] = run_endpoint($root, $root . '/api/get_reporting_to_options.php', [], ['department_id' => $DEPT]);
    ($r3['mode'] ?? '') === 'leadership' ? ok('mode = leadership') : no('mode wrong: ' . json_encode($r3));
    $ids = array_map(fn($o) => (int)$o['id'], $r3['results'] ?? []);
    (in_array($LEADER, $ids, true) && in_array($ASSISTANT, $ids, true)) ? ok('leader + assistant offered') : no('options wrong: ' . json_encode($ids));

    // ── 4. Reporting-To options: all-employees mode (no leader) ────────────
    section('4. get_reporting_to_options — all-employees mode');
    // Use dept 2 (has employees), temporarily clear its leader
    $touchedDept2 = $pdo->query("SELECT manager_id, assistant_manager_id FROM departments WHERE department_id = 2")->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE departments SET manager_id = NULL, assistant_manager_id = NULL WHERE department_id = 2");
    [$r4] = run_endpoint($root, $root . '/api/get_reporting_to_options.php', [], ['department_id' => 2]);
    ($r4['mode'] ?? '') === 'all' ? ok('mode = all (no leader)') : no('mode wrong: ' . json_encode($r4));
    (count($r4['results'] ?? []) > 0) ? ok('dept employees offered (' . count($r4['results']) . ')') : no('no employees returned for dept 2');

    // ── 5. Department leadership read endpoint ─────────────────────────────
    section('5. get_department_leadership');
    [$r5] = run_endpoint($root, $root . '/api/get_department_leadership.php', [], ['department_id' => $DEPT]);
    (($r5['leader']['id'] ?? null) === $LEADER) ? ok('leader name resolved') : no('leader wrong: ' . json_encode($r5));
    (($r5['assistant']['id'] ?? null) === $ASSISTANT) ? ok('assistant name resolved') : no('assistant wrong: ' . json_encode($r5));

} finally {
    // ── cleanup ────────────────────────────────────────────────────────────
    section('cleanup');
    if ($event_id) {
        $pdo->prepare("DELETE FROM employee_lifecycle_events WHERE event_id = ?")->execute([$event_id]);
    }
    $pdo->prepare("UPDATE departments SET manager_id = ?, assistant_manager_id = ? WHERE department_id = ?")
        ->execute([$origDept['manager_id'], $origDept['assistant_manager_id'], $DEPT]);
    if ($touchedDept2) {
        $pdo->prepare("UPDATE departments SET manager_id = ?, assistant_manager_id = ? WHERE department_id = 2")
            ->execute([$touchedDept2['manager_id'], $touchedDept2['assistant_manager_id']]);
    }
    echo "  (restored departments 1" . ($touchedDept2 ? ' & 2' : '') . ", deleted test event)\n";
}

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
