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
 * running it again is always safe — this is also why it's safe to still run by
 * hand at any time (`php scripts/setup_control_db.php` / `--check`).
 *
 * ALSO invoked automatically from deploy.yml since 2026-09-14 (§10's own
 * "Wired into deploy.yml" subsection in MULTI_TENANCY_CONVENTIONS.md) — but
 * wrapped in the same non-aborting `|| echo` guard tenant migrations already
 * use, not the unguarded `migrations/runner.php` path that caused the
 * 2026-08-31 incident this docblock describes. A failure here now warns
 * loudly in the deploy log instead of silently requiring someone to remember
 * to re-run it by hand (which is exactly what let a since-added table,
 * feature_upgrade_requests, go missing on demo_control for two weeks).
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

    // Break-glass for the platform operator themselves.
    //
    // Until this existed, an operator who forgot their password — or who
    // tripped their own 5-attempt lockout — had exactly one way back:
    // scripts/create_superadmin.php or raw SQL, over SSH. That is not a
    // recovery path, it is an outage with a shell prompt.
    //
    // Same shape as the tenant-side password_resets: SHA-256 of the token so a
    // leaked dump is worthless, UNIQUE so single-use is enforceable, short
    // expiry, and an attempt ledger because throttling cannot live in a
    // session belonging to someone who is not signed in.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`superadmin_password_resets` (
            `reset_id`      INT AUTO_INCREMENT PRIMARY KEY,
            `superadmin_id` INT          NOT NULL,
            `token_hash`    CHAR(64)     NOT NULL,
            `destination`   VARCHAR(191) NOT NULL DEFAULT '',
            `expires_at`    DATETIME     NOT NULL,
            `used_at`       DATETIME     NULL,
            `request_ip`    VARCHAR(45)  NOT NULL DEFAULT '',
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_sapr_token` (`token_hash`),
            KEY `idx_sapr_owner` (`superadmin_id`, `created_at`),
            KEY `idx_sapr_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table superadmin_password_resets ready');

    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`superadmin_reset_attempts` (
            `attempt_id` INT AUTO_INCREMENT PRIMARY KEY,
            `identifier` VARCHAR(191) NOT NULL DEFAULT '',
            `request_ip` VARCHAR(45)  NOT NULL DEFAULT '',
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_sara_identifier` (`identifier`, `created_at`),
            KEY `idx_sara_ip` (`request_ip`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table superadmin_reset_attempts ready');

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

    // ── Platform settings (ternant.md Phase "superadmin professional gap") ──
    // The control database's only general-purpose settings store. Mirrors the
    // tenant-side `system_settings` key/value shape (get_setting()/save_setting()
    // in helpers.php) on purpose — same shape, same mental model, just scoped to
    // the platform rather than one company. Starts empty; a platform with no
    // email configured yet degrades to "platform mail disabled", never a fatal.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`platform_settings` (
            `setting_key`   VARCHAR(64) NOT NULL,
            `setting_value` TEXT        NULL,
            `updated_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by`    INT         NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table platform_settings ready');

    // ── Plans (superadmin professional-gap plan, Phase C) ────────────────────
    // A reusable bundle: a name, a feature set, and quota limits — so an
    // operator can apply "Starter"/"Pro" in one click instead of hand-setting
    // every tenant's features and quotas individually. Deliberately NOT a new
    // enforcement mechanism: applying a plan just calls the existing
    // setTenantFeatures()/setTenantQuotas() (Phases 11/12) with the plan's
    // values, so a tenant stays fully independently editable afterward — a
    // plan is a starting point, not a rigid link. No tenants.plan_id FK: the
    // existing tenants.plan VARCHAR is set to the plan's name at apply time,
    // same denormalised-label convention as tenant_admin_log.actor_email — a
    // later rename or retirement of the plan does not disturb tenants that
    // already applied it.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`plans` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `plan_key`       VARCHAR(64)  NOT NULL,
            `name`           VARCHAR(100) NOT NULL,
            `description`    VARCHAR(255) NULL,
            `max_users`      INT          NULL,
            `max_storage_mb` INT          NULL,
            `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order`     INT          NOT NULL DEFAULT 0,
            `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_plans_key` (`plan_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table plans ready');

    // Which features a plan includes. Absence = not included — applying a
    // plan is a COMPLETE feature-set replacement (same semantics as
    // tenant_features's own override rows), not an incremental grant.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`plan_features` (
            `plan_id`     INT         NOT NULL,
            `feature_key` VARCHAR(64) NOT NULL,
            PRIMARY KEY (`plan_id`, `feature_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table plan_features ready');

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

    // The reserved "Blank" plan (tenant_module_control_plan.md §5.1) — what
    // self-registration applies when the platform's provisioning switch is set
    // to "nothing but the base essentials". INSERT IGNORE so re-running this
    // script never resurrects it if an operator ever renamed/retired it, and
    // deliberately zero plan_features rows: it exists to represent "nothing",
    // not a real bundle. Never shown in any plan-picker in the superadmin UI —
    // filtered out by plan_key wherever plans are listed for a human to choose.
    $admin->exec("
        INSERT IGNORE INTO `{$controlDb}`.`plans`
            (`plan_key`, `name`, `description`, `is_active`, `sort_order`)
        VALUES ('blank', 'Blank (system)',
                'Reserved for self-registration''s \"nothing but the base essentials\" mode. Not meant to be applied by hand.',
                1, 999)
    ");
    say('  · reserved "blank" plan seeded');

    // ── Broadcast log (superadmin_plan.md P9) ───────────────────────────────
    // Records every bulk email sent to tenants from the superadmin broadcast page.
    // sent_by has no FK — same denormalised-id convention as tenant_admin_log.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`broadcast_log` (
            `id`                 INT AUTO_INCREMENT PRIMARY KEY,
            `subject`            VARCHAR(255) NOT NULL,
            `body`               TEXT         NOT NULL,
            `audience_definition` VARCHAR(255) NOT NULL,
            `recipients_count`   INT          NOT NULL DEFAULT 0,
            `sent_by`            INT          NULL,
            `sent_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_broadcast_sent` (`sent_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table broadcast_log ready');

    // ── Payment history (subscription_plan.md) ──────────────────────────────
    // One row per recorded payment. Source of truth for subscription_ends_at:
    // postPayment() inserts here and then updates tenants.subscription_ends_at.
    // No FK — same denormalised-id convention as tenant_admin_log.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenant_payments` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id`       INT          NOT NULL,
            `amount_tzs`      INT          NOT NULL,
            `duration_months` TINYINT      NOT NULL,
            `starts_at`       DATE         NOT NULL,
            `ends_at`         DATE         NOT NULL,
            `notes`           VARCHAR(500) NULL,
            `recorded_by`     INT          NULL,
            `recorded_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tp_tenant` (`tenant_id`),
            KEY `idx_tp_ends`   (`ends_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table tenant_payments ready');

    // The paid-BJP-invoice → subscription bridge (core/vendor_bridge.php) never
    // guesses: when it cannot apply a payment safely it leaves one of these for
    // the superadmin. One open row per (invoice_ref, kind).
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`vendor_bridge_alerts` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id`   INT          NULL,
            `customer_id` INT          NULL,
            `invoice_ref` VARCHAR(64)  NOT NULL,
            `kind`        ENUM('no_term','not_linked','many_tenants','apply_failed','payment_voided') NOT NULL,
            `message`     VARCHAR(500) NOT NULL,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `resolved_at` DATETIME     NULL,
            `resolved_by` VARCHAR(191) NULL,
            KEY `idx_vba_open` (`resolved_at`),
            KEY `idx_vba_tenant` (`tenant_id`),
            KEY `idx_vba_ref` (`invoice_ref`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table vendor_bridge_alerts ready');

    // Where a payment came from: typed in by an operator, or a paid BJP invoice
    // (core/vendor_bridge.php). UNIQUE(source, source_ref) is what stops one
    // invoice extending a tenant twice; manual rows leave source_ref NULL,
    // which MySQL never treats as a duplicate.
    $tpCols = $admin->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'tenant_payments'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'source'     => "ADD COLUMN `source` ENUM('manual','vendor_invoice') NOT NULL DEFAULT 'manual' AFTER `notes`",
        'source_ref' => "ADD COLUMN `source_ref` VARCHAR(64) NULL AFTER `source`",
    ] as $col => $clause) {
        if (!in_array($col, $tpCols, true)) {
            $admin->exec("ALTER TABLE `{$controlDb}`.`tenant_payments` {$clause}");
            say("  + tenant_payments.{$col} added");
        }
    }
    $hasTpIdx = $admin->query("
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = " . $admin->quote($controlDb) . "
          AND table_name = 'tenant_payments' AND index_name = 'uq_tp_source'
    ")->fetchColumn();
    if (!$hasTpIdx) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`tenant_payments` ADD UNIQUE KEY `uq_tp_source` (`source`, `source_ref`)");
        say('  + tenant_payments unique (source, source_ref) added');
    }

    // ── Superadmin notifications ─────────────────────────────────────────────
    // One row per event (trial/subscription expired). Drives the bell badge in
    // the superadmin header. Deduplicated per (tenant_id, type) per day.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`superadmin_notifications` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `type`        ENUM('trial_expired','subscription_expired') NOT NULL,
            `title`       VARCHAR(255)  NOT NULL,
            `body`        VARCHAR(500)  NOT NULL,
            `tenant_id`   INT          NULL,
            `tenant_name` VARCHAR(255) NULL,
            `subdomain`   VARCHAR(63)  NULL,
            `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_san_unread`   (`is_read`, `created_at`),
            KEY `idx_san_tenant`   (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table superadmin_notifications ready');

    // ── Self-service module requests (tenant_module_control_plan.md, Phase C) ──
    // A tenant's own admin asking for a module they don't have, and a
    // superadmin approving/declining. Lives here, not in any tenant database,
    // for the same reason `features`/`tenant_features` do: a request FROM a
    // tenant and a DECISION about it must both be readable by every
    // superadmin across every tenant in one place, which only the control
    // database can do.
    //
    // One open (pending) request per (tenant_id, feature_key) — a repeat click
    // updates the existing row's note rather than creating a duplicate,
    // enforced by the application layer (core/module_requests.php), not a
    // unique index, because a tenant MAY legitimately have more than one
    // *resolved* (approved/declined) historical request for the same feature
    // over time — only one truly OPEN one at once.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`feature_upgrade_requests` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id`     INT NOT NULL,
            `feature_key`   VARCHAR(64) NOT NULL,
            `requested_by`  INT NOT NULL,
            `note`          VARCHAR(500) NULL,
            `status`        ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
            `decided_by`    INT NULL,
            `decided_at`    DATETIME NULL,
            `decision_note` VARCHAR(500) NULL,
            `reminded_at`   DATETIME NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tenant_status` (`tenant_id`, `status`),
            KEY `idx_status_created` (`status`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table feature_upgrade_requests ready');

    // Usage quotas (ternant.md Phase 12). NULL means unlimited — deliberately not
    // a magic -1, so "no limit set" and "a real number" can never be confused.
    // Two plain columns on `tenants`, not a second features/tenant_features-shaped
    // pair of tables: unlike entitlements this isn't an extensible catalogue of
    // independent toggles, it's two numbers per tenant.
    $tCols = $admin->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'tenants'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'max_users'      => "ADD COLUMN `max_users` INT NULL AFTER `plan`",
        'max_storage_mb' => "ADD COLUMN `max_storage_mb` INT NULL AFTER `max_users`",
        // Whether THIS tenant's own admin may change POS Simple Mode
        // themselves (Available Modules > Point of Sale > More). 0 = tenant
        // can self-manage (default — matches behaviour before this column
        // existed); 1 = locked, the tenant's own "More" button is hidden and
        // api/pos/save_simple_mode.php refuses the write server-side too.
        // The setting's actual VALUE lives in the tenant's own database
        // (system_settings.pos_simple_mode) — this is only the lock.
        'pos_simple_mode_locked' => "ADD COLUMN `pos_simple_mode_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_storage_mb`",
        // Superadmin-only override: shows the full (non-simplified) Product
        // form even on a tenant running Simple POS mode. Same shape as
        // pos_simple_mode_locked — the setting's VALUE lives in the tenant's
        // own database (system_settings.pos_advanced_product); this is only
        // the lock, and it's always 1 once set (the tenant admin never
        // self-manages this — products_simple_pos_plan.md §3).
        'pos_advanced_product_locked' => "ADD COLUMN `pos_advanced_product_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pos_simple_mode_locked`",
        // Same shape again (2026-09-17): independent superadmin-only overrides
        // showing the full (non-simplified) Customer / Supplier registration
        // form even on a tenant running Simple POS mode. Kept separate from
        // pos_advanced_product_locked — a shop may want simple products but
        // still need full supplier records (e.g. formal bank-transfer
        // payment details), or vice versa. Values live in the tenant's own
        // database (system_settings.pos_advanced_customer / _supplier); these
        // are only the locks.
        'pos_advanced_customer_locked' => "ADD COLUMN `pos_advanced_customer_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pos_advanced_product_locked`",
        'pos_advanced_supplier_locked' => "ADD COLUMN `pos_advanced_supplier_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pos_advanced_customer_locked`",
        // 2026-09-17 — lightweight Supplier visibility for Simple POS tenants
        // that don't have the full Procurement module enabled. Unlike the
        // pos_advanced_* toggles (which change the FORM shown once a page is
        // already reachable), this one changes REACHABILITY itself: it makes
        // core/feature_registry.php::tenantModuleAllowsPage() treat the
        // 'suppliers' page_key as allowed even with 'procurement' off. Value
        // lives in the tenant's own database (system_settings.pos_supplier_access);
        // this is only the lock.
        'pos_supplier_access_locked' => "ADD COLUMN `pos_supplier_access_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pos_advanced_supplier_locked`",

        // ── Professional tenant management columns (superadmin_plan.md P0) ────
        // Trial lifecycle
        'trial_ends_at'      => "ADD COLUMN `trial_ends_at` DATETIME NULL AFTER `suspended_at`",
        'trial_extended_by'  => "ADD COLUMN `trial_extended_by` INT NULL AFTER `trial_ends_at`",
        // Owner contact — mirrored from the tenant DB so superadmin never has to open it
        'owner_first_name'   => "ADD COLUMN `owner_first_name` VARCHAR(100) NULL AFTER `owner_email`",
        'owner_last_name'    => "ADD COLUMN `owner_last_name` VARCHAR(100) NULL AFTER `owner_first_name`",
        'owner_phone'        => "ADD COLUMN `owner_phone` VARCHAR(20) NULL AFTER `owner_last_name`",
        // Business classification
        'country'            => "ADD COLUMN `country` VARCHAR(100) NULL AFTER `owner_phone`",
        'industry'           => "ADD COLUMN `industry` VARCHAR(64) NULL AFTER `country`",
        'company_size'       => "ADD COLUMN `company_size` VARCHAR(20) NULL AFTER `industry`",
        // Engagement
        'last_active_at'     => "ADD COLUMN `last_active_at` DATETIME NULL AFTER `company_size`",
        // Operator notes
        'notes'              => "ADD COLUMN `notes` TEXT NULL AFTER `last_active_at`",
        'notes_updated_at'   => "ADD COLUMN `notes_updated_at` DATETIME NULL AFTER `notes`",
        'notes_updated_by'   => "ADD COLUMN `notes_updated_by` INT NULL AFTER `notes_updated_at`",
        // Billing
        'billing_cycle'      => "ADD COLUMN `billing_cycle` ENUM('monthly','annual') NULL AFTER `notes_updated_by`",
        'billing_amount_tzs' => "ADD COLUMN `billing_amount_tzs` INT NULL AFTER `billing_cycle`",
        'next_billing_date'  => "ADD COLUMN `next_billing_date` DATE NULL AFTER `billing_amount_tzs`",
        'payment_status'     => "ADD COLUMN `payment_status` ENUM('current','overdue','pending','none') NOT NULL DEFAULT 'none' AFTER `next_billing_date`",
        // Comms opt-out
        'unsubscribed_at'    => "ADD COLUMN `unsubscribed_at` DATETIME NULL AFTER `payment_status`",
        // Subscription expiry (set automatically when a payment is recorded)
        'subscription_ends_at' => "ADD COLUMN `subscription_ends_at` DATE NULL AFTER `unsubscribed_at`",
        // Why a tenant is currently suspended (set on auto-suspend; cleared on activate)
        'suspension_reason'    => "ADD COLUMN `suspension_reason` ENUM('trial_expired','subscription_expired','manual') NULL AFTER `subscription_ends_at`",
        // Grace period end date — set when trial/subscription expires; tenant stays accessible until this date
        'grace_until'          => "ADD COLUMN `grace_until` DATE NULL AFTER `suspension_reason`",
        // Per-tenant grace override. NULL = follow the platform default, which
        // is NOT the same as 0 (cut off the moment it expires). "They asked for
        // more time" should not mean giving every other customer more time too.
        'grace_days'           => "ADD COLUMN `grace_days` TINYINT UNSIGNED NULL AFTER `grace_until`",
        // The customer (in BJP's own books, on the platform's root domain) who
        // pays for this tenant. Set by "Make real customer" on Customer Visits
        // or by an operator; read by the invoice → subscription bridge.
        'vendor_customer_id'   => "ADD COLUMN `vendor_customer_id` INT NULL AFTER `grace_days`",
        // "BJP-CUST-0042 — Duka la Juma", copied at link time so the superadmin panel
        // can say who pays without ever opening BJP's own database.
        'vendor_customer_label' => "ADD COLUMN `vendor_customer_label` VARCHAR(255) NULL AFTER `vendor_customer_id`",
    ] as $col => $clause) {
        if (!in_array($col, $tCols, true)) {
            $admin->exec("ALTER TABLE `{$controlDb}`.`tenants` {$clause}");
            say("  + tenants.{$col} added");
        }
    }
    $hasVcIdx = $admin->query("
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = " . $admin->quote($controlDb) . "
          AND table_name = 'tenants' AND index_name = 'idx_tenants_vendor_customer'
    ")->fetchColumn();
    if (!$hasVcIdx) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`tenants` ADD KEY `idx_tenants_vendor_customer` (`vendor_customer_id`)");
        say('  + tenants index on vendor_customer_id added');
    }

    // ── Service plans (service_plans_plan.md Phase 1) ───────────────────────
    // What BJP sells: a platform PRODUCT (Shop Mode, full BMS) has public
    // PLANS, each with PRICES per term, extra "what's included" lines, and
    // ADD-ONS (extra shop / extra user) priced per unit per term. Superadmin
    // is the master; BJP's own books only read these (D1). Prices are never
    // hard-deleted — issued quotations/invoices snapshot them, and a retired
    // price must keep resolving.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`platform_products` (
            `product_key`          VARCHAR(32)  NOT NULL,
            `name`                 VARCHAR(100) NOT NULL,
            `description`          VARCHAR(255) NULL,
            `show_on_registration` TINYINT(1)   NOT NULL DEFAULT 0,
            `sort_order`           INT          NOT NULL DEFAULT 0,
            `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`product_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    // INSERT IGNORE: an operator's own edits (name, registration switch) are
    // never overwritten. No plans are seeded — the owner types the real ones.
    $admin->exec("
        INSERT IGNORE INTO `{$controlDb}`.`platform_products`
            (`product_key`, `name`, `description`, `show_on_registration`, `sort_order`)
        VALUES ('bms_shop', 'Smart POS', 'Point of sale, stock and simple shop management.', 0, 1),
               ('bms_full', 'Smart BMS', 'The complete business management system.', 0, 2)
    ");
    // 2026-10-18 rename — only rows still carrying the original seed name, so a
    // name the operator typed is never overwritten. Re-running is a no-op.
    $admin->exec("UPDATE `{$controlDb}`.`platform_products` SET `name` = 'Smart POS' WHERE `product_key` = 'bms_shop' AND `name` = 'BMS Shop Mode'");
    $admin->exec("UPDATE `{$controlDb}`.`platform_products` SET `name` = 'Smart BMS' WHERE `product_key` = 'bms_full' AND `name` = 'BMS Full'");
    say('  · table platform_products ready (bms_shop, bms_full)');

    $plCols = $admin->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'plans'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'product_key' => "ADD COLUMN `product_key` VARCHAR(32) NULL AFTER `plan_key`",
        'tagline'     => "ADD COLUMN `tagline` VARCHAR(150) NULL AFTER `name`",
        'max_shops'   => "ADD COLUMN `max_shops` INT NULL AFTER `max_storage_mb`",
        // Offered to the public (registration page, tenant Billing page).
        'is_public'   => "ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`",
        'is_popular'  => "ADD COLUMN `is_popular` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_public`",
        // Higher = bigger plan. Decides upgrade (apply now) vs downgrade (at period end).
        'tier_rank'   => "ADD COLUMN `tier_rank` INT NOT NULL DEFAULT 0 AFTER `is_popular`",
    ] as $col => $clause) {
        if (!in_array($col, $plCols, true)) {
            $admin->exec("ALTER TABLE `{$controlDb}`.`plans` {$clause}");
            say("  + plans.{$col} added");
        }
    }

    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`plan_prices` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `plan_id`        INT          NOT NULL,
            `term_label`     VARCHAR(60)  NOT NULL,
            `duration_value` SMALLINT     NOT NULL,
            `duration_unit`  ENUM('month','year') NOT NULL,
            `price_tzs`      INT UNSIGNED NOT NULL,
            `is_default`     TINYINT(1)   NOT NULL DEFAULT 0,
            `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order`     INT          NOT NULL DEFAULT 0,
            `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_plan_price_term` (`plan_id`, `duration_value`, `duration_unit`),
            KEY `idx_plan_prices_plan` (`plan_id`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table plan_prices ready');

    // Extra "what's included" lines. Modules, users, shops and storage are
    // generated from the enforced fields — never typed here twice.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`plan_items` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `plan_id`    INT          NOT NULL,
            `label`      VARCHAR(100) NOT NULL,
            `value`      VARCHAR(100) NULL,
            `sort_order` INT          NOT NULL DEFAULT 0,
            KEY `idx_plan_items_plan` (`plan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table plan_items ready');

    // Add-ons raise ONE enforced limit by `units` per quantity bought.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`plan_addons` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `plan_id`    INT          NOT NULL,
            `addon_key`  VARCHAR(64)  NOT NULL,
            `name`       VARCHAR(100) NOT NULL,
            `quota`      ENUM('max_shops','max_users') NOT NULL,
            `units`      INT          NOT NULL DEFAULT 1,
            `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order` INT          NOT NULL DEFAULT 0,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_plan_addon_key` (`plan_id`, `addon_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    // An add-on's price for one of the plan's terms — keyed to the plan
    // price, so an add-on line always follows (and locks to) the plan's term.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`plan_addon_prices` (
            `addon_id`      INT          NOT NULL,
            `plan_price_id` INT          NOT NULL,
            `price_tzs`     INT UNSIGNED NOT NULL,
            PRIMARY KEY (`addon_id`, `plan_price_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · tables plan_addons, plan_addon_prices ready');

    $tCols2 = $admin->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'tenants'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        // NULL = unlimited, same convention as max_users.
        'max_shops'          => "ADD COLUMN `max_shops` INT NULL AFTER `max_storage_mb`",
        // A paid downgrade waits for the end of the period already paid for (D5).
        'scheduled_plan_key' => "ADD COLUMN `scheduled_plan_key` VARCHAR(64) NULL AFTER `plan`",
        'scheduled_plan_at'  => "ADD COLUMN `scheduled_plan_at` DATE NULL AFTER `scheduled_plan_key`",
        // The owner's real email (tenants.owner_email holds the login phone).
        // Set by self-registration; one free trial per address (Phase 3).
        'owner_contact_email' => "ADD COLUMN `owner_contact_email` VARCHAR(191) NULL AFTER `owner_email`",
    ] as $col => $clause) {
        if (!in_array($col, $tCols2, true)) {
            $admin->exec("ALTER TABLE `{$controlDb}`.`tenants` {$clause}");
            say("  + tenants.{$col} added");
        }
    }
    $hasContactIdx = (int)$admin->query("
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'tenants'
          AND index_name = 'idx_tenants_contact_email'
    ")->fetchColumn();
    if (!$hasContactIdx) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`tenants` ADD KEY `idx_tenants_contact_email` (`owner_contact_email`)");
        say('  + tenants.idx_tenants_contact_email added');
    }

    // Registration → "make this company a BJP customer" outbox (Phase 4).
    // One row per tenant: re-queuing can never create a second customer.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`vendor_link_queue` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id`  INT          NOT NULL,
            `state`      ENUM('pending','done','needs_review','failed') NOT NULL DEFAULT 'pending',
            `attempts`   INT          NOT NULL DEFAULT 0,
            `message`    VARCHAR(500) NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_vlq_tenant` (`tenant_id`),
            KEY `idx_vlq_state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table vendor_link_queue ready');

    // Extra shops/users bought as add-on lines on a paid BJP invoice (Phase 5).
    // Effective limit = tenants.max_X + SUM(units) of the grants active today;
    // a grant lasts the plan period it was bought with (starts_at..ends_at).
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenant_addon_grants` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id`  INT          NOT NULL,
            `quota`      ENUM('max_shops','max_users') NOT NULL,
            `units`      INT          NOT NULL,
            `starts_at`  DATE         NOT NULL,
            `ends_at`    DATE         NOT NULL,
            `source_ref` VARCHAR(64)  NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tag_tenant` (`tenant_id`, `quota`, `ends_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table tenant_addon_grants ready');

    // A phone/email-only match is confirmed by a person, never auto-linked (D4).
    $vbaKind = $admin->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = " . $admin->quote($controlDb) . "
          AND TABLE_NAME = 'vendor_bridge_alerts' AND COLUMN_NAME = 'kind'
    ")->fetchColumn();
    if ($vbaKind !== false && strpos((string)$vbaKind, 'possible_match') === false) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`vendor_bridge_alerts`
            MODIFY COLUMN `kind` ENUM('no_term','not_linked','many_tenants','apply_failed','payment_voided','possible_match') NOT NULL");
        say('  · vendor_bridge_alerts.kind ENUM expanded (possible_match added)');
    }

    // Backfill trial_ends_at for existing rows that pre-date the column.
    // Uses created_at + 14 days as a proxy — safe to re-run (WHERE IS NULL).
    $admin->exec("
        UPDATE `{$controlDb}`.`tenants`
           SET `trial_ends_at` = DATE_ADD(`created_at`, INTERVAL 14 DAY)
         WHERE `trial_ends_at` IS NULL
    ");
    say('  · trial_ends_at backfilled for existing rows');

    // Expand superadmin_notifications.type ENUM to include grace-period event types.
    $sanType = $admin->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = " . $admin->quote($controlDb) . "
          AND TABLE_NAME = 'superadmin_notifications' AND COLUMN_NAME = 'type'
    ")->fetchColumn();
    if ($sanType !== false && strpos((string)$sanType, 'trial_grace_started') === false) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`superadmin_notifications`
            MODIFY COLUMN `type` ENUM('trial_expired','subscription_expired','trial_grace_started','subscription_grace_started') NOT NULL");
        say('  · superadmin_notifications.type ENUM expanded (grace types added)');
    }

    // Add 'archived' to the tenant status ENUM (idempotent check).
    //
    // Before this there were only two ways to stop serving a company:
    // 'suspended', which reads as "we are chasing you for payment" and stays
    // in the operator's working list, and 'deleted', which destroys the
    // database. A company that has simply LEFT needed a third: locked out,
    // data intact, reversible, and out of the default view.
    $stType = $admin->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = " . $admin->quote($controlDb) . "
          AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'status'
    ")->fetchColumn();
    if ($stType !== false && strpos((string)$stType, 'archived') === false) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`tenants`
            MODIFY COLUMN `status` ENUM('trial','active','suspended','archived','deleted')
            NOT NULL DEFAULT 'trial'");
        say("  · tenants.status ENUM expanded (archived added)");
    }

    // Smart BMS signups wait for a superadmin: suspended + awaiting_approval,
    // and approval_declined once refused (idempotent; existing values kept).
    $srType = $admin->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = " . $admin->quote($controlDb) . "
          AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'suspension_reason'
    ")->fetchColumn();
    if ($srType !== false && strpos((string)$srType, 'awaiting_approval') === false) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`tenants`
            MODIFY COLUMN `suspension_reason` ENUM('trial_expired','subscription_expired','manual','awaiting_approval','approval_declined') NULL");
        say("  · tenants.suspension_reason ENUM expanded (awaiting_approval, approval_declined added)");
    }
    $snType = $admin->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = " . $admin->quote($controlDb) . "
          AND TABLE_NAME = 'superadmin_notifications' AND COLUMN_NAME = 'type'
    ")->fetchColumn();
    if ($snType !== false && strpos((string)$snType, 'bms_request') === false) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`superadmin_notifications`
            MODIFY COLUMN `type` ENUM('trial_expired','subscription_expired','trial_grace_started','subscription_grace_started','bms_request') NOT NULL");
        say('  · superadmin_notifications.type ENUM expanded (bms_request added)');
        $snType = 'bms_request';   // so the step below sees the expanded list
    }
    // Company activity alerts (core/tenant_activity_alerts.php, mobile API upgrade Phase 2).
    if ($snType !== false && strpos((string)$snType, 'first_login') === false) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`superadmin_notifications`
            MODIFY COLUMN `type` ENUM('trial_expired','subscription_expired','trial_grace_started','subscription_grace_started','bms_request',
                                      'first_login','first_product','first_sale','inactive') NOT NULL");
        say('  · superadmin_notifications.type ENUM expanded (first_login, first_product, first_sale, inactive added)');
    }
    // One row per company + alert already sent, so each rings once.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`tenant_activity_alerts` (
            `tenant_id` INT          NOT NULL,
            `alert`     VARCHAR(40)  NOT NULL,
            `sent_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`tenant_id`, `alert`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // Expand billing_cycle ENUM to include quarterly and biannual (idempotent check).
    $bcType = $admin->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = " . $admin->quote($controlDb) . "
          AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'billing_cycle'
    ")->fetchColumn();
    if ($bcType !== false && strpos((string)$bcType, 'quarterly') === false) {
        $admin->exec("ALTER TABLE `{$controlDb}`.`tenants`
            MODIFY COLUMN `billing_cycle` ENUM('monthly','quarterly','biannual','annual') NULL");
        say("  · tenants.billing_cycle ENUM expanded (quarterly, biannual added)");
    }

    // Async mobile registration queue. Stores validated, pending registration
    // requests so api/mobile/register.php can return in < 1s while the slow
    // tenant provisioning (schema + seed + geography SQL) runs via cron.
    // owner_pass_enc holds the password encrypted with encryptTenantSecret() and
    // is cleared to '' immediately after the worker provisions the tenant.
    $admin->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`registration_jobs` (
            `id`               BIGINT AUTO_INCREMENT PRIMARY KEY,
            `job_id`           CHAR(64)     NOT NULL,
            `status`           ENUM('pending','provisioning','ready','failed') NOT NULL DEFAULT 'pending',
            `company_name`     VARCHAR(255) NOT NULL,
            `subdomain`        VARCHAR(100) NOT NULL,
            `owner_phone`      VARCHAR(100) NOT NULL,
            `owner_pass_enc`   TEXT         NOT NULL,
            `owner_first_name` VARCHAR(100) NOT NULL DEFAULT '',
            `owner_last_name`  VARCHAR(100) NOT NULL DEFAULT '',
            `owner_email`      VARCHAR(255) NOT NULL DEFAULT '',
            `phys_address`     TEXT         NOT NULL,
            `post_address`     TEXT         NOT NULL,
            `device_name`      VARCHAR(255) NOT NULL DEFAULT 'Flutter App',
            `ip_address`       VARCHAR(45)  NOT NULL DEFAULT '',
            `tenant_id`        INT          DEFAULT NULL,
            `tenant_url`       VARCHAR(500) DEFAULT NULL,
            `token`            VARCHAR(128) DEFAULT NULL,
            `error_message`    TEXT,
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at`       DATETIME     DEFAULT NULL,
            `completed_at`     DATETIME     DEFAULT NULL,
            UNIQUE KEY `uq_regjob_id`    (`job_id`),
            KEY `idx_regjob_status`      (`status`),
            KEY `idx_regjob_phone`       (`owner_phone`),
            KEY `idx_regjob_subdomain`   (`subdomain`),
            KEY `idx_regjob_created`     (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    say('  · table registration_jobs ready');

    // The plan the app user picked (service_plans_plan.md Phase 3). Only keys —
    // the worker re-checks them against the live catalogue before applying.
    $rjCols = $admin->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'registration_jobs'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'plan_key'  => "ADD COLUMN `plan_key` VARCHAR(64) NULL AFTER `device_name`",
        'plan_term' => "ADD COLUMN `plan_term` VARCHAR(8) NULL AFTER `plan_key`",
        // The owner's chosen login name (web signup has it; NULL = the phone, as before).
        'owner_username' => "ADD COLUMN `owner_username` VARCHAR(50) NULL AFTER `owner_phone`",
    ] as $col => $clause) {
        if (!in_array($col, $rjCols, true)) {
            $admin->exec("ALTER TABLE `{$controlDb}`.`registration_jobs` {$clause}");
            say("  + registration_jobs.{$col} added");
        }
    }

    // Older installs created superadmins before the lockout columns existed.
    $saCols = $admin->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $admin->quote($controlDb) . " AND table_name = 'superadmins'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'failed_attempts' => "ADD COLUMN `failed_attempts` INT NOT NULL DEFAULT 0 AFTER `password_hash`",
        'locked_until'    => "ADD COLUMN `locked_until` DATETIME NULL AFTER `failed_attempts`",
        'last_login'      => "ADD COLUMN `last_login` DATETIME NULL AFTER `locked_until`",
        // Two-step sign-in (core/superadmin_2fa.php). This account reaches
        // every company on the platform and had one password protecting it.
        // The secret is stored encrypted, never raw; totp_last_counter is the
        // replay guard (a code stays valid ~90s, so without it the same six
        // digits work twice); recovery codes are kept only as SHA-256.
        'totp_secret_enc'      => "ADD COLUMN `totp_secret_enc` VARCHAR(255) NULL AFTER `last_login`",
        'totp_confirmed_at'    => "ADD COLUMN `totp_confirmed_at` DATETIME NULL AFTER `totp_secret_enc`",
        'totp_last_counter'    => "ADD COLUMN `totp_last_counter` BIGINT NULL AFTER `totp_confirmed_at`",
        'totp_recovery_hashes' => "ADD COLUMN `totp_recovery_hashes` TEXT NULL AFTER `totp_last_counter`",
    ] as $col => $clause) {
        if (!in_array($col, $saCols, true)) {
            $admin->exec("ALTER TABLE `{$controlDb}`.`superadmins` {$clause}");
            say("  + superadmins.{$col} added");
        }
    }

    // Help & Support Center (support_center_plan.md) — BJP-facing requests and
    // platform-wide help content, readable by every superadmin across tenants.
    require_once __DIR__ . '/../core/support.php';
    foreach (supportEnsureSchema($admin, $controlDb) as $t) {
        say("  · table {$t} ready");
    }
    // Starter categories + draft articles, only into empty tables (BJP reviews and publishes).
    require_once __DIR__ . '/../core/support_articles.php';
    [$sc, $sa] = supportSeedDefaults($admin, $controlDb);
    if ($sc || $sa) say("  + support starter content: {$sc} categories, {$sa} draft articles");

    // Platform AI (platform_ai_plan.md) — per-tenant key source/cap and platform-key metering.
    require_once __DIR__ . '/../core/ai_platform.php';
    foreach (aiPlatformSchema($admin, $controlDb) as $t) {
        say("  · table {$t} ready");
    }

    // Scheduled backups (backup_plan.md) — one row per backup attempt per database.
    require_once __DIR__ . '/../core/tenant_backups.php';
    foreach (bmsBackupLogSchema($admin, $controlDb) as $t) {
        say("  · table {$t} ready");
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
