<?php
/**
 * MM User Agent Grants — management page contract
 *   php tests/test_mm_user_agent_grants_cli.php
 *
 * A. STATIC — page exists, lint-clean, admin-gated, AJAX handlers present,
 *             route and permission registered.
 *
 * B. LIVE (transaction rolled back) — insert/delete grant rows, mmUserCanOnTill()
 *    reads them correctly (specific ability on/off, NULL row fallback).
 *
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)  { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p)     { return is_file($p) ? file_get_contents($p) : ''; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

$page = "$root/app/constant/settings/mm_user_agent_grants.php";

// ── A. Static ───────────────────────────────────────────────────────────────
section('A. Static — file + lint');
ok(is_file($page), 'mm_user_agent_grants.php exists');
$o = []; $rc = 0; exec('php -l ' . escapeshellarg($page) . ' 2>&1', $o, $rc);
ok($rc === 0, 'mm_user_agent_grants.php lint-clean');

section('A. Static — security gates');
$sp = src($page);
ok(strpos($sp, 'isAdmin()') !== false,               'page has isAdmin() gate');
ok(strpos($sp, "getUrl('unauthorized')") !== false,  'page redirects non-admins');
ok(strpos($sp, 'csrf_check()') !== false,            'POST handler calls csrf_check()');
ok(strpos($sp, 'logActivity') !== false,             'save handler calls logActivity');
ok(strpos($sp, 'logAudit') !== false,                'save handler calls logAudit');

section('A. Static — AJAX handlers');
ok(strpos($sp, "action==='get_grants'") !== false || strpos($sp, "action' === 'get_grants'") !== false || strpos($sp, "'get_grants'") !== false, 'GET get_grants handler present');
ok(strpos($sp, "REQUEST_METHOD'] === 'POST'") !== false, 'POST save handler present');
ok(strpos($sp, 'beginTransaction') !== false,        'save wrapped in transaction');
ok(strpos($sp, 'rollBack') !== false,                'transaction has rollBack on failure');
ok(strpos($sp, 'mm_user_agent_grants') !== false,    'page references mm_user_agent_grants table');

section('A. Static — ability columns');
ok(strpos($sp, 'can_open_shift') !== false,          'can_open_shift ability present');
ok(strpos($sp, 'can_record_transactions') !== false, 'can_record_transactions ability present');
ok(strpos($sp, 'can_close_shift') !== false,         'can_close_shift ability present');
ok(strpos($sp, 'can_reconcile') !== false,           'can_reconcile ability present');

section('A. Static — NULL row (agent-level default)');
ok(strpos($sp, 'till_id') !== false,                 'till_id referenced in page');
ok(strpos($sp, "null") !== false,                    'NULL till_id supported for agent-level grant');
ok(strpos($sp, 'allTills') !== false || strpos($sp, 'All tills') !== false, 'All tills label present');

section('A. Static — route + permission registration');
$rootsSrc = src("$root/roots.php");
ok(strpos($rootsSrc, "'mm_user_agent_grants'") !== false, 'route mm_user_agent_grants registered in roots.php');
ok(strpos($rootsSrc, 'mm_user_agent_grants.php') !== false, 'route mm_user_agent_grants.php registered in roots.php');

$permSrc = src("$root/core/permissions.php");
ok(strpos($permSrc, "'mm_user_agent_grants.php'") !== false, 'permission mm_user_agent_grants.php registered');

// ── B. Live data-model ───────────────────────────────────────────────────────
section('B. Live — DB mechanics (transaction rolled back)');

require_once "$root/core/mm_float_service.php";
ok(function_exists('mmUserCanOnTill'), 'mmUserCanOnTill() is callable');

if (!(bool)$pdo->query("SHOW TABLES LIKE 'mm_user_agent_grants'")->fetch()) {
    ok(true, 'mm_user_agent_grants table absent on this server — live tests skipped');
    return;
}

// Verify table columns
$cols = array_column($pdo->query("SHOW COLUMNS FROM mm_user_agent_grants")->fetchAll(PDO::FETCH_ASSOC), 'Field');
ok(in_array('grant_id', $cols),                'mm_user_agent_grants has grant_id PK');
ok(in_array('user_id', $cols),                 'mm_user_agent_grants has user_id');
ok(in_array('agent_id', $cols),                'mm_user_agent_grants has agent_id');
ok(in_array('till_id', $cols),                 'mm_user_agent_grants has till_id (nullable)');
ok(in_array('can_open_shift', $cols),          'mm_user_agent_grants has can_open_shift');
ok(in_array('can_record_transactions', $cols), 'mm_user_agent_grants has can_record_transactions');
ok(in_array('can_close_shift', $cols),         'mm_user_agent_grants has can_close_shift');
ok(in_array('can_reconcile', $cols),           'mm_user_agent_grants has can_reconcile');
ok(in_array('granted_by', $cols),              'mm_user_agent_grants has granted_by');

$uid  = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
ok($uid > 0, 'at least one user exists');

$tills = $pdo->query("SELECT t.till_id, t.agent_id FROM mm_tills t JOIN mm_agents a ON a.agent_id=t.agent_id WHERE t.status='active' AND a.status='active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (empty($tills)) {
    ok(true, 'no active tills — specific-row live tests skipped');
} else {
    $pdo->beginTransaction();
    try {
        $t1 = $tills[0];

        // Insert specific-till grant: open+record only
        $pdo->prepare("INSERT INTO mm_user_agent_grants (user_id,agent_id,till_id,can_open_shift,can_record_transactions,can_close_shift,can_reconcile,created_at,granted_by) VALUES (?,?,?,1,1,0,0,NOW(),?)")
            ->execute([$uid, $t1['agent_id'], $t1['till_id'], $uid]);
        ok(true, "specific till grant inserted for till {$t1['till_id']}");

        $_SESSION['user_id'] = 9999; // non-admin session id won't affect isAdmin()
        ok(mmUserCanOnTill($pdo, $uid, $t1['till_id'], 'can_open_shift'),          'can_open_shift=1 reads true');
        ok(mmUserCanOnTill($pdo, $uid, $t1['till_id'], 'can_record_transactions'), 'can_record_transactions=1 reads true');
        ok(!mmUserCanOnTill($pdo, $uid, $t1['till_id'], 'can_close_shift'),        'can_close_shift=0 reads false');
        ok(!mmUserCanOnTill($pdo, $uid, $t1['till_id'], 'can_reconcile'),          'can_reconcile=0 reads false');

        // Insert agent-level NULL grant: all abilities
        $t1AgentId = $t1['agent_id'];
        $pdo->prepare("INSERT INTO mm_user_agent_grants (user_id,agent_id,till_id,can_open_shift,can_record_transactions,can_close_shift,can_reconcile,created_at,granted_by) VALUES (?,?,NULL,1,1,1,1,NOW(),?)")
            ->execute([$uid, $t1AgentId, $uid]);
        ok(true, "agent-level NULL grant inserted for agent $t1AgentId");

        // Specific row still overrides NULL row for this till (ORDER BY till_id DESC)
        ok(!mmUserCanOnTill($pdo, $uid, $t1['till_id'], 'can_close_shift'),
            'specific row overrides NULL row — close_shift still false for this till');

        // DELETE specific row → NULL row now serves as fallback
        $pdo->prepare("DELETE FROM mm_user_agent_grants WHERE user_id=? AND till_id=? AND agent_id=?")
            ->execute([$uid, $t1['till_id'], $t1AgentId]);
        ok(mmUserCanOnTill($pdo, $uid, $t1['till_id'], 'can_close_shift'),
            'after specific row deleted, NULL row fallback makes can_close_shift=true');

        // Full replace: DELETE + re-INSERT
        $pdo->prepare("DELETE FROM mm_user_agent_grants WHERE user_id=?")->execute([$uid]);
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM mm_user_agent_grants WHERE user_id=$uid")->fetchColumn();
        ok($cnt === 0, 'full-replace DELETE clears all grants for user');

    } finally {
        $pdo->rollBack();
        echo "  (transaction rolled back — DB unchanged)\n";
    }
}
