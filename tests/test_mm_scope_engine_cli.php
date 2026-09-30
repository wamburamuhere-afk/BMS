<?php
/**
 * MM agent-scope — Phase 1: scope engine (core/mm_scope.php)
 *   php tests/test_mm_scope_engine_cli.php
 *
 * Runs against the live local DB inside a transaction that is rolled back.
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/mm_float_service.php";
require_once __DIR__ . '/mm_scope_fixture.inc.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});
if (session_status() === PHP_SESSION_NONE) @session_start();

section('Lint');
foreach (['core/mm_scope.php', 'core/mm_float_service.php', 'roots.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok($rc === 0, "$f lint-clean");
}
section('Loaded');
foreach (['mmScopeGrantMap','mmScopeAgentIds','mmScopeTillIds','mmHasAnyGrant','mmTillInScope','mmAgentInScope',
          'mmScopeSql','mmHasOwnOpenShift','mmGrantAllowsPage','mmRequireTill','mmDenyToDashboard','mmScopeReset'] as $fn) {
    ok(function_exists($fn), "$fn() defined (via roots.php)");
}

$pdo->beginTransaction();
try {
    $fx = mmFixtureCreate($pdo);
    $A = $fx['agents']; $T = $fx['tills'];

    section('Admin — never scoped');
    mmFixtureAs(1, true);
    ok(mmScopeTillIds() === null && mmScopeAgentIds() === null, 'admin: till/agent ids = null (all)');
    ok(mmScopeSql('t.till_id') === '', 'admin: mmScopeSql = empty string');
    ok(mmHasAnyGrant(), 'admin: mmHasAnyGrant true');
    ok(mmTillInScope($T['D1'], 'can_open_shift'), 'admin: even closed-agent till allowed');

    section('U_FULL — agent-wide on A and B');
    mmFixtureAs(MMF_U_FULL);
    $ids = mmScopeTillIds(); sort($ids);
    $exp = [$T['A1'], $T['A2'], $T['A3'], $T['B1']]; sort($exp);
    ok($ids === $exp, 'view tills = A1,A2,A3(closed till history),B1');
    $agents = mmScopeAgentIds(); sort($agents);
    $expA = [$A['A'], $A['B']]; sort($expA);
    ok($agents === $expA, 'agents = A,B');
    ok(!in_array($T['C1'], $ids, true) && !in_array($T['D1'], $ids, true), 'C1/D1 not visible');
    $open = mmScopeTillIds('can_open_shift');
    ok(!in_array($T['A3'], $open, true), 'closed till A3 cannot open shift');
    ok(in_array($T['A1'], $open, true) && in_array($T['B1'], $open, true), 'A1/B1 can open shift');
    ok(mmScopeSql('t.till_id') === ' AND t.till_id IN (' . implode(',', mmScopeTillIds()) . ') ', 'mmScopeSql builds IN list');
    ok(strpos(mmScopeSql('a.agent_id', 'agent'), 'a.agent_id IN (') !== false, "mmScopeSql kind=agent");

    section('U_TILL — till-only grant on A1, no reconcile');
    mmFixtureAs(MMF_U_TILL);
    ok(mmScopeTillIds() === [$T['A1']], 'only A1 visible (A2 not leaked)');
    ok(mmTillInScope($T['A1'], 'can_record_transactions'), 'A1 record allowed');
    ok(!mmTillInScope($T['A1'], 'can_reconcile'), 'A1 reconcile denied');
    ok(!mmTillInScope($T['A2']), 'A2 denied');
    ok(mmAgentInScope($A['A']) && !mmAgentInScope($A['B']), 'agent A in scope, B not');

    section('U_NONE — no grant');
    mmFixtureAs(MMF_U_NONE);
    ok(mmScopeTillIds() === [] && mmScopeAgentIds() === [], 'empty tills and agents');
    ok(!mmHasAnyGrant(), 'mmHasAnyGrant false');
    ok(mmScopeSql('t.till_id') === ' AND 1=0 ', 'empty scope → AND 1=0 (never empty IN, never unfiltered)');
    ok(mmScopeSql('x.agent_id', 'agent') === ' AND 1=0 ', 'agent kind empty → AND 1=0');

    section('U_DEAD — grants on suspended C and closed D only');
    mmFixtureAs(MMF_U_DEAD);
    ok(mmScopeAgentIds() === [$A['C']], 'closed agent D ignored; suspended C kept');
    ok(mmTillInScope($T['C1']), 'suspended agent till C1 viewable');
    ok(!mmTillInScope($T['C1'], 'can_open_shift'), 'suspended: cannot open shift');
    ok(!mmTillInScope($T['C1'], 'can_record_transactions'), 'suspended: cannot record');
    ok(mmTillInScope($T['C1'], 'can_close_shift'), 'suspended: can still close shift');
    ok(!mmTillInScope($T['D1']), 'closed agent till D1 not viewable');

    section('U_NOREC / U_MIXED — ability flags + override');
    mmFixtureAs(MMF_U_NOREC);
    ok(!mmTillInScope($T['A1'], 'can_record_transactions'), 'NOREC: record denied');
    ok(mmTillInScope($T['A1'], 'can_open_shift'), 'NOREC: open allowed');
    ok(!mmTillInScope($T['A1'], 'bogus_flag'), 'unknown ability denied');
    mmFixtureAs(MMF_U_MIXED);
    ok(!mmTillInScope($T['A1'], 'can_reconcile'), 'MIXED: agent-wide grant → A1 reconcile denied');
    ok(mmTillInScope($T['A2'], 'can_reconcile'), 'MIXED: till-specific override → A2 reconcile allowed');

    section('Cache + mmUserCanOnTill fresh read');
    mmFixtureAs(MMF_U_TILL);
    ok(mmTillInScope($T['A1']), 'A1 in scope (cached)');
    $pdo->prepare("DELETE FROM mm_user_agent_grants WHERE user_id=?")->execute([MMF_U_TILL]);
    ok(mmTillInScope($T['A1']), 'same request: cache still answers (per-request cache)');
    ok(!mmUserCanOnTill($pdo, MMF_U_TILL, $T['A1'], 'can_open_shift'), 'mmUserCanOnTill reads fresh → revoked');
    mmScopeReset();
    ok(!mmTillInScope($T['A1']), 'after mmScopeReset: revoked');

    section('mmHasOwnOpenShift');
    $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, status) VALUES ('ZZSCOPE-S1', ?, ?, NOW(), 'open')")
        ->execute([$T['A1'], MMF_U_TILL]);
    mmFixtureAs(MMF_U_TILL);
    ok(mmHasOwnOpenShift(), 'revoked teller with an open shift detected');
    mmFixtureAs(MMF_U_NONE);
    ok(!mmHasOwnOpenShift(), 'U_NONE has no open shift');

    section('mmUserCanOnTill — admin bypass kept');
    mmFixtureAs(1, true);
    ok(mmUserCanOnTill($pdo, 1, $T['D1'], 'can_open_shift'), 'admin bypass');
} finally {
    $pdo->rollBack();
    echo "  (fixture transaction rolled back)\n";
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM mm_agents WHERE agent_code LIKE 'ZZSCOPE%'")->fetchColumn();
ok($left === 0, 'no fixture rows left behind');
