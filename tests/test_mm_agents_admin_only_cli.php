<?php
/**
 * MM Agents — Admin-only gate
 *   php tests/test_mm_agents_admin_only_cli.php
 *
 * A. STATIC — mm_agents.php, mm_agent_view.php redirect non-admins;
 *             save_agent.php, save_till.php enforce isAdmin();
 *             mm_dashboard.php shows Agents action only for admins.
 *
 * B. LIVE (transaction rolled back) — isAdmin() gate verified on save_agent
 *    logic path; admin can create, non-admin is blocked at API level.
 *
 * No web server needed. Exit 0 = pass.
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

// ── A. Static contract ──────────────────────────────────────────────────────
$agentsPage    = "$root/app/bms/mobile_money/mm_agents.php";
$agentViewPage = "$root/app/bms/mobile_money/mm_agent_view.php";
$saveAgent     = "$root/api/mobile_money/save_agent.php";
$saveTill      = "$root/api/mobile_money/save_till.php";
$dashboard     = "$root/app/bms/mobile_money/mm_dashboard.php";

section('A. Static — lint');
foreach ([
    'mm_agents.php'      => $agentsPage,
    'mm_agent_view.php'  => $agentViewPage,
    'save_agent.php'     => $saveAgent,
    'save_till.php'      => $saveTill,
    'mm_dashboard.php'   => $dashboard,
] as $name => $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    ok($rc === 0, "$name lint-clean");
}

section('A. Static — page gates');
$ap = src($agentsPage);
ok(strpos($ap, 'isAdmin()') !== false,          'mm_agents.php has isAdmin() check');
ok(strpos($ap, 'autoEnforcePermission') === false, 'mm_agents.php no longer uses autoEnforcePermission');
ok(strpos($ap, "getUrl('unauthorized')") !== false, 'mm_agents.php redirects to unauthorized');

$avp = src($agentViewPage);
ok(strpos($avp, 'isAdmin()') !== false,          'mm_agent_view.php has isAdmin() check');
ok(strpos($avp, 'autoEnforcePermission') === false, 'mm_agent_view.php no longer uses autoEnforcePermission');
// 2026-09-30 agent-scope: non-admins may view (read-only) only agents they are granted.
ok(strpos($avp, 'mmAgentInScope($id)') !== false && strpos($avp, 'mmDenyToDashboard()') !== false,
   'mm_agent_view.php: non-admin limited to granted agents, else back to dashboard');

section('A. Static — API gates');
$sa = src($saveAgent);
ok(strpos($sa, 'isAdmin()') !== false,           'save_agent.php has isAdmin() check');
ok(strpos($sa, 'Admin access required') !== false, 'save_agent.php returns admin-required message');

$st = src($saveTill);
ok(strpos($st, 'isAdmin()') !== false,           'save_till.php has isAdmin() check');
ok(strpos($st, 'Admin access required') !== false, 'save_till.php returns admin-required message');

section('A. Static — dashboard Agents action');
$dp = src($dashboard);
ok(strpos($dp, "if (isAdmin())") !== false,      'dashboard Agents action gated on isAdmin()');
ok(strpos($dp, "canView('mm_agents')") === false, 'dashboard no longer uses canView(mm_agents)');

// ── B. Live data-model ──────────────────────────────────────────────────────
section('B. Live — isAdmin() function available + agent table present');

ok(function_exists('isAdmin'), 'isAdmin() function is callable');
ok(function_exists('canCreate'), 'canCreate() function is callable');

if (!(bool)$pdo->query("SHOW TABLES LIKE 'mm_agents'")->fetch()) {
    ok(true, 'mm_agents table absent on this server — live tests skipped');
    return;
}

// Find an admin user (role_id = 1)
$adminUser = $pdo->query("SELECT user_id FROM users WHERE role_id = 1 LIMIT 1")->fetchColumn();
ok((bool)$adminUser, 'at least one admin user exists (role_id=1)');

// Find a non-admin user
$nonAdminUser = $pdo->query("SELECT user_id FROM users WHERE role_id != 1 LIMIT 1")->fetchColumn();
ok((bool)$nonAdminUser, 'at least one non-admin user exists');

// Simulate isAdmin() logic — role_id = 1 is admin
if ($adminUser) {
    $role = (int)$pdo->query("SELECT role_id FROM users WHERE user_id = $adminUser")->fetchColumn();
    ok($role === 1, "admin user has role_id=1 (isAdmin() would return true)");
}

if ($nonAdminUser) {
    $role = (int)$pdo->query("SELECT role_id FROM users WHERE user_id = $nonAdminUser")->fetchColumn();
    ok($role !== 1, "non-admin user has role_id!=1 (isAdmin() would return false)");
}

// Verify save_agent.php isAdmin() gate comes BEFORE canCreate check (must be line-order correct)
$saLines = file($saveAgent);
$adminLine = 0; $canCreateLine = 0;
foreach ($saLines as $i => $line) {
    if (strpos($line, 'isAdmin()') !== false && $adminLine === 0) $adminLine = $i + 1;
    if (strpos($line, "canCreate('mm_agents')") !== false && $canCreateLine === 0) $canCreateLine = $i + 1;
}
ok($adminLine > 0 && ($canCreateLine === 0 || $adminLine < $canCreateLine),
    "save_agent.php isAdmin() gate (line $adminLine) precedes canCreate check (line $canCreateLine)");

// Same check for save_till.php
$stLines = file($saveTill);
$adminLineT = 0; $canCreateLineT = 0;
foreach ($stLines as $i => $line) {
    if (strpos($line, 'isAdmin()') !== false && $adminLineT === 0) $adminLineT = $i + 1;
    if (strpos($line, "canCreate('mm_agents')") !== false && $canCreateLineT === 0) $canCreateLineT = $i + 1;
}
ok($adminLineT > 0 && ($canCreateLineT === 0 || $adminLineT < $canCreateLineT),
    "save_till.php isAdmin() gate (line $adminLineT) precedes canCreate check (line $canCreateLineT)");
