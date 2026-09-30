<?php
/**
 * Shared fixture for the MM agent-scope test suites (not a suite itself).
 *
 * Agents (code prefix ZZSCOPE):  A active (tills A1, A2, A3-closed) · B active (B1)
 *                                C suspended (C1) · D closed (D1)
 * Fake users (no users row needed — grants have no FK):
 *   U_FULL    990001  agent-wide grant on A (all abilities) + agent-wide on B (all)
 *   U_TILL    990002  till-only grant on A1 (open/record/close, no reconcile)
 *   U_NONE    990003  no grant at all
 *   U_DEAD    990004  grants only on C (suspended) and D (closed)
 *   U_NOREC   990005  agent-wide grant on A with can_record_transactions = 0
 *   U_MIXED   990006  agent-wide on A (no reconcile) + till-specific A2 override (reconcile)
 */

const MMF_U_FULL = 990001, MMF_U_TILL = 990002, MMF_U_NONE = 990003,
      MMF_U_DEAD = 990004, MMF_U_NOREC = 990005, MMF_U_MIXED = 990006;

function mmFixtureDestroy(PDO $pdo): void
{
    $tillIds = $pdo->query("SELECT t.till_id FROM mm_tills t JOIN mm_agents a ON a.agent_id=t.agent_id WHERE a.agent_code LIKE 'ZZSCOPE%'")
                   ->fetchAll(PDO::FETCH_COLUMN);
    if ($tillIds) {
        $in = implode(',', array_map('intval', $tillIds));
        $pdo->exec("DELETE FROM mm_kyc_records WHERE mm_txn_id IN (SELECT mm_txn_id FROM mm_transactions WHERE till_id IN ($in))");
        foreach (['mm_transactions', 'mm_shifts', 'mm_float_movements', 'mm_float_snapshots', 'mm_reconciliations'] as $t) {
            $pdo->exec("DELETE FROM $t WHERE till_id IN ($in)");
        }
        $pdo->exec("DELETE FROM mm_tills WHERE till_id IN ($in)");
    }
    $pdo->exec("DELETE FROM mm_user_agent_grants WHERE user_id BETWEEN 990001 AND 990099
                OR agent_id IN (SELECT agent_id FROM mm_agents WHERE agent_code LIKE 'ZZSCOPE%')");
    $pdo->exec("DELETE FROM mm_agents WHERE agent_code LIKE 'ZZSCOPE%'");
}

/** @return array{agents: array<string,int>, tills: array<string,int>, network_id: int} */
function mmFixtureCreate(PDO $pdo): array
{
    mmFixtureDestroy($pdo);
    $net = (int)$pdo->query("SELECT network_id FROM mm_networks WHERE status='active' ORDER BY network_id LIMIT 1")->fetchColumn();
    if (!$net) throw new RuntimeException('Fixture needs at least one active mm_networks row');

    $agents = [];
    foreach (['A' => 'active', 'B' => 'active', 'C' => 'suspended', 'D' => 'closed'] as $k => $st) {
        $pdo->prepare("INSERT INTO mm_agents (agent_code, agent_name, status) VALUES (?,?,?)")
            ->execute(["ZZSCOPE-$k", "ZZSCOPE Agent $k", $st]);
        $agents[$k] = (int)$pdo->lastInsertId();
    }
    $tills = [];
    foreach (['A1' => ['A', 'active'], 'A2' => ['A', 'active'], 'A3' => ['A', 'closed'],
              'B1' => ['B', 'active'], 'C1' => ['C', 'active'], 'D1' => ['D', 'active']] as $k => [$ag, $st]) {
        $pdo->prepare("INSERT INTO mm_tills (agent_id, network_id, till_number, status) VALUES (?,?,?,?)")
            ->execute([$agents[$ag], $net, "ZZSCOPE-$k", $st]);
        $tills[$k] = (int)$pdo->lastInsertId();
    }
    $g = $pdo->prepare("INSERT INTO mm_user_agent_grants
        (user_id, agent_id, till_id, can_open_shift, can_record_transactions, can_close_shift, can_reconcile)
        VALUES (?,?,?,?,?,?,?)");
    $g->execute([MMF_U_FULL,  $agents['A'], null,        1, 1, 1, 1]);
    $g->execute([MMF_U_FULL,  $agents['B'], null,        1, 1, 1, 1]);
    $g->execute([MMF_U_TILL,  $agents['A'], $tills['A1'], 1, 1, 1, 0]);
    $g->execute([MMF_U_DEAD,  $agents['C'], null,        1, 1, 1, 1]);
    $g->execute([MMF_U_DEAD,  $agents['D'], null,        1, 1, 1, 1]);
    $g->execute([MMF_U_NOREC, $agents['A'], null,        1, 0, 1, 0]);
    $g->execute([MMF_U_MIXED, $agents['A'], null,        1, 1, 1, 0]);
    $g->execute([MMF_U_MIXED, $agents['A'], $tills['A2'], 1, 1, 1, 1]);

    return ['agents' => $agents, 'tills' => $tills, 'network_id' => $net];
}

/** Switch the simulated session user (non-admin unless $admin). */
function mmFixtureAs(int $userId, bool $admin = false): void
{
    $_SESSION['user_id']  = $userId;
    $_SESSION['is_admin'] = $admin;
    $_SESSION['role_id']  = $admin ? 1 : 4;
    $_SESSION['permissions'] = [];
    if (function_exists('mmScopeReset')) mmScopeReset();
}

/** Force the tenant feature map: MM-only (true) or multi-module (false). */
function mmFixtureTenant(bool $mmOnly): void
{
    $GLOBALS['__bms_features'] = $mmOnly
        ? ['mobile_money' => true, 'finance' => true, 'settings' => true, 'sales' => false, 'pos' => false, 'procurement' => false]
        : ['mobile_money' => true, 'finance' => true, 'settings' => true, 'sales' => true, 'pos' => true, 'procurement' => true];
    if (function_exists('mmScopeReset')) mmScopeReset();
}
