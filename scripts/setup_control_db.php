<?php
/**
 * scripts/setup_control_db.php — create the multi-tenancy control database.
 *
 *   php scripts/setup_control_db.php            # create / update
 *   php scripts/setup_control_db.php --check    # report only, change nothing
 *
 * WHY THIS IS A SCRIPT AND NOT A MIGRATION
 * ----------------------------------------
 * It used to be one, and that was wrong. `bms_control` is PLATFORM
 * infrastructure — a registry of which companies exist — not tenant schema. It
 * lives in its own database, needs CREATE DATABASE privilege that a hardened
 * application user has no reason to hold, and nothing in the application reads
 * it until multi-tenancy is switched on.
 *
 * Running it from migrations/runner.php on every deploy meant that a production
 * user without CREATE privilege failed the migration, and `script_stop: true`
 * then halted the WHOLE deploy — blocking unrelated work because an optional,
 * not-yet-enabled subsystem could not build its database. That is the wrong
 * trade, and it happened for real on 2026-08-31.
 *
 * Setting up multi-tenancy is already a deliberate manual process (see
 * docs/MULTI_TENANCY_CONVENTIONS.md §9: environment variables, a config.php
 * edit, DNS). This script is that process's database step. It is idempotent, so
 * running it again is always safe.
 *
 * CLI ONLY — scripts/ is HTTP-blocked by .htaccess, and this refuses a web SAPI
 * regardless.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';

$checkOnly = in_array('--check', array_slice($argv, 1), true);

function say(string $s = ''): void { echo $s . "\n"; }
function die_with(string $s): void { say(); say("  ERROR: $s"); say(); exit(1); }

$controlDb = controlDbName();
if (!preg_match('/^[a-z0-9_]{1,64}$/i', $controlDb)) {
    die_with("Invalid control database name '{$controlDb}' (CONTROL_DB_NAME must be alphanumeric/underscore).");
}

say();
say("  BMS — multi-tenancy control database setup");
say("  Target: {$controlDb}");
say();

// Connect WITHOUT selecting a database: we may be about to create it.
try {
    $s = controlDbSettings();
    $admin = new PDO(
        'mysql:host=' . $s['host'] . ';charset=utf8mb4',
        $s['user'], $s['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    die_with("Cannot connect to MySQL on " . ($s['host'] ?? '?') . ": " . $e->getMessage());
}
say("  Connected to {$s['host']} as '{$s['user']}' (credentials from {$s['source']}).");

$exists = (bool)$admin->query(
    "SELECT 1 FROM information_schema.schemata WHERE schema_name = " . $admin->quote($controlDb)
)->fetchColumn();

if ($checkOnly) {
    say('  Database exists: ' . ($exists ? 'yes' : 'no'));
    if ($exists) {
        $t = $admin->query("
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = " . $admin->quote($controlDb) . " AND table_type = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN);
        say('  Tables: ' . ($t ? implode(', ', $t) : '(none)'));
    }
    say();
    exit(0);
}

// ── 1. The database ─────────────────────────────────────────────────────────
if ($exists) {
    say("  · database {$controlDb} already exists");
} else {
    try {
        $admin->exec("CREATE DATABASE `{$controlDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        say("  + database {$controlDb} created");
    } catch (PDOException $e) {
        say();
        say("  This MySQL user cannot create databases. Ask your DBA to run:");
        say();
        say("      CREATE DATABASE `{$controlDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;");
        say("      GRANT ALL PRIVILEGES ON `{$controlDb}`.* TO '{$s['user']}'@'localhost';");
        say("      FLUSH PRIVILEGES;");
        say();
        say("  Then run this script again. Nothing else needs to change.");
        die_with($e->getMessage());
    }
}

// ── 2. The tables ───────────────────────────────────────────────────────────
// Everything below is CREATE TABLE IF NOT EXISTS / guarded ALTER, so re-running
// is a no-op. Applied with fully-qualified names so no USE is needed.
try {
    // Registry. db_name is a STORED column, never computed from the id, because
    // Tenant #1 keeps the legacy name `bms`. subdomain stays UNIQUE even for
    // deleted tenants so a new signup cannot inherit a dead company's address.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenants` (
            `id`                    INT AUTO_INCREMENT PRIMARY KEY,
            `company_name`          VARCHAR(191) NOT NULL,
            `subdomain`             VARCHAR(63)  NOT NULL,
            `db_host`               VARCHAR(191) NOT NULL DEFAULT 'localhost',
            `db_name`               VARCHAR(64)  NOT NULL,
            `db_username`           VARCHAR(32)  NOT NULL,
            `db_password_encrypted` VARCHAR(255) NOT NULL,
            `status`                ENUM('trial','active','suspended','deleted') NOT NULL DEFAULT 'trial',
            `plan`                  VARCHAR(50)  NULL,
            `owner_email`           VARCHAR(191) NOT NULL,
            `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `activated_at`          DATETIME     NULL,
            `suspended_at`          DATETIME     NULL,
            UNIQUE KEY `uq_tenants_subdomain` (`subdomain`),
            KEY `idx_tenants_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table tenants ready');

    // Platform operators. Ships EMPTY — a default account with known credentials
    // would be a backdoor into every tenant. Create the first with
    // scripts/create_superadmin.php.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`superadmins` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `name`            VARCHAR(191) NOT NULL,
            `email`           VARCHAR(191) NOT NULL,
            `password_hash`   VARCHAR(255) NOT NULL,
            `failed_attempts` INT          NOT NULL DEFAULT 0,
            `locked_until`    DATETIME     NULL,
            `last_login`      DATETIME     NULL,
            `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_superadmins_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table superadmins ready');

    // tenant_id is NULLable with NO foreign key on purpose: provisioning logs
    // steps before the tenants row exists, and its rollback deletes that row
    // while the record of WHY it failed has to survive.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenant_provisioning_log` (
            `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id`  INT          NULL,
            `subdomain`  VARCHAR(63)  NULL,
            `step`       VARCHAR(64)  NOT NULL,
            `status`     ENUM('started','ok','failed','rolled_back') NOT NULL,
            `message`    TEXT         NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_provlog_tenant` (`tenant_id`),
            KEY `idx_provlog_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table tenant_provisioning_log ready');

    // Signup audit trail AND the ledger the rate limiter counts. Self-registration
    // is a public, unauthenticated endpoint that creates a MySQL database on every
    // success; without this table there is nothing to throttle against, which is
    // the difference between a signup form and a resource-exhaustion amplifier.
    //
    // Separate from tenant_provisioning_log: that records the STEPS of a
    // provisioning run, this records ATTEMPTS to register — including the ones
    // rejected before provisioning starts, which is exactly what a limiter counts.
    // No FK to tenants: most rows are failures that never produced a tenant, and
    // successful ones must outlive a tenant being deleted.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`registration_attempts` (
            `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
            `ip_address` VARCHAR(45)  NOT NULL,
            `email`      VARCHAR(191) NULL,
            `subdomain`  VARCHAR(63)  NULL,
            `outcome`    ENUM('success','rejected','failed','throttled') NOT NULL,
            `reason`     VARCHAR(255) NULL,
            `tenant_id`  INT          NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_regattempt_ip_time` (`ip_address`, `created_at`),
            KEY `idx_regattempt_time` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table registration_attempts ready');

    // Who did what to which tenant. Suspending cuts a company off from its own
    // system and deleting destroys its database permanently — irreversible acts
    // against someone else's business data must be attributable to a named
    // operator, not merely "the platform".
    //
    // superadmin_id has no FK and the operator's email is DENORMALISED into
    // actor_email on purpose: the record of who deleted a tenant has to survive
    // that operator's own account being removed later.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenant_admin_log` (
            `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
            `superadmin_id` INT          NULL,
            `actor_email`   VARCHAR(191) NULL,
            `tenant_id`     INT          NULL,
            `subdomain`     VARCHAR(63)  NULL,
            `action`        VARCHAR(32)  NOT NULL,
            `detail`        VARCHAR(500) NULL,
            `ip_address`    VARCHAR(45)  NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_adminlog_tenant` (`tenant_id`),
            KEY `idx_adminlog_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table tenant_admin_log ready');

    // Audit trail for core/tenant_migration_runner.php (Phase 8). Separate from
    // tenant_provisioning_log (provisioning steps) and tenant_admin_log
    // (operator lifecycle actions) — this one is ongoing schema-drift history,
    // written every time migrations/tenant/ is applied across the fleet.
    // No FK: a tenant can be deleted long after its migration history matters.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenant_migration_log` (
            `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id`      INT          NULL,
            `subdomain`      VARCHAR(63)  NULL,
            `migration_name` VARCHAR(191) NOT NULL,
            `status`         ENUM('ok','failed') NOT NULL,
            `message`        TEXT         NULL,
            `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_migrationlog_tenant` (`tenant_id`),
            KEY `idx_migrationlog_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table tenant_migration_log ready');

    // ── Feature entitlements (ternant.md Phase 11) ──────────────────────────
    // The catalogue of switchable feature areas, platform-wide. Lives HERE and
    // not in a tenant database because a tenant's own admin bypasses that
    // database's permission system entirely (isAdmin()) and can reach its rows.
    // A flag the tenant can flip is not a flag. Phase 9 proved tenants cannot
    // reach this database.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`features` (
            `feature_key`     VARCHAR(64)  NOT NULL,
            `label`           VARCHAR(100) NOT NULL,
            `description`     VARCHAR(255) NULL,
            `is_available`    TINYINT(1)   NOT NULL DEFAULT 1,
            `default_enabled` TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order`      INT          NOT NULL DEFAULT 0,
            PRIMARY KEY (`feature_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table features ready');

    // Per-tenant override. NO row means "use features.default_enabled", which is
    // why provisioning a tenant needs no change at all: a new company inherits
    // the platform defaults by having nothing written for it.
    //
    // updated_by has no FK and is not denormalised away for the same reason
    // tenant_admin_log keeps actor_email: the record of who revoked a company's
    // module must survive that operator's own account being deleted.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenant_features` (
            `tenant_id`   INT         NOT NULL,
            `feature_key` VARCHAR(64) NOT NULL,
            `is_enabled`  TINYINT(1)  NOT NULL,
            `updated_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by`  INT         NULL,
            PRIMARY KEY (`tenant_id`, `feature_key`),
            KEY `idx_tenantfeat_feature` (`feature_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table tenant_features ready');

    // Seed the catalogue from the code registry — the one source of truth for
    // which features exist (core/feature_registry.php). INSERT IGNORE, so an
    // operator's own is_available/default_enabled edits are never overwritten by
    // re-running this script; only genuinely new keys are added.
    require_once __DIR__ . '/../core/feature_registry.php';
    $seed = $admin->prepare("
        INSERT IGNORE INTO `{$controlDb}`.`features`
            (`feature_key`, `label`, `description`, `is_available`, `default_enabled`, `sort_order`)
        VALUES (?,?,?,1,?,?)
    ");
    $added = 0;
    foreach (bmsFeatureRegistry() as $key => $def) {
        $seed->execute([
            $key,
            $def['label'],
            $def['description'] ?? null,
            !empty($def['default']) ? 1 : 0,
            (int)($def['sort_order'] ?? 0),
        ]);
        $added += $seed->rowCount();
    }
    say('  · features catalogue seeded' . ($added ? " ({$added} new)" : ' (no new keys)'));

    // Older installs created superadmins before the lockout columns existed.
    $saCols = $admin->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'superadmins'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'failed_attempts' => "ADD COLUMN `failed_attempts` INT NOT NULL DEFAULT 0 AFTER `password_hash`",
        'locked_until'    => "ADD COLUMN `locked_until` DATETIME NULL AFTER `failed_attempts`",
        'last_login'      => "ADD COLUMN `last_login` DATETIME NULL AFTER `locked_until`",
    ] as $col => $clause) {
        if (!in_array($col, $saCols, true)) {
            $admin->exec("ALTER TABLE `{$controlDb}`.`superadmins` {$clause}");
            say("  + superadmins.{$col} added");
        }
    }
} catch (PDOException $e) {
    die_with('Creating the control tables failed: ' . $e->getMessage());
}

// ── 3. Verify through the real code path ────────────────────────────────────
if (controlDbReady()) {
    say('  ✓ getControlPdo() connects and can read the registry');
} else {
    die_with('Tables exist but getControlPdo() cannot read them. Check the CONTROL_DB_* environment variables.');
}

$n = (int)getControlPdo()->query('SELECT COUNT(*) FROM superadmins')->fetchColumn();
say();
say('  Control database is ready.');
if ($n === 0) {
    say('  Next: create the first platform operator —');
    say('      php scripts/create_superadmin.php --email=you@example.com --name="Your Name"');
}
say('  Then follow docs/MULTI_TENANCY_CONVENTIONS.md §9 to switch multi-tenancy on.');
say();
