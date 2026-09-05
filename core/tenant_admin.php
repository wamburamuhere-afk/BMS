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
 *   tenantUserDirectory(int $tenantId): ?array
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
                       status, plan, owner_email, max_users, max_storage_mb,
                       created_at, activated_at, suspended_at
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
                   status, plan, owner_email, max_users, max_storage_mb,
                       created_at, activated_at, suspended_at
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

if (!function_exists('tenantFeatureMatrix')) {
    /**
     * Every switchable feature with this tenant's effective state and, crucially,
     * WHY it is in that state.
     *
     * The panel shows the reason because "off" has three different causes and an
     * operator staring at a switch needs to know which one they are looking at:
     * the platform removed it for everyone, this tenant was explicitly denied it,
     * or it simply defaults off and nobody has said otherwise.
     *
     * Reads the control database only — like every other function in this file,
     * it never opens the tenant's own database.
     */
    function tenantFeatureMatrix(int $tenantId): array
    {
        $st = getControlPdo()->prepare("
            SELECT f.feature_key, f.label, f.description, f.is_available, f.default_enabled,
                   f.sort_order, tf.is_enabled
            FROM features f
            LEFT JOIN tenant_features tf
                   ON tf.feature_key = f.feature_key AND tf.tenant_id = ?
            ORDER BY f.sort_order, f.feature_key
        ");
        $st->execute([$tenantId]);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $available = (int)$r['is_available'] === 1;
            $default   = (int)$r['default_enabled'] === 1;
            $override  = $r['is_enabled'] === null ? null : ((int)$r['is_enabled'] === 1);
            $effective = $available && ($override ?? $default);

            if (!$available)             { $reason = 'Removed platform-wide'; }
            elseif ($override === true)  { $reason = 'Granted to this tenant'; }
            elseif ($override === false) { $reason = 'Denied to this tenant'; }
            else                         { $reason = $default ? 'On by default' : 'Off by default'; }

            $out[] = [
                'key'             => (string)$r['feature_key'],
                'label'           => (string)$r['label'],
                'description'     => $r['description'] !== null ? (string)$r['description'] : null,
                'available'       => $available,
                'default_enabled' => $default,
                'override'        => $override,
                'effective'       => $effective,
                'reason'          => $reason,
            ];
        }
        return $out;
    }
}

if (!function_exists('setTenantFeatures')) {
    /**
     * Write this tenant's feature overrides.
     *
     * $desired maps feature_key => bool. A key whose requested state already
     * equals the platform default has its override row DELETED rather than
     * written: absence of a row means "follow the default", so a tenant left at
     * the defaults keeps following them if those defaults later change. Writing
     * a redundant row would silently pin them.
     *
     * Only keys that actually changed are logged, so the audit trail records
     * decisions rather than every time someone opened the panel and hit Save.
     *
     * @return array{ok:bool, error:?string, changed:int}
     */
    function setTenantFeatures(int $tenantId, array $desired): array
    {
        $t = getTenant($tenantId);
        if (!$t) return ['ok' => false, 'error' => 'Tenant not found.', 'changed' => 0];
        if ($t['status'] === 'deleted') {
            return ['ok' => false, 'error' => 'This tenant has been deleted.', 'changed' => 0];
        }

        $pdo     = getControlPdo();
        $current = [];
        foreach (tenantFeatureMatrix($tenantId) as $row) $current[$row['key']] = $row;

        $enabled  = [];
        $disabled = [];

        foreach ($desired as $key => $want) {
            $key = (string)$key;
            if (!isset($current[$key])) continue;      // unknown key — ignore, never invent one
            $want = (bool)$want;
            $row  = $current[$key];
            $was  = $row['effective'];                 // effective state BEFORE this change

            if ($want === $row['default_enabled']) {
                $pdo->prepare("DELETE FROM tenant_features WHERE tenant_id = ? AND feature_key = ?")
                    ->execute([$tenantId, $key]);
            } else {
                $pdo->prepare("
                    INSERT INTO tenant_features (tenant_id, feature_key, is_enabled, updated_by)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled),
                                            updated_by = VALUES(updated_by),
                                            updated_at = CURRENT_TIMESTAMP
                ")->execute([$tenantId, $key, $want ? 1 : 0, currentSuperadmin()['id'] ?? null]);
            }

            // A platform-removed feature cannot become effective, whatever we wrote.
            $nowEffective = $row['available'] && $want;
            if ($nowEffective !== $was) {
                if ($nowEffective) { $enabled[] = $key; } else { $disabled[] = $key; }
            }
        }

        $changed = count($enabled) + count($disabled);
        if ($changed > 0) {
            $detail = trim(
                ($enabled  ? 'enabled: '  . implode(',', $enabled)  . ' ' : '') .
                ($disabled ? 'disabled: ' . implode(',', $disabled)       : '')
            );
            logTenantAdminAction($tenantId, $t['subdomain'], 'update_features', $detail);
        }

        return ['ok' => true, 'error' => null, 'changed' => $changed];
    }
}

if (!function_exists('platformFeatures')) {
    /**
     * The platform-wide catalogue, with how many live tenants each feature is
     * currently effective for — so removing one states the blast radius instead
     * of leaving the operator to guess.
     */
    function platformFeatures(): array
    {
        $pdo  = getControlPdo();
        $live = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status IN ('active','trial')")
                         ->fetchColumn();

        $rows = $pdo->query("
            SELECT f.feature_key, f.label, f.description, f.is_available, f.default_enabled, f.sort_order,
                   (SELECT COUNT(*) FROM tenant_features tf
                     JOIN tenants t ON t.id = tf.tenant_id AND t.status IN ('active','trial')
                    WHERE tf.feature_key = f.feature_key AND tf.is_enabled = 1) AS explicit_on,
                   (SELECT COUNT(*) FROM tenant_features tf
                     JOIN tenants t ON t.id = tf.tenant_id AND t.status IN ('active','trial')
                    WHERE tf.feature_key = f.feature_key AND tf.is_enabled = 0) AS explicit_off
            FROM features f
            ORDER BY f.sort_order, f.feature_key
        ")->fetchAll();

        foreach ($rows as &$r) {
            $on  = (int)$r['explicit_on'];
            $off = (int)$r['explicit_off'];
            // Tenants with no row follow the default; the rest are counted above.
            $r['tenants_using'] = (int)$r['is_available'] === 0
                ? 0
                : ($on + ((int)$r['default_enabled'] === 1 ? max(0, $live - $on - $off) : 0));
            $r['tenants_live']  = $live;
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('setPlatformFeature')) {
    /**
     * Change one feature platform-wide.
     *
     * `is_available = 0` removes it for EVERY tenant regardless of that tenant's
     * own override — that asymmetry is the whole reason the column exists, and
     * tenantFeatureMatrix() reports it as the reason so nobody has to work out
     * why a tenant's own "on" is not taking effect.
     *
     * @return array{ok:bool, error:?string}
     */
    function setPlatformFeature(string $featureKey, ?bool $available, ?bool $defaultEnabled): array
    {
        $pdo = getControlPdo();
        $st  = $pdo->prepare("SELECT feature_key, label, is_available, default_enabled FROM features WHERE feature_key = ?");
        $st->execute([$featureKey]);
        $row = $st->fetch();
        if (!$row) return ['ok' => false, 'error' => 'No such feature.'];

        $newAvail   = $available      === null ? (int)$row['is_available']    : ($available ? 1 : 0);
        $newDefault = $defaultEnabled === null ? (int)$row['default_enabled'] : ($defaultEnabled ? 1 : 0);

        if ($newAvail === (int)$row['is_available'] && $newDefault === (int)$row['default_enabled']) {
            return ['ok' => true, 'error' => null];   // nothing to do, nothing to log
        }

        $pdo->prepare("UPDATE features SET is_available = ?, default_enabled = ? WHERE feature_key = ?")
            ->execute([$newAvail, $newDefault, $featureKey]);

        $bits = [];
        if ($newAvail !== (int)$row['is_available']) {
            $bits[] = $newAvail ? 'restored platform-wide' : 'REMOVED platform-wide';
        }
        if ($newDefault !== (int)$row['default_enabled']) {
            $bits[] = 'new-tenant default ' . ($newDefault ? 'on' : 'off');
        }

        // tenant_id null: a platform decision, not an action against one company.
        logTenantAdminAction(null, null, 'platform_feature', $featureKey . ': ' . implode('; ', $bits));

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('setTenantQuotas')) {
    /**
     * Set (or clear) one tenant's seat and storage limits.
     *
     * $maxUsers / $maxStorageMb: null = unlimited. Only genuinely changed
     * values are logged, matching setTenantFeatures()'s discipline — an
     * operator opening this panel and clicking Save with nothing changed
     * writes no audit noise.
     *
     * @return array{ok:bool, error:?string, changed:bool}
     */
    function setTenantQuotas(int $tenantId, ?int $maxUsers, ?int $maxStorageMb): array
    {
        $t = getTenant($tenantId);
        if (!$t) return ['ok' => false, 'error' => 'Tenant not found.', 'changed' => false];
        if ($t['status'] === 'deleted') {
            return ['ok' => false, 'error' => 'This tenant has been deleted.', 'changed' => false];
        }

        if ($maxUsers !== null && $maxUsers < 1) {
            return ['ok' => false, 'error' => 'The user limit must be at least 1, or left blank for unlimited.', 'changed' => false];
        }
        if ($maxStorageMb !== null && $maxStorageMb < 1) {
            return ['ok' => false, 'error' => 'The storage limit must be at least 1 MB, or left blank for unlimited.', 'changed' => false];
        }

        $before = $t['max_users'] === null ? 'unlimited' : (string)$t['max_users'];
        $beforeStorage = $t['max_storage_mb'] === null ? 'unlimited' : (string)$t['max_storage_mb'] . 'MB';
        $after = $maxUsers === null ? 'unlimited' : (string)$maxUsers;
        $afterStorage = $maxStorageMb === null ? 'unlimited' : (string)$maxStorageMb . 'MB';

        $changed = ($before !== $after) || ($beforeStorage !== $afterStorage);

        getControlPdo()->prepare("UPDATE tenants SET max_users = ?, max_storage_mb = ? WHERE id = ?")
            ->execute([$maxUsers, $maxStorageMb, $tenantId]);

        if ($changed) {
            logTenantAdminAction($tenantId, $t['subdomain'], 'update_quotas',
                "users: {$before} -> {$after}; storage: {$beforeStorage} -> {$afterStorage}");
        }

        return ['ok' => true, 'error' => null, 'changed' => $changed];
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

if (!function_exists('tenantUserDirectory')) {
    /**
     * A read-only directory of one tenant's staff accounts: who they are,
     * whether they're active, and when they last signed in. Nothing else.
     *
     * A SECOND deliberate, narrow exception to "the superadmin panel never
     * opens a tenant's own database" — same shape and same justification as
     * core/tenant_quotas.php's tenantUsageSnapshotFor(), which this function
     * deliberately does NOT share a connection-opening helper with. Factoring
     * that into a shared "open any tenant's DB" utility would make the
     * exception MORE available platform-wide than it is today — exactly what
     * that file's own docblock argues against ("kept as narrow as the
     * invariant it bends allows... easy to find, audit, and — if it is ever
     * misused — remove"). Two small, independently-auditable, side-by-side
     * exceptions are safer than one shared gateway future code could lean on
     * for something broader.
     *
     * Called ON DEMAND from one explicit action
     * (actions/superadmin_tenant_users.php), never automatically on
     * tenant_view.php's normal page load — same discipline as usage snapshots.
     *
     * Returns ONLY: id, name, email, role, admin flag, active flag, last
     * login, created date. Never a password hash, phone number, avatar, or
     * anything from any other table — this is an account directory, not a
     * window into the tenant's business data.
     *
     * @return array<int,array{user_id:int,name:string,email:string,role:string,is_admin:bool,is_active:bool,last_login:?string,created_at:?string}>|null
     */
    function tenantUserDirectory(int $tenantId): ?array
    {
        try {
            $st = getControlPdo()->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$tenantId]);
            $t = $st->fetch();
            if (!$t || $t['status'] === 'deleted') return null;

            $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
            if ($pw === null) return null;

            $tPdo = new PDO(
                'mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
                $t['db_username'], $pw,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            $rows = $tPdo->query("
                SELECT user_id, first_name, last_name, username, email,
                       COALESCE(NULLIF(role, ''), user_role) AS role,
                       is_admin, is_active, last_login, created_at
                FROM users
                ORDER BY is_active DESC, first_name, last_name
                LIMIT 500
            ")->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $r) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $out[] = [
                    'user_id'    => (int)$r['user_id'],
                    'name'       => $name !== '' ? $name : (string)$r['username'],
                    'email'      => (string)($r['email'] ?? ''),
                    'role'       => (string)($r['role'] ?? ''),
                    'is_admin'   => (bool)$r['is_admin'],
                    'is_active'  => (bool)$r['is_active'],
                    'last_login' => $r['last_login'] !== null ? (string)$r['last_login'] : null,
                    'created_at' => $r['created_at'] !== null ? (string)$r['created_at'] : null,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('tenantUserDirectory(' . $tenantId . '): ' . $e->getMessage());
            return null;
        }
    }
}

// ── Shared display helpers for tenant_admin_log ─────────────────────────────
// Used by both the dashboard's Recent Platform Activity panel (last 12) and
// the full Activity log page (up to 500) — one place, so the icon/color/label
// for a given action can never drift between the two views.

if (!function_exists('saTimeAgo')) {
    function saTimeAgo(string $datetime): string
    {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   { $m = (int)floor($diff / 60);    return $m . ($m > 1 ? ' minutes ago' : ' minute ago'); }
        if ($diff < 86400)  { $h = (int)floor($diff / 3600);  return $h . ($h > 1 ? ' hours ago'   : ' hour ago'); }
        if ($diff < 604800) { $d = (int)floor($diff / 86400); return $d . ($d > 1 ? ' days ago'    : ' day ago'); }
        return date('d M Y', strtotime($datetime));
    }
}

if (!function_exists('saActionMeta')) {
    /** action => [icon, bootstrap color, human label] */
    function saActionMeta(string $action): array
    {
        static $map = [
            'create'               => ['bi-plus-circle',           'success',   'Created tenant'],
            'create_failed'        => ['bi-x-circle',               'danger',    'Failed to create tenant'],
            'suspend'               => ['bi-pause-circle',           'warning',   'Suspended tenant'],
            'activate'              => ['bi-play-circle',            'primary',   'Activated tenant'],
            'delete'                => ['bi-trash',                  'danger',    'Deleted tenant'],
            'delete_refused'        => ['bi-shield-x',                'secondary', 'Delete refused (name mismatch)'],
            'delete_partial'        => ['bi-exclamation-triangle',   'danger',    'Delete left cleanup debris'],
            'update_features'       => ['bi-grid',                   'primary',   'Updated modules'],
            'update_quotas'         => ['bi-speedometer',            'primary',   'Updated quotas'],
            'platform_feature'      => ['bi-toggles',                'primary',   'Changed a platform-wide module'],
            'sa_credential_change'  => ['bi-person-gear',            'secondary', 'Updated their own account'],
            'plan_create'            => ['bi-box-seam',               'success',   'Created a plan'],
            'plan_update'            => ['bi-box-seam',               'primary',   'Updated a plan'],
            'plan_activate'          => ['bi-play-circle',            'primary',   'Restored a plan'],
            'plan_deactivate'        => ['bi-pause-circle',           'secondary', 'Retired a plan'],
            'apply_plan'             => ['bi-check2-circle',          'success',   'Applied a plan to tenant'],
            'platform_settings'      => ['bi-gear-wide-connected',    'primary',   'Updated platform settings'],
        ];
        return $map[$action] ?? ['bi-activity', 'secondary', ucfirst(str_replace('_', ' ', $action))];
    }
}
