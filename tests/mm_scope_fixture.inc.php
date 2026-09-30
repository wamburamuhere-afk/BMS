<?php
/**
 * Shared fixture for the MM agent-scope test suites (not a suite itself).
 *
 * Agents (code prefix ZZSCOPE):  A active (tills A1, A2, A3-closed) · B active (B1)
 *                                C suspended (C1) · D closed (D1)
 * Roles:  'ZZSCOPE Full'  (non-admin, every permission key granted)
 *         'ZZSCOPE Empty' (non-admin, no permissions)
 * Users (real rows, username zzscope_*), role Full unless noted:
 *   FULL    agent-wide grant on A and B (all abilities)
 *   TILL    till-only grant on A1 (open/record/close, no reconcile)
 *   NONE    no grant at all
 *   DEAD    grants only on C (suspended) and D (closed)
 *   NOREC   agent-wide grant on A, can_record_transactions = 0, no reconcile
 *   MIXED   agent-wide on A (no reconcile) + till-specific A2 override (reconcile)
 *   NOROLE  role Empty, agent-wide grant on A
 * Admin: an existing user whose role has is_admin = 1.
 */

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
    $userIds = $pdo->query("SELECT user_id FROM users WHERE username LIKE 'zzscope\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($userIds) {
        $uin = implode(',', array_map('intval', $userIds));
        $pdo->exec("DELETE FROM mm_user_agent_grants WHERE user_id IN ($uin)");
        $pdo->exec("DELETE FROM mm_shifts WHERE teller_user_id IN ($uin)");
        $pdo->exec("DELETE FROM users WHERE user_id IN ($uin)");
    }
    $pdo->exec("DELETE FROM mm_user_agent_grants WHERE agent_id IN (SELECT agent_id FROM mm_agents WHERE agent_code LIKE 'ZZSCOPE%')");
    $pdo->exec("DELETE FROM mm_agents WHERE agent_code LIKE 'ZZSCOPE%'");
    $pdo->exec("DELETE FROM role_permissions WHERE role_id IN (SELECT role_id FROM roles WHERE role_name LIKE 'ZZSCOPE %')");
    $pdo->exec("DELETE FROM roles WHERE role_name LIKE 'ZZSCOPE %'");
}

/**
 * @return array{agents: array<string,int>, tills: array<string,int>, users: array<string,int>,
 *               roles: array<string,int>, admin_id: int, network_id: int}
 */
function mmFixtureCreate(PDO $pdo): array
{
    mmFixtureDestroy($pdo);
    $net = (int)$pdo->query("SELECT network_id FROM mm_networks WHERE status='active' ORDER BY network_id LIMIT 1")->fetchColumn();
    if (!$net) throw new RuntimeException('Fixture needs at least one active mm_networks row');
    $adminId = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
    if (!$adminId) throw new RuntimeException('Fixture needs an existing admin user');

    $roles = [];
    foreach (['Full', 'Empty'] as $r) {
        $pdo->prepare("INSERT INTO roles (role_name, is_admin, description) VALUES (?, 0, 'MM scope test fixture')")
            ->execute(["ZZSCOPE $r"]);
        $roles[$r] = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
                   SELECT ?, permission_id, 1, 1, 1, 1, 1, 1 FROM permissions")->execute([$roles['Full']]);

    $users = [];
    foreach (['FULL' => 'Full', 'TILL' => 'Full', 'NONE' => 'Full', 'DEAD' => 'Full',
              'NOREC' => 'Full', 'MIXED' => 'Full', 'NOROLE' => 'Empty'] as $k => $role) {
        $pdo->prepare("INSERT INTO users (username, password, email, first_name, last_name, role_id, is_active)
                       VALUES (?, ?, ?, 'ZZSCOPE', ?, ?, 1)")
            ->execute(['zzscope_' . strtolower($k), password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                       'zzscope_' . strtolower($k) . '@example.invalid', $k, $roles[$role]]);
        $users[$k] = (int)$pdo->lastInsertId();
    }

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
    $U = $users;
    $g->execute([$U['FULL'],   $agents['A'], null,         1, 1, 1, 1]);
    $g->execute([$U['FULL'],   $agents['B'], null,         1, 1, 1, 1]);
    $g->execute([$U['TILL'],   $agents['A'], $tills['A1'], 1, 1, 1, 0]);
    $g->execute([$U['DEAD'],   $agents['C'], null,         1, 1, 1, 1]);
    $g->execute([$U['DEAD'],   $agents['D'], null,         1, 1, 1, 1]);
    $g->execute([$U['NOREC'],  $agents['A'], null,         1, 0, 1, 0]);
    $g->execute([$U['MIXED'],  $agents['A'], null,         1, 1, 1, 0]);
    $g->execute([$U['MIXED'],  $agents['A'], $tills['A2'], 1, 1, 1, 1]);
    $g->execute([$U['NOROLE'], $agents['A'], null,         1, 1, 1, 1]);

    return ['agents' => $agents, 'tills' => $tills, 'users' => $users, 'roles' => $roles,
            'admin_id' => $adminId, 'network_id' => $net];
}

/** Insert one posted transaction dated today on a fixture till; returns mm_txn_id. */
function mmFixtureTxn(PDO $pdo, array $fx, string $tillKey, float $amount, int $tellerId, string $status = 'posted'): int
{
    static $n = 0; $n++;
    $st = $pdo->prepare("SELECT agent_id, network_id FROM mm_tills WHERE till_id=?");
    $st->execute([$fx['tills'][$tillKey]]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO mm_transactions (txn_code, till_id, network_id, agent_id, txn_type, txn_date, txn_time,
                   customer_phone, principal_amount, commission_earned, cash_effect, float_effect, teller_user_id, status)
                   VALUES (?, ?, ?, ?, 'cash_in', CURDATE(), CURTIME(), '255700000000', ?, ?, ?, ?, ?, ?)")
        ->execute(["ZZSCOPE-TX-$tillKey-$n-" . mt_rand(1000, 9999), $fx['tills'][$tillKey], $t['network_id'], $t['agent_id'],
                   $amount, round($amount / 100, 2), $amount, -$amount, $tellerId, $status]);
    return (int)$pdo->lastInsertId();
}

/** Switch the simulated in-process session user and load that user's real role permissions. */
function mmFixtureAs(PDO $pdo, int $userId): void
{
    $st = $pdo->prepare("SELECT u.role_id, COALESCE(r.is_admin,0) AS is_admin FROM users u LEFT JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['role_id' => 0, 'is_admin' => 0];
    $_SESSION['user_id']  = $userId;
    $_SESSION['role_id']  = (int)$row['role_id'];
    $_SESSION['is_admin'] = (bool)$row['is_admin'];
    $_SESSION['permissions'] = [];
    if (function_exists('loadUserPermissions')) loadUserPermissions((int)$row['role_id']);
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

/**
 * Run an app page / API in a clean PHP subprocess as $userId and return
 * ['out' => html/json, 'notice' => mm_scope_notice|null, 'location' => redirect|null, 'exit' => code].
 * Fixture rows must be COMMITTED (the subprocess has its own DB connection).
 */
function mmFixtureRun(string $relPath, int $userId, bool $mmOnly, array $get = [], array $post = [], array $session = []): array
{
    $harness = __DIR__ . '/mm_scope_harness.php';
    $payload = base64_encode(json_encode(['path' => $relPath, 'user' => $userId, 'mm_only' => $mmOnly,
                                          'get' => $get, 'post' => $post, 'session' => $session]));
    putenv('MMSCOPE_PAYLOAD=' . $payload);
    putenv('REDIRECT_STATUS=1');
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php-cgi.exe' : 'php-cgi');
    $cmd = escapeshellarg($cgi) . ' -f ' . escapeshellarg($harness) . ' 2>&1';
    $lines = []; $rc = 0;
    exec($cmd, $lines, $rc);
    $raw = implode("\n", $lines);
    // Strip the CGI header block (up to the first blank line).
    $parts = preg_split('/\R\R/', $raw, 2);
    if (count($parts) === 2 && preg_match('/^(Status|X-Powered-By|Content-type|Set-Cookie|Location|Expires|Cache-Control|Pragma)/mi', $parts[0])) {
        $raw = $parts[1];
    }
    $meta = ['notice' => null, 'location' => null];
    if (preg_match('/@@MMSCOPE@@(\{.*\})\s*$/s', $raw, $m)) {
        $meta = json_decode($m[1], true) ?: $meta;
        $raw  = substr($raw, 0, strpos($raw, '@@MMSCOPE@@'));
    }
    return ['out' => $raw, 'notice' => $meta['notice'] ?? null, 'location' => $meta['location'] ?? null, 'exit' => $rc];
}
