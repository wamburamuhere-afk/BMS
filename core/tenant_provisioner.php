<?php
/**
 * core/tenant_provisioner.php
 * ---------------------------
 * Creates a complete, isolated tenant: its own MySQL database, its own MySQL
 * user, the full BMS schema, sensible defaults, and an owner account.
 *
 * The single hard guarantee of this file: **it never leaves a half-created
 * tenant behind.** Applying the schema is a multi-statement batch, and MySQL
 * DDL auto-commits — a failure two hundred tables in leaves those tables on
 * disk, and no transaction can undo them. So every failure path after the
 * database is created tears down the database, the MySQL user, and the registry
 * row, and records why in tenant_provisioning_log.
 *
 * Ordering note — the plan in ternant.md lists "CREATE DATABASE bms_t{id}" as
 * step 2 and "INSERT the tenants row" as step 7, which cannot work: the id in
 * the database name *comes from* that row's AUTO_INCREMENT. The registry row is
 * therefore inserted first as a placeholder, and updated with the real database
 * name, username and encrypted password once provisioning succeeds. A failed
 * attempt deletes the row but does not roll the AUTO_INCREMENT back, so a
 * retry always gets a fresh id and can never collide with an orphan.
 *
 * Public API:
 *   provisionTenant(company, subdomain, ownerEmail, ownerPassword, opts): array
 *   tenantSubdomainError(string): ?string        → validation message, or null
 *   tenantSubdomainAvailable(string): bool       → registry uniqueness check
 *   TENANT_RESERVED_SUBDOMAINS                   → const array
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_crypto.php';

/**
 * Subdomains a tenant may not claim. Either already in use by infrastructure,
 * needed for the platform itself, or phishing-adjacent (a tenant calling itself
 * "secure" or "billing" on the platform's own domain).
 * Mirrored in docs/MULTI_TENANCY_CONVENTIONS.md §1.
 */
const TENANT_RESERVED_SUBDOMAINS = [
    'www','admin','superadmin','api','app','mail','smtp','imap','ftp','ns1','ns2','mx',
    'static','cdn','assets','files','download','uploads','status','health','test','staging',
    'dev','demo','sandbox','billing','support','help','docs','blog','login','signup',
    'register','auth','account','accounts','secure','ssl','vpn','git','ci','bms','root',
];

if (!function_exists('tenantSubdomainError')) {
    /**
     * Validate a desired subdomain. Returns a human-readable error, or null if OK.
     * Format-only — does not touch the database, so Phase 5's live availability
     * check can call it on every keystroke.
     */
    function tenantSubdomainError(string $subdomain): ?string
    {
        $s = strtolower(trim($subdomain));

        if ($s === '')                 return 'Please choose a subdomain.';
        if (strlen($s) < 3)            return 'Subdomain must be at least 3 characters.';
        if (strlen($s) > 32)           return 'Subdomain must be 32 characters or fewer.';
        if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $s)) {
            return 'Use lowercase letters, numbers and hyphens only, starting and ending with a letter or number.';
        }
        if (strpos($s, '--') !== false) return 'Subdomain cannot contain two hyphens in a row.';
        if (in_array($s, TENANT_RESERVED_SUBDOMAINS, true)) {
            return 'That subdomain is reserved. Please choose another.';
        }
        return null;
    }
}

if (!function_exists('tenantSubdomainAvailable')) {
    /**
     * True if no tenant has claimed this subdomain.
     *
     * Deliberately counts rows in EVERY status, including 'deleted'. A deleted
     * company keeps its claim so a new signup cannot inherit its subdomain along
     * with the stale bookmarks, emailed links and search results pointing at it.
     */
    function tenantSubdomainAvailable(string $subdomain): bool
    {
        $st = getControlPdo()->prepare("SELECT COUNT(*) FROM tenants WHERE subdomain = ?");
        $st->execute([strtolower(trim($subdomain))]);
        return (int)$st->fetchColumn() === 0;
    }
}

if (!function_exists('generateTenantDbPassword')) {
    /**
     * A 24-character random password for the tenant's MySQL user.
     *
     * The alphabet deliberately excludes quotes, backslash and backtick. Those
     * are all escapable, but this value is interpolated into a CREATE USER
     * statement and read back out of an encrypted column; keeping shell- and
     * SQL-hostile characters out of it entirely removes a whole class of
     * quoting bug from a code path that hands out database credentials.
     */
    function generateTenantDbPassword(int $length = 24): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#%*+-=?@^_';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];   // CSPRNG, not rand()
        }
        return $out;
    }
}

if (!function_exists('logProvisioningStep')) {
    /**
     * Append to the provisioning audit trail.
     *
     * Best-effort by design: a logging failure must never be the thing that
     * breaks a signup, and must never mask the real error being reported.
     * NEVER pass a plaintext password as $message (audited in Phase 9).
     */
    function logProvisioningStep(?int $tenantId, ?string $subdomain, string $step, string $status, ?string $message = null): void
    {
        try {
            getControlPdo()->prepare("
                INSERT INTO tenant_provisioning_log (tenant_id, subdomain, step, status, message)
                VALUES (?,?,?,?,?)
            ")->execute([$tenantId, $subdomain, $step, $status, $message === null ? null : substr($message, 0, 4000)]);
        } catch (Throwable $e) {
            error_log('tenant_provisioning_log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getProvisioningPdo')) {
    /**
     * An administrative connection with NO database selected — it has to create
     * databases and users, which cannot be done from inside the database being
     * created. Reuses the control connection's credentials (see control_db.php).
     */
    function getProvisioningPdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $s = controlDbSettings();
        try {
            $pdo = new PDO(
                'mysql:host=' . $s['host'] . ';charset=utf8mb4',
                $s['user'],
                $s['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Cannot open an administrative MySQL connection on ' . $s['host']
                . ' as "' . $s['user'] . '" (from ' . $s['source'] . '): ' . $e->getMessage()
            );
        }
        return $pdo;
    }
}

if (!function_exists('destroyTenantResources')) {
    /**
     * Tear down a tenant's physical resources. Used both by the rollback path
     * here and (Phase 6) by superadmin delete.
     *
     * Each drop is attempted independently: if dropping the database fails we
     * still try to drop the user, because a half-cleaned failure is worse than
     * either single failure. Returns a list of problems, empty on success.
     */
    function destroyTenantResources(?string $dbName, ?string $dbUser): array
    {
        $problems = [];
        try {
            $admin = getProvisioningPdo();
        } catch (Throwable $e) {
            return ['could not open admin connection: ' . $e->getMessage()];
        }

        if ($dbName !== null && $dbName !== '' && preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            try { $admin->exec("DROP DATABASE IF EXISTS `{$dbName}`"); }
            catch (Throwable $e) { $problems[] = "DROP DATABASE {$dbName}: " . $e->getMessage(); }
        }

        if ($dbUser !== null && $dbUser !== '' && preg_match('/^[A-Za-z0-9_]+$/', $dbUser)) {
            try { $admin->exec("DROP USER IF EXISTS " . $admin->quote($dbUser) . "@'%'"); }
            catch (Throwable $e) { $problems[] = "DROP USER {$dbUser}: " . $e->getMessage(); }
        }

        return $problems;
    }
}

if (!function_exists('provisionTenant')) {
    /**
     * Create a fully working tenant.
     *
     * @param array $opts  'status' => 'active'|'trial' (default 'active'),
     *                     'plan'   => string|null,
     *                     'owner_first_name', 'owner_last_name' => string
     * @return array{ok:bool, tenant_id:?int, subdomain:string, db_name:?string,
     *                db_username:?string, error:?string, steps:array}
     */
    function provisionTenant(
        string $companyName,
        string $subdomain,
        string $ownerEmail,
        string $ownerPassword,
        array  $opts = []
    ): array {
        $companyName = trim($companyName);
        $subdomain   = strtolower(trim($subdomain));
        $ownerEmail  = trim($ownerEmail);

        $steps  = [];
        $result = [
            'ok' => false, 'tenant_id' => null, 'subdomain' => $subdomain,
            'db_name' => null, 'db_username' => null, 'error' => null, 'steps' => &$steps,
        ];
        $step = function (string $name, string $status, ?string $msg = null) use (&$steps) {
            $steps[] = ['step' => $name, 'status' => $status, 'message' => $msg];
        };
        $fail = function (string $msg) use (&$result, $step, $subdomain) {
            $step('failed', 'failed', $msg);
            $result['error'] = $msg;
            return $result;
        };

        // ── 1. Validate before touching anything ─────────────────────────────
        if ($companyName === '' || mb_strlen($companyName) < 2) {
            return $fail('Please enter your company name.');
        }
        if (mb_strlen($companyName) > 191) {
            return $fail('Company name must be 191 characters or fewer.');
        }
        if ($err = tenantSubdomainError($subdomain)) {
            return $fail($err);
        }
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            return $fail('Please enter a valid email address.');
        }
        if (strlen($ownerPassword) < 8) {
            return $fail('Password must be at least 8 characters.');
        }
        if (!tenantCredKeyAvailable()) {
            // Refuse rather than store a database password in the clear.
            return $fail('The platform encryption key is not configured. Provisioning is disabled until TENANT_CRED_KEY is set.');
        }

        $status = in_array($opts['status'] ?? 'active', ['active','trial'], true) ? $opts['status'] ?? 'active' : 'active';

        $cpdo = getControlPdo();

        if (!tenantSubdomainAvailable($subdomain)) {
            logProvisioningStep(null, $subdomain, 'validate', 'failed', 'subdomain already taken');
            return $fail('That subdomain is already taken. Please choose another.');
        }
        $step('validate', 'ok');
        logProvisioningStep(null, $subdomain, 'validate', 'ok');

        // ── 2. Reserve the registry row to obtain the tenant id ──────────────
        // Placeholder db_name/username; filled in at step 8 once real.
        try {
            $cpdo->prepare("
                INSERT INTO tenants (company_name, subdomain, db_host, db_name, db_username,
                                     db_password_encrypted, status, plan, owner_email)
                VALUES (?,?,?,'','','', ?, ?, ?)
            ")->execute([
                $companyName, $subdomain, controlDbSettings()['host'],
                $status, $opts['plan'] ?? null, $ownerEmail,
            ]);
            $tenantId = (int)$cpdo->lastInsertId();
        } catch (PDOException $e) {
            // A race between the availability check and this insert lands here,
            // and the UNIQUE index is what actually makes it safe.
            $dup = ((string)$e->getCode() === '23000');
            logProvisioningStep(null, $subdomain, 'reserve_registry_row', 'failed', $e->getMessage());
            return $fail($dup
                ? 'That subdomain was just taken by another signup. Please choose another.'
                : 'Could not reserve the tenant record: ' . $e->getMessage());
        }

        $result['tenant_id'] = $tenantId;
        $dbName = 'bms_t' . $tenantId;
        $dbUser = 'bms_u' . $tenantId;
        $result['db_name'] = $dbName;
        $result['db_username'] = $dbUser;
        $step('reserve_registry_row', 'ok', "tenant_id={$tenantId}");
        logProvisioningStep($tenantId, $subdomain, 'reserve_registry_row', 'ok', "db={$dbName} user={$dbUser}");

        // Everything from here can leave physical debris, so it all runs inside
        // a try whose catch tears the whole tenant down.
        $dbPassword = generateTenantDbPassword();

        // Rollback must only destroy what THIS call created. Without these flags
        // the "refusing to overwrite" guard below would be a lie: it throws, and
        // the catch would then cheerfully drop the very database it just refused
        // to touch — destroying whatever data made it worth protecting.
        $createdDb   = false;
        $createdUser = false;

        try {
            $admin = getProvisioningPdo();

            // ── 3. Refuse to reuse leftover resources ────────────────────────
            // AUTO_INCREMENT never rewinds, so a fresh id colliding with an
            // existing database means orphaned debris from an earlier crash.
            // Silently dropping it could destroy real data, so stop instead.
            $exists = (bool)$admin->query(
                "SELECT 1 FROM information_schema.schemata WHERE schema_name = " . $admin->quote($dbName)
            )->fetchColumn();
            if ($exists) {
                throw new RuntimeException(
                    "Database {$dbName} already exists — refusing to overwrite it. This is orphaned "
                    . "debris from an earlier failed provision; an administrator must inspect and drop it."
                );
            }

            // ── 4. The database ──────────────────────────────────────────────
            $admin->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            $createdDb = true;
            $step('create_database', 'ok', $dbName);
            logProvisioningStep($tenantId, $subdomain, 'create_database', 'ok', $dbName);

            // ── 5. The dedicated MySQL user, scoped to this database only ────
            $admin->exec("DROP USER IF EXISTS " . $admin->quote($dbUser) . "@'%'");
            $admin->exec("CREATE USER " . $admin->quote($dbUser) . "@'%' IDENTIFIED BY " . $admin->quote($dbPassword));
            $admin->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO " . $admin->quote($dbUser) . "@'%'");
            $admin->exec("FLUSH PRIVILEGES");
            $createdUser = true;
            $step('create_db_user', 'ok', $dbUser);
            logProvisioningStep($tenantId, $subdomain, 'create_db_user', 'ok', $dbUser);   // never the password

            // ── 6. Schema, then defaults ─────────────────────────────────────
            $s = controlDbSettings();
            $tpdo = new PDO(
                "mysql:host={$s['host']};dbname={$dbName};charset=utf8mb4",
                $s['user'], $s['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );

            foreach ([
                'apply_schema' => __DIR__ . '/../schema/tenant_schema_template.sql',
                'apply_seed'   => __DIR__ . '/../schema/tenant_seed_defaults.sql',
            ] as $stepName => $file) {
                if (!is_file($file)) {
                    throw new RuntimeException(basename($file) . ' is missing from the deployment.');
                }
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') {
                    throw new RuntimeException(basename($file) . ' could not be read or is empty.');
                }
                // A mid-batch failure throws but leaves earlier statements applied
                // (DDL auto-commits) — which is exactly why the catch below drops
                // the whole database rather than trying to unpick it.
                $tpdo->exec($sql);
                $step($stepName, 'ok', basename($file));
                logProvisioningStep($tenantId, $subdomain, $stepName, 'ok', basename($file));
            }

            $tableCount = (int)$tpdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $tpdo->quote($dbName)
            )->fetchColumn();
            if ($tableCount < 100) {
                throw new RuntimeException("Schema looks incomplete — only {$tableCount} objects created.");
            }

            // ── 7. The owner's account ───────────────────────────────────────
            // Look the Admin role up by name rather than hardcoding id 1: the
            // seed file's ids are whatever the source database happened to use.
            $roleId = $tpdo->query("SELECT role_id FROM roles WHERE role_name = 'Admin' LIMIT 1")->fetchColumn();
            if ($roleId === false) {
                $roleId = $tpdo->query("SELECT role_id FROM roles ORDER BY role_id LIMIT 1")->fetchColumn();
            }
            if ($roleId === false) {
                throw new RuntimeException('No roles were seeded — the owner would have no permissions.');
            }

            $tpdo->prepare("
                INSERT INTO users (username, password, email, role, user_role, is_admin,
                                   role_id, is_active, first_name, last_name, password_changed_at)
                VALUES (?,?,?,?,?,1,?,1,?,?,NOW())
            ")->execute([
                $ownerEmail,
                password_hash($ownerPassword, PASSWORD_DEFAULT),
                $ownerEmail,
                'Admin', 'Admin',
                (int)$roleId,
                trim((string)($opts['owner_first_name'] ?? '')) ?: $companyName,
                trim((string)($opts['owner_last_name'] ?? '')) ?: 'Owner',
            ]);
            $step('create_owner_user', 'ok', $ownerEmail);
            logProvisioningStep($tenantId, $subdomain, 'create_owner_user', 'ok', $ownerEmail);

            // ── 8. Prove the tenant's own credentials actually work ──────────
            // Everything above ran as the admin user. If the GRANT were wrong,
            // the failure would otherwise surface at the tenant's first login,
            // long after provisioning reported success.
            $verify = new PDO(
                "mysql:host={$s['host']};dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPassword,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $owners = (int)$verify->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($owners !== 1) {
                throw new RuntimeException("Expected exactly 1 owner account, found {$owners}.");
            }
            $verify = null;
            $step('verify_tenant_credentials', 'ok');
            logProvisioningStep($tenantId, $subdomain, 'verify_tenant_credentials', 'ok');

            // ── 9. Commit the real values to the registry ────────────────────
            $cpdo->prepare("
                UPDATE tenants
                   SET db_name = ?, db_username = ?, db_password_encrypted = ?, activated_at = ?
                 WHERE id = ?
            ")->execute([
                $dbName, $dbUser, encryptTenantSecret($dbPassword),
                $status === 'active' ? date('Y-m-d H:i:s') : null,
                $tenantId,
            ]);
            $step('finalise_registry', 'ok');
            logProvisioningStep($tenantId, $subdomain, 'finalise_registry', 'ok');

            $result['ok'] = true;
            logProvisioningStep($tenantId, $subdomain, 'complete', 'ok');
            return $result;

        } catch (Throwable $e) {
            // ── Rollback: never leave a half-created tenant ──────────────────
            $why = $e->getMessage();
            logProvisioningStep($tenantId, $subdomain, 'rollback', 'started', $why);

            // Only what this call created — see the $createdDb/$createdUser note above.
            $problems = destroyTenantResources(
                $createdDb   ? $dbName : null,
                $createdUser ? $dbUser : null
            );

            try {
                $cpdo->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenantId]);
            } catch (Throwable $e2) {
                $problems[] = 'DELETE tenants row: ' . $e2->getMessage();
            }

            // The log row deliberately survives the deleted tenant — that is why
            // tenant_provisioning_log.tenant_id has no foreign key.
            logProvisioningStep(
                $tenantId, $subdomain, 'rollback',
                $problems ? 'failed' : 'rolled_back',
                $problems ? implode(' | ', $problems) : 'removed: registry row'
                    . ($createdDb ? ", database {$dbName}" : '')
                    . ($createdUser ? ", user {$dbUser}" : '')
            );
            $step('rollback', $problems ? 'failed' : 'rolled_back', $problems ? implode(' | ', $problems) : null);

            $result['error'] = 'Provisioning failed: ' . $why;
            if ($problems) {
                // Loud, because this is the one state that needs a human.
                $result['error'] .= ' — AND CLEANUP WAS INCOMPLETE: ' . implode(' | ', $problems);
                error_log('TENANT PROVISIONING LEFT DEBRIS for ' . $subdomain . ': ' . implode(' | ', $problems));
            }
            return $result;
        }
    }
}
