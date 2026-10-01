<?php
/**
 * core/mm_scope.php — Mobile Money agent-grant scoping.
 *
 * A non-admin's MM access = role permission AND a row in mm_user_agent_grants.
 * Admins (isAdmin()) are never scoped. See mm_agent_scope_plan.md.
 *
 * Grant semantics:
 *   - grant.till_id NULL  → every till of that agent; a till-specific grant overrides it for that till.
 *   - agent 'closed'      → grant ignored entirely.
 *   - agent/till 'suspended' → history visible, but no open-shift / record-transaction.
 */

if (!defined('MM_SCOPE_ABILITIES')) {
    define('MM_SCOPE_ABILITIES', ['can_open_shift', 'can_record_transactions', 'can_close_shift', 'can_reconcile']);
    // Abilities that need a live (active) agent and till.
    define('MM_SCOPE_LIVE_ABILITIES', ['can_open_shift', 'can_record_transactions']);
    // Reachable by any logged-in user even without a grant (MM-only tenant lock-down whitelist).
    define('MM_SCOPE_ALWAYS_KEYS', ['mm_dashboard', 'profile', 'my_settings', 'my_hr', 'help']);
    // MM pages whose writes are company-level → admin only, whatever the role says.
    define('MM_SCOPE_ADMIN_WRITE_KEYS', ['mm_agents', 'mm_networks', 'mm_user_agent_grants', 'mm_commission_rates', 'mm_commissions']);
}

if (!function_exists('mmScopeReset')) {
    function mmScopeReset(): void
    {
        $GLOBALS['__mm_scope_cache'] = [];
    }
}

if (!function_exists('mmScopeUserId')) {
    function mmScopeUserId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('mmScopeAll')) {
    /** True when the current user sees every agent (admin). */
    function mmScopeAll(): bool
    {
        return function_exists('isAdmin') && isAdmin();
    }
}

if (!function_exists('mmScopeGrantMap')) {
    /**
     * [till_id => ['agent_id','agent_status','till_status', ability flags…]] for the user,
     * with till-specific grants overriding agent-wide ones. Cached per request per user.
     */
    function mmScopeGrantMap(?int $userId = null): array
    {
        $userId = $userId ?? mmScopeUserId();
        $cache  = &$GLOBALS['__mm_scope_cache'];
        if (!is_array($cache)) $cache = [];
        if (isset($cache["tills:$userId"])) return $cache["tills:$userId"];

        $map = [];
        if ($userId > 0) {
            global $pdo;
            try {
                $stmt = $pdo->prepare("
                    SELECT t.till_id, t.agent_id, t.status AS till_status, a.status AS agent_status,
                           g.till_id AS grant_till_id,
                           g.can_open_shift, g.can_record_transactions, g.can_close_shift, g.can_reconcile
                    FROM mm_user_agent_grants g
                    JOIN mm_agents a ON a.agent_id = g.agent_id AND a.status <> 'closed'
                    JOIN mm_tills  t ON t.agent_id = g.agent_id AND (g.till_id IS NULL OR g.till_id = t.till_id)
                    WHERE g.user_id = ?
                    ORDER BY (g.till_id IS NULL) DESC
                ");
                $stmt->execute([$userId]);
                // Agent-wide rows come first; a till-specific row then overwrites its till.
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $map[(int)$r['till_id']] = [
                        'agent_id'                => (int)$r['agent_id'],
                        'agent_status'            => $r['agent_status'],
                        'till_status'             => $r['till_status'],
                        'can_open_shift'          => (bool)$r['can_open_shift'],
                        'can_record_transactions' => (bool)$r['can_record_transactions'],
                        'can_close_shift'         => (bool)$r['can_close_shift'],
                        'can_reconcile'           => (bool)$r['can_reconcile'],
                    ];
                }
            } catch (PDOException $e) {
                error_log('mmScopeGrantMap: ' . $e->getMessage());
                $map = [];
            }
        }
        return $cache["tills:$userId"] = $map;
    }
}

if (!function_exists('mmScopeAgentIds')) {
    /** Granted agent ids (closed agents excluded; includes agents with no tills yet). null = admin/all. */
    function mmScopeAgentIds(?int $userId = null): ?array
    {
        if ($userId === null && mmScopeAll()) return null;
        $userId = $userId ?? mmScopeUserId();
        $cache  = &$GLOBALS['__mm_scope_cache'];
        if (!is_array($cache)) $cache = [];
        if (isset($cache["agents:$userId"])) return $cache["agents:$userId"];

        $ids = [];
        if ($userId > 0) {
            global $pdo;
            try {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT g.agent_id
                    FROM mm_user_agent_grants g
                    JOIN mm_agents a ON a.agent_id = g.agent_id AND a.status <> 'closed'
                    WHERE g.user_id = ?
                ");
                $stmt->execute([$userId]);
                $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            } catch (PDOException $e) {
                error_log('mmScopeAgentIds: ' . $e->getMessage());
            }
        }
        return $cache["agents:$userId"] = $ids;
    }
}

if (!function_exists('mmScopeTillAllows')) {
    /** Does one grant-map entry allow $ability (null = view)? */
    function mmScopeTillAllows(array $g, ?string $ability): bool
    {
        if ($ability === null) return true;
        if (!in_array($ability, MM_SCOPE_ABILITIES, true)) return false;
        if (empty($g[$ability])) return false;
        if (in_array($ability, MM_SCOPE_LIVE_ABILITIES, true)) {
            return $g['agent_status'] === 'active' && $g['till_status'] === 'active';
        }
        return $g['till_status'] !== 'closed';
    }
}

if (!function_exists('mmScopeTillIds')) {
    /** Till ids the user may see (null ability) or act on. null = admin/all. */
    function mmScopeTillIds(?string $ability = null, ?int $userId = null): ?array
    {
        if ($userId === null && mmScopeAll()) return null;
        $ids = [];
        foreach (mmScopeGrantMap($userId) as $tillId => $g) {
            if (mmScopeTillAllows($g, $ability)) $ids[] = (int)$tillId;
        }
        return $ids;
    }
}

if (!function_exists('mmHasAnyGrant')) {
    function mmHasAnyGrant(?int $userId = null): bool
    {
        if ($userId === null && mmScopeAll()) return true;
        return count(mmScopeAgentIds($userId) ?? []) > 0;
    }
}

if (!function_exists('mmTillInScope')) {
    function mmTillInScope(int $tillId, ?string $ability = null, ?int $userId = null): bool
    {
        if ($userId === null && mmScopeAll()) return true;
        $map = mmScopeGrantMap($userId);
        return isset($map[$tillId]) && mmScopeTillAllows($map[$tillId], $ability);
    }
}

if (!function_exists('mmAgentInScope')) {
    function mmAgentInScope(int $agentId, ?int $userId = null): bool
    {
        if ($userId === null && mmScopeAll()) return true;
        return in_array($agentId, mmScopeAgentIds($userId) ?? [], true);
    }
}

if (!function_exists('mmScopeSql')) {
    /**
     * SQL fragment restricting $col to the user's scope.
     *   admin → ''   |   ids → " AND col IN (1,2)"   |   none → ' AND 1=0 '
     * $kind: 'till' (col holds a till_id) or 'agent' (col holds an agent_id).
     */
    function mmScopeSql(string $col, string $kind = 'till', ?string $ability = null): string
    {
        if (mmScopeAll()) return '';
        $ids = $kind === 'agent' ? mmScopeAgentIds() : mmScopeTillIds($ability);
        if (!$ids) return ' AND 1=0 ';
        return " AND $col IN (" . implode(',', array_map('intval', $ids)) . ') ';
    }
}

if (!function_exists('mmHasOwnOpenShift')) {
    /** A teller whose grant was revoked mid-shift may still close their own open shift. */
    function mmHasOwnOpenShift(?int $userId = null): bool
    {
        $userId = $userId ?? mmScopeUserId();
        if ($userId <= 0) return false;
        $cache = &$GLOBALS['__mm_scope_cache'];
        if (!is_array($cache)) $cache = [];
        if (isset($cache["ownshift:$userId"])) return $cache["ownshift:$userId"];
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM mm_shifts WHERE teller_user_id = ? AND status = 'open' LIMIT 1");
            $stmt->execute([$userId]);
            $has = (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $has = false;
        }
        return $cache["ownshift:$userId"] = $has;
    }
}

if (!function_exists('mmScopeIsMmOnlyTenant')) {
    function mmScopeIsMmOnlyTenant(): bool
    {
        return function_exists('tenantOnlyHasModule') && tenantOnlyHasModule('mobile_money');
    }
}

if (!function_exists('mmGrantAllowsPage')) {
    /**
     * The permission-layer question: may this non-admin reach $pageKey given their agent grants?
     * Called by every canX() after the admin bypass. $action: view|create|edit|delete|workflow.
     *   - mm_* keys: need a grant (mm_dashboard always open; mm_shifts open to a teller with an
     *     own open shift so they can close it). Company-level MM writes are admin only.
     *   - MM-only tenant: a user with no grant reaches only MM_SCOPE_ALWAYS_KEYS.
     */
    function mmGrantAllowsPage(string $pageKey, string $action = 'view'): bool
    {
        if (mmScopeAll()) return true;

        $isMmKey = strncmp($pageKey, 'mm_', 3) === 0;
        if (!$isMmKey && !mmScopeIsMmOnlyTenant()) return true;

        if ($isMmKey && $action !== 'view' && in_array($pageKey, MM_SCOPE_ADMIN_WRITE_KEYS, true)) {
            return false;
        }
        if (in_array($pageKey, MM_SCOPE_ALWAYS_KEYS, true)) return true;
        if (mmHasAnyGrant()) return true;
        if ($pageKey === 'mm_shifts' && mmHasOwnOpenShift()) return true;
        return false;
    }
}

if (!function_exists('mmRequireTill')) {
    /** API guard: JSON 403 + exit unless the till is in scope for $ability. */
    function mmRequireTill(int $tillId, ?string $ability = null, string $message = 'This till is not assigned to you.'): void
    {
        if (mmTillInScope($tillId, $ability)) return;
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}

if (!function_exists('mmDenyToDashboard')) {
    /** Page guard target: send the user back to the MM dashboard with a notice. */
    function mmDenyToDashboard(string $notice = 'not_assigned'): void
    {
        $_SESSION['mm_scope_notice'] = $notice;
        header('Location: ' . getUrl('mm_dashboard'));
        exit;
    }
}
