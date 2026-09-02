<?php
/**
 * core/tenant_admin.php
 * ---------------------
 * Tenant lifecycle operations for platform operators: suspend, activate, delete.
 *
 * The invariant every function here preserves: ONE TENANT AT A TIME. Suspending
 * or deleting a company must have no effect whatsoever on any other tenant, or
 * on the main application. Each operation touches exactly one registry row and,
 * for delete, exactly one database and one MySQL user — all named from that
 * row's own stored values, never computed or pattern-matched.
 *
 * Every operation is attributed. Cutting a company off from its own system, or
 * destroying its data, is not something the platform does anonymously.
 *
 * Public API:
 *   listTenants(?string $status = null): array
 *   getTenant(int $id): ?array
 *   tenantStats(): array
 *   suspendTenant(int $id, string $reason = ''): array
 *   activateTenant(int $id): array
 *   deleteTenant(int $id, string $typedName): array
 *   tenantAdminLog(?int $tenantId = null, int $limit = 50): array
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_provisioner.php';
require_once __DIR__ . '/superadmin_auth.php';

if (!function_exists('logTenantAdminAction')) {
    /**
     * Record a lifecycle action against a tenant.
     *
     * The operator's email is copied in rather than referenced, so the record of
     * who deleted a company survives that operator's own account being removed.
     */
    function logTenantAdminAction(?int $tenantId, ?string $subdomain, string $action, ?string $detail = null): void
    {
        try {
            $me = currentSuperadmin();
            getControlPdo()->prepare("
                INSERT INTO tenant_admin_log
                    (superadmin_id, actor_email, tenant_id, subdomain, action, detail, ip_address)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                $me['id'] ?? null,
                $me['email'] ?? null,
                $tenantId, $subdomain, $action,
                $detail === null ? null : substr($detail, 0, 500),
                substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
            ]);
        } catch (Throwable $e) {
            error_log('tenant_admin_log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('listTenants')) {
    /** All tenants, newest first. Never returns encrypted credentials. */
    function listTenants(?string $status = null): array
    {
        $sql = "SELECT id, company_name, subdomain, db_name, db_username, db_host,
                       status, plan, owner_email, created_at, activated_at, suspended_at
                FROM tenants";
        $args = [];
        if ($status !== null && $status !== '') {
            $sql .= " WHERE status = ?";
            $args[] = $status;
        }
        $sql .= " ORDER BY created_at DESC, id DESC";

        $st = getControlPdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }
}

if (!function_exists('getTenant')) {
    /**
     * One tenant. db_password_encrypted is deliberately NOT selected — no
     * superadmin page has any reason to hold a tenant's database password, so it
     * never enters a rendered page's variable scope in the first place.
     */
    function getTenant(int $id): ?array
    {
        $st = getControlPdo()->prepare("
            SELECT id, company_name, subdomain, db_name, db_username, db_host,
                   status, plan, owner_email, created_at, activated_at, suspended_at
            FROM tenants WHERE id = ? LIMIT 1
        ");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('tenantStats')) {
    /** Counts by status, with every status present even at zero. */
    function tenantStats(): array
    {
        $out = ['active' => 0, 'trial' => 0, 'suspended' => 0, 'deleted' => 0];
        foreach (getControlPdo()->query("SELECT status, COUNT(*) AS n FROM tenants GROUP BY status") as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        $out['total'] = array_sum($out);
        return $out;
    }
}

if (!function_exists('suspendTenant')) {
    /**
     * Cut one tenant off. Its subdomain starts returning "Account suspended"
     * on the very next request (core/tenant_bootstrap.php reads status live), and
     * no other tenant is touched.
     *
     * Nothing is destroyed: the database, its data and its users are untouched,
     * so activateTenant() restores service immediately.
     *
     * @return array{ok:bool, error:?string}
     */
    function suspendTenant(int $id, string $reason = ''): array
    {
        $t = getTenant($id);
        if (!$t) return ['ok' => false, 'error' => 'Tenant not found.'];
        if ($t['status'] === 'deleted') {
            return ['ok' => false, 'error' => 'This tenant has been deleted and cannot be suspended.'];
        }
        if ($t['status'] === 'suspended') {
            return ['ok' => true, 'error' => null];   // already there; not an error
        }

        getControlPdo()->prepare("UPDATE tenants SET status='suspended', suspended_at=NOW() WHERE id=?")
            ->execute([$id]);
        logTenantAdminAction($id, $t['subdomain'], 'suspend', $reason !== '' ? $reason : null);

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('activateTenant')) {
    /**
     * Restore service to one tenant.
     *
     * @return array{ok:bool, error:?string}
     */
    function activateTenant(int $id): array
    {
        $t = getTenant($id);
        if (!$t) return ['ok' => false, 'error' => 'Tenant not found.'];
        if ($t['status'] === 'deleted') {
            // Its database and MySQL user are gone; flipping a flag would produce
            // a tenant that resolves to nothing and fails at connection time.
            return ['ok' => false, 'error' => 'This tenant has been deleted. Its database no longer exists, so it cannot be reactivated.'];
        }
        if ($t['status'] === 'active') {
            return ['ok' => true, 'error' => null];
        }

        getControlPdo()->prepare("
            UPDATE tenants
               SET status='active', suspended_at=NULL,
                   activated_at = IFNULL(activated_at, NOW())
             WHERE id = ?
        ")->execute([$id]);
        logTenantAdminAction($id, $t['subdomain'], 'activate');

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('deleteTenant')) {
    /**
     * Permanently destroy one tenant: drop its database, drop its MySQL user,
     * and mark the registry row 'deleted'.
     *
     * THIS IS IRREVERSIBLE. The company's entire ledger, invoices, payroll and
     * documents are gone. There is no undo, and `git revert` does not bring data
     * back — which is why it demands the company name typed exactly.
     *
     * The registry row is KEPT (status='deleted', credentials blanked) rather
     * than removed, so the subdomain stays claimed and the audit trail still has
     * something to point at.
     *
     * @param string $typedName  must match company_name exactly (trimmed)
     * @return array{ok:bool, error:?string}
     */
    function deleteTenant(int $id, string $typedName): array
    {
        $t = getTenant($id);
        if (!$t) return ['ok' => false, 'error' => 'Tenant not found.'];

        if ($t['status'] === 'deleted') {
            return ['ok' => false, 'error' => 'This tenant has already been deleted.'];
        }

        // Typed confirmation, matching the destructive-action pattern used
        // elsewhere in this codebase. Compared case-sensitively after trimming:
        // a near-miss should stop, not proceed.
        if (trim($typedName) !== trim((string)$t['company_name'])) {
            logTenantAdminAction($id, $t['subdomain'], 'delete_refused', 'confirmation text did not match');
            return ['ok' => false, 'error' => 'The company name you typed does not match. Nothing was deleted.'];
        }

        // Names come from the tenant's OWN stored columns — never computed from
        // the id and never pattern-matched — so this cannot reach another
        // tenant's database even if the registry were inconsistent.
        $problems = destroyTenantResources($t['db_name'] ?: null, $t['db_username'] ?: null);

        // The row is updated even if a drop failed: the tenant is out of service
        // either way, and leaving it 'active' would be a lie. The failure is
        // surfaced and logged so an operator can finish the cleanup by hand.
        getControlPdo()->prepare("
            UPDATE tenants
               SET status = 'deleted',
                   suspended_at = IFNULL(suspended_at, NOW()),
                   db_password_encrypted = ''
             WHERE id = ?
        ")->execute([$id]);

        if ($problems) {
            logTenantAdminAction($id, $t['subdomain'], 'delete_partial', implode(' | ', $problems));
            error_log('Tenant delete left debris for ' . $t['subdomain'] . ': ' . implode(' | ', $problems));
            return ['ok' => false, 'error' =>
                'The tenant was marked deleted, but some resources could not be removed and need '
                . 'manual cleanup: ' . implode('; ', $problems)];
        }

        logTenantAdminAction($id, $t['subdomain'], 'delete',
            'dropped database ' . $t['db_name'] . ' and user ' . $t['db_username']);

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('tenantAdminLog')) {
    /** Recent lifecycle actions, newest first. */
    function tenantAdminLog(?int $tenantId = null, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        if ($tenantId !== null) {
            $st = getControlPdo()->prepare(
                "SELECT * FROM tenant_admin_log WHERE tenant_id = ? ORDER BY id DESC LIMIT $limit"
            );
            $st->execute([$tenantId]);
        } else {
            $st = getControlPdo()->query("SELECT * FROM tenant_admin_log ORDER BY id DESC LIMIT $limit");
        }
        return $st->fetchAll();
    }
}

if (!function_exists('operatorTenantLoginUrl')) {
    /** Absolute sign-in URL for a tenant's own subdomain. */
    function operatorTenantLoginUrl(string $subdomain): string
    {
        $base   = function_exists('tenantBaseDomain') ? (tenantBaseDomain() ?? '') : '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $subdomain . ($base !== '' ? '.' . $base : '') . '/login';
    }
}

if (!function_exists('createTenantAsOperator')) {
    /**
     * Register a company from the superadmin panel.
     *
     * @param array $in company_name, subdomain, owner_email, owner_password,
     *                  owner_password_confirm, owner_first_name, owner_last_name, status
     * @return array{ok:bool, error:?string, tenant_id:?int, subdomain:?string, login_url:?string}
     *
     * WHY THIS IS NOT registerTenant(). The public signup path in
     * core/tenant_registration.php layers three anti-abuse controls on top of
     * provisioning: a honeypot field, a 3-per-IP-per-hour throttle, and the
     * selfRegistrationOpen() master switch. Every one of them is wrong here:
     *
     *   - an authenticated platform operator is not a bot,
     *   - an operator onboarding several customers in one sitting would trip the
     *     throttle and be locked out of their own panel, and
     *   - an operator must still be able to create companies when public signup
     *     is deliberately switched OFF — which is precisely when this page earns
     *     its keep.
     *
     * What it does NOT relax is validation. Every rule registerTenant() applies
     * to a member of the public — name, subdomain format, reserved labels,
     * availability, email, password strength and confirmation — applies here
     * identically, because those rules protect the platform's own integrity, not
     * just its front door.
     *
     * Provisioning itself is provisionTenant(), unchanged and shared. A second
     * provisioning implementation would drift from the first, and the one that
     * drifts is always the one that creates a subtly broken tenant.
     */
    function createTenantAsOperator(array $in): array
    {
        $fail = function (string $msg): array {
            return ['ok' => false, 'error' => $msg, 'tenant_id' => null, 'subdomain' => null, 'login_url' => null];
        };

        $company = trim((string)($in['company_name'] ?? ''));
        $sub     = strtolower(trim((string)($in['subdomain'] ?? '')));
        $email   = strtolower(trim((string)($in['owner_email'] ?? '')));
        $pw      = (string)($in['owner_password'] ?? '');
        $confirm = (string)($in['owner_password_confirm'] ?? $pw);
        $status  = (string)($in['status'] ?? 'active');

        if ($company === '' || mb_strlen($company) < 2) {
            return $fail('Please enter the company name.');
        }
        if ($err = tenantSubdomainError($sub)) {
            return $fail($err);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fail('Please enter a valid owner email address.');
        }
        // Same rule as public signup — see superadminPasswordError()'s note on
        // why this codebase keeps one password rule, not several.
        if ($err = superadminPasswordError($pw, $confirm)) {
            return $fail($err);
        }
        if (!in_array($status, ['active', 'trial'], true)) {
            return $fail('Choose whether the account starts active or on trial.');
        }
        if (!tenantSubdomainAvailable($sub)) {
            return $fail('That subdomain is already taken. Please choose another.');
        }

        $r = provisionTenant($company, $sub, $email, $pw, [
            'status'           => $status,
            'owner_first_name' => trim((string)($in['owner_first_name'] ?? '')),
            'owner_last_name'  => trim((string)($in['owner_last_name'] ?? '')),
        ]);

        if (!$r['ok']) {
            // provisionTenant() is all-or-nothing: there is no orphaned database,
            // MySQL user or registry row to clean up here.
            logTenantAdminAction(null, $sub, 'create_failed', substr((string)$r['error'], 0, 500));
            return $fail((string)($r['error'] ?? 'The company could not be created.'));
        }

        logTenantAdminAction((int)$r['tenant_id'], $sub, 'create',
            'Created from the superadmin panel for ' . $email . ' (status: ' . $status . ')');

        return [
            'ok'        => true,
            'error'     => null,
            'tenant_id' => (int)$r['tenant_id'],
            'subdomain' => $sub,
            // Computed here rather than calling tenantLoginUrl(), which lives in
            // the PUBLIC registration module: the admin module should not have to
            // load the public signup flow just to format a URL.
            'login_url' => operatorTenantLoginUrl($sub),
        ];
    }
}
