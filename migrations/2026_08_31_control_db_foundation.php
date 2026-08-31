<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/control_db.php';
global $pdo;

/**
 * Multi-tenancy Phase 1 — the control database.
 *
 * Creates `bms_control` and its three tables. This is the registry that knows
 * which tenants exist, where their databases live, and how to authenticate to
 * them. It holds NO business data.
 *
 * Nothing reads these tables yet — Phase 3 wires up connection routing. Running
 * this migration is therefore a no-op as far as the running application is
 * concerned, which is what makes it safe to deploy ahead of the rest.
 *
 * Idempotent: CREATE DATABASE / TABLE IF NOT EXISTS throughout, so a second run
 * changes nothing.
 *
 * Note this migration uses the app's own $pdo (from roots.php) rather than
 * getControlPdo() — it has to, because getControlPdo() connects *to* the
 * database this migration is responsible for creating.
 */

$controlDb = controlDbName();

// The control DB name can be overridden by CONTROL_DB_NAME, and a database name
// cannot be a bound parameter — it is interpolated straight into DDL. Validate it
// rather than trusting the environment.
if (!preg_match('/^[a-z0-9_]{1,64}$/i', $controlDb)) {
    echo "Migration failed: invalid control database name '{$controlDb}' "
       . "(CONTROL_DB_NAME must be alphanumeric/underscore, max 64 chars).\n";
    exit(1);
}

echo "Starting migration: multi-tenant control database ({$controlDb})...\n";

try {
    // ── The database itself ──────────────────────────────────────────────────
    $exists = (bool)$pdo->query(
        "SELECT 1 FROM information_schema.schemata WHERE schema_name = " . $pdo->quote($controlDb)
    )->fetchColumn();

    if ($exists) {
        echo "  · database {$controlDb} already exists — skipped.\n";
    } else {
        try {
            $pdo->exec("CREATE DATABASE `{$controlDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            echo "  + database {$controlDb} created.\n";
        } catch (PDOException $e) {
            // The app's MySQL user may legitimately lack CREATE DATABASE on a
            // hardened production server. Fail loudly with the exact SQL a DBA
            // needs, rather than a bare "access denied" the deploy log buries.
            echo "Migration failed: could not create database {$controlDb}.\n";
            echo "  The application's MySQL user lacks the CREATE privilege.\n";
            echo "  Ask the DBA to run this once, then re-run the deploy:\n\n";
            echo "    CREATE DATABASE `{$controlDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;\n";
            echo "    GRANT ALL PRIVILEGES ON `{$controlDb}`.* TO '<app_user>'@'<host>';\n";
            echo "    FLUSH PRIVILEGES;\n\n";
            echo "  Underlying error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    // ── tenants ──────────────────────────────────────────────────────────────
    // The registry proper. db_name is a real stored column, never computed from
    // the id: Tenant #1 keeps the legacy name `bms` while every provisioned
    // tenant gets `bms_t{id}`. Code must always read this column.
    //
    // subdomain is UNIQUE across ALL rows including status='deleted'. That is
    // deliberate — a deleted tenant keeps its claim so a new signup cannot
    // inherit a dead company's subdomain, along with any stale bookmarks,
    // emailed links or search-engine entries pointing at it.
    $pdo->exec("
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
    echo "  · table tenants ready.\n";

    // ── superadmins ──────────────────────────────────────────────────────────
    // Platform operators. Completely separate from any tenant's `users` table —
    // a superadmin is not a user of any tenant, and no tenant user can become
    // one. Left EMPTY on purpose: shipping a default account with known
    // credentials would be a backdoor into every tenant. The first superadmin is
    // created deliberately at go-live (Phase 10 checklist).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$controlDb}`.`superadmins` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `name`          VARCHAR(191) NOT NULL,
            `email`         VARCHAR(191) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_superadmins_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
    echo "  · table superadmins ready.\n";

    // ── tenant_provisioning_log ──────────────────────────────────────────────
    // Audit trail for every provisioning attempt, successful or not — the thing
    // that makes a failed signup debuggable after the fact.
    //
    // tenant_id is NULLable and carries NO foreign key on purpose. Provisioning
    // logs steps before the tenants row exists, and Phase 2's rollback-on-failure
    // deletes that row while the log of *why it failed* must survive. An FK would
    // either block the rollback or cascade away the evidence.
    //
    // message must never contain a plaintext password (Phase 9 audits this).
    $pdo->exec("
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
    echo "  · table tenant_provisioning_log ready.\n";

    // ── Verify through the real connection path ──────────────────────────────
    // Proves getControlPdo() actually works from here on, rather than assuming
    // it will. Catches a wrong CONTROL_DB_* env var now instead of in Phase 3.
    if (controlDbReady()) {
        echo "  ✓ getControlPdo() connects and sees the registry.\n";
    } else {
        echo "Migration failed: tables were created but getControlPdo() cannot read them.\n";
        echo "  Check the CONTROL_DB_* environment variables on this server.\n";
        exit(1);
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
