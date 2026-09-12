<?php
/**
 * core/tenant_migration_runner.php
 * ---------------------------------
 * Applies pending files from `migrations/tenant/` to every tenant database.
 *
 * Usage:
 *   php core/tenant_migration_runner.php                 # apply to every live tenant
 *   php core/tenant_migration_runner.php --tenant=7       # one tenant only (retry/debug)
 *   php core/tenant_migration_runner.php --dry-run        # report what WOULD run
 *
 * WIRED INTO deploy.yml AS OF 2026-09-02 — and it may never abort a deploy.
 * It runs last per host, guarded with `|| echo`, so a tenant-side failure warns
 * loudly while the release still lands on every host. That guard is the whole
 * point: on 2026-08-31 a migration for the optional, not-yet-live control
 * database exited 1, `script_stop: true` correctly halted everything, and the
 * second host never received the release (see the hotfix in changelog.md).
 * A per-tenant subsystem must never be able to veto the platform's deploy.
 *
 * Safe on a host with no multi-tenancy: with no control database, or no files
 * under migrations/tenant/, it reports "Nothing to do" and exits 0. Both no-op
 * paths were verified before the wiring went in. It still runs standalone by
 * hand or from cron exactly as before.
 *
 * ISOLATION, NOT A GLOBAL SCRIPT_STOP. ternant.md's "what ships" table says
 * a broken migration "stops on first failure for THAT TENANT" — but its
 * acceptance-gate wording also says the whole run "stops". Those two read as
 * contradictory, and this codebase already has a governing principle that
 * settles it: every other multi-tenancy phase enforces that one tenant's
 * failure must NEVER affect another (Phase 6's suspend/delete tests assert
 * exactly this). So: a migration that fails stops FURTHER migrations for that
 * one tenant (an inconsistent partial state within a single tenant would be
 * worse than stopping there) but processing CONTINUES to every other tenant.
 * The failure is not silent — it is logged loudly to both the console/log
 * file and the `tenant_migration_log` control table, and the process exits
 * non-zero if anything failed, for a human or a future cron alert to see.
 *
 * How a migration file gets its $pdo: see core/tenant_migration_bootstrap.php.
 * Each (tenant, file) pair runs as an isolated subprocess — exactly like the
 * app's own migrations/runner.php — so an exit(1) inside one migration can
 * never kill this runner's process, only that one subprocess.
 *
 * Public API (safe to require_once from a test or another script):
 *   tenantMigrationFiles(): array                    → sorted list of files
 *   runTenantMigrations(?int $onlyTenantId, bool $dryRun): array   → summary
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_crypto.php';

if (!function_exists('tenantMigrationFiles')) {
    /** Every migrations/tenant/*.php file, chronological by filename. */
    function tenantMigrationFiles(): array
    {
        $files = glob(__DIR__ . '/../migrations/tenant/[0-9]*.php') ?: [];
        sort($files);
        return $files;
    }
}

if (!function_exists('logTenantMigrationEvent')) {
    /** Best-effort audit row. A logging failure must never abort a migration run. */
    function logTenantMigrationEvent(?int $tenantId, ?string $subdomain, string $migration, string $status, ?string $message = null): void
    {
        try {
            getControlPdo()->prepare("
                INSERT INTO tenant_migration_log (tenant_id, subdomain, migration_name, status, message)
                VALUES (?,?,?,?,?)
            ")->execute([$tenantId, $subdomain, $migration, $status, $message === null ? null : substr($message, 0, 2000)]);
        } catch (Throwable $e) {
            error_log('tenant_migration_log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('runTenantMigrations')) {
    /**
     * Apply every pending migrations/tenant/ file to every live tenant.
     *
     * @return array{
     *   ran:bool, reason:?string, files:int, tenants_total:int,
     *   tenants:array<int, array{id:int, subdomain:string, applied:string[], failed:?string, error:?string}>
     * }
     *
     * ran=false means nothing was attempted at all (control DB not set up, or
     * no live tenants, or no migration files) — always a non-fatal outcome.
     */
    function runTenantMigrations(?int $onlyTenantId = null, bool $dryRun = false): array
    {
        $out = ['ran' => false, 'reason' => null, 'files' => 0, 'tenants_total' => 0, 'tenants' => []];

        // ── Graceful no-ops, matching the lesson from the control-DB hotfix ──
        // None of these are errors: a fresh install with multi-tenancy not yet
        // set up, or simply no tenants yet, is the NORMAL state before Phase 7.
        if (!controlDbReady()) {
            $out['reason'] = 'control database is not set up (run scripts/setup_control_db.php first if you intend to use multi-tenancy)';
            return $out;
        }

        $files = tenantMigrationFiles();
        $out['files'] = count($files);
        if (!$files) {
            $out['reason'] = 'no files under migrations/tenant/';
            return $out;
        }

        $cpdo = getControlPdo();
        // 'deleted' tenants have no database left to migrate. Suspended tenants
        // keep their database and should stay schema-current so reactivation
        // does not surface a stale schema.
        $sql = "SELECT * FROM tenants WHERE status != 'deleted'";
        $args = [];
        if ($onlyTenantId !== null) { $sql .= ' AND id = ?'; $args[] = $onlyTenantId; }
        $sql .= ' ORDER BY id';
        $st = $cpdo->prepare($sql);
        $st->execute($args);
        $tenants = $st->fetchAll();

        $out['tenants_total'] = count($tenants);
        if (!$tenants) {
            $out['reason'] = $onlyTenantId !== null ? "no live tenant with id {$onlyTenantId}" : 'no live tenants';
            return $out;
        }

        $out['ran'] = true;
        $s = controlDbSettings();

        foreach ($tenants as $t) {
            $result = ['id' => (int)$t['id'], 'subdomain' => (string)$t['subdomain'], 'applied' => [], 'failed' => null, 'error' => null];

            $password = decryptTenantSecret((string)$t['db_password_encrypted']);
            if ($password === null) {
                $result['error'] = 'could not decrypt stored credentials (check TENANT_CRED_KEY)';
                logTenantMigrationEvent((int)$t['id'], (string)$t['subdomain'], '(all)', 'failed', $result['error']);
                $out['tenants'][] = $result;
                continue;   // this tenant is skipped; every OTHER tenant still proceeds
            }

            // The runner's OWN connection to this tenant, separate from the
            // subprocess's — used only to track/record what has been applied.
            try {
                $tpdo = new PDO(
                    "mysql:host={$t['db_host']};dbname={$t['db_name']};charset=utf8mb4",
                    $t['db_username'], $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                );
            } catch (PDOException $e) {
                $result['error'] = 'could not connect: ' . $e->getMessage();
                logTenantMigrationEvent((int)$t['id'], (string)$t['subdomain'], '(all)', 'failed', $result['error']);
                $out['tenants'][] = $result;
                continue;
            }

            // Defensive: a tenant provisioned before Phase 8 predates
            // schema_migrations in the template. Idempotent either way.
            $tpdo->exec("
                CREATE TABLE IF NOT EXISTS `schema_migrations` (
                    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `migration_name` VARCHAR(191) NOT NULL,
                    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_schema_migrations_name` (`migration_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            ");

            $done = $tpdo->query("SELECT migration_name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
            $record = $tpdo->prepare("INSERT IGNORE INTO schema_migrations (migration_name) VALUES (?)");

            foreach ($files as $file) {
                $name = basename($file);
                if (in_array($name, $done, true)) continue;

                if ($dryRun) {
                    $result['applied'][] = "$name (dry-run, not executed)";
                    continue;
                }

                // Isolated subprocess, credentials passed via env — never argv.
                putenv('TENANT_MIGRATION_DB_HOST=' . $t['db_host']);
                putenv('TENANT_MIGRATION_DB_NAME=' . $t['db_name']);
                putenv('TENANT_MIGRATION_DB_USER=' . $t['db_username']);
                putenv('TENANT_MIGRATION_DB_PASS=' . $password);

                $output = [];
                $exitCode = 0;
                exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

                putenv('TENANT_MIGRATION_DB_HOST');
                putenv('TENANT_MIGRATION_DB_NAME');
                putenv('TENANT_MIGRATION_DB_USER');
                putenv('TENANT_MIGRATION_DB_PASS');

                if ($exitCode !== 0) {
                    $msg = implode("\n", $output);
                    $result['failed'] = $name;
                    $result['error']  = $msg;
                    logTenantMigrationEvent((int)$t['id'], (string)$t['subdomain'], $name, 'failed', $msg);
                    break;   // stop THIS tenant's sequence; other tenants are unaffected
                }

                $record->execute([$name]);
                $result['applied'][] = $name;
                logTenantMigrationEvent((int)$t['id'], (string)$t['subdomain'], $name, 'ok', null);
            }

            $out['tenants'][] = $result;
        }

        return $out;
    }
}

if (!function_exists('notifySuperadminsOfTenantMigrationFailures')) {
    /**
     * Email every superadmin when this run leaves one or more tenants
     * un-migrated (a failed migration, or credentials that could not be
     * decrypted). Same pattern as core/module_requests.php's
     * notifySuperadminsOfModuleRequest() — platform mail via
     * core/platform_settings.php, since superadmins have no in-app
     * notification center of their own (they are platform operators, not
     * tenant users) and this is exactly the class of event that must never
     * go unnoticed: a tenant silently running with incomplete schema until
     * its own users hit a broken page (see migrations/tenant/README.md's
     * "Known gap" note this closes).
     *
     * Fail-silent by design, like every other notifier in this codebase: a
     * mail-sending problem must never turn a deploy's migration step into a
     * fatal error on top of the migration failure it's trying to report.
     *
     * @param array<int, array{id:int, subdomain:string, failed:?string, error:?string}> $failedTenants
     */
    function notifySuperadminsOfTenantMigrationFailures(array $failedTenants): void
    {
        if (empty($failedTenants)) return;
        try {
            require_once __DIR__ . '/platform_settings.php';
            require_once __DIR__ . '/mailer.php';

            $mailer = platformMailerOpts();
            if (!$mailer['configured']) {
                error_log('notifySuperadminsOfTenantMigrationFailures: platform SMTP not configured — cannot email, see migrations/tenant_deploy.log instead');
                return;
            }

            $rows = '';
            foreach ($failedTenants as $t) {
                $subdomain = htmlspecialchars((string)$t['subdomain'], ENT_QUOTES, 'UTF-8');
                $reason = htmlspecialchars($t['failed'] ? "migration {$t['failed']}" : 'credentials/connection', ENT_QUOTES, 'UTF-8');
                $error = htmlspecialchars(substr((string)$t['error'], 0, 500), ENT_QUOTES, 'UTF-8');
                $rows .= "<tr><td style=\"padding:6px 10px;border:1px solid #dee2e6;\">#{$t['id']} ({$subdomain})</td>"
                       . "<td style=\"padding:6px 10px;border:1px solid #dee2e6;\">{$reason}</td>"
                       . "<td style=\"padding:6px 10px;border:1px solid #dee2e6;font-family:monospace;font-size:12px;\">{$error}</td></tr>";
            }

            $count = count($failedTenants);
            $subject = $count === 1
                ? "Tenant migration failed: {$failedTenants[0]['subdomain']}"
                : "{$count} tenant migrations failed on this deploy";

            $body = "<p>The following tenant(s) did <strong>not</strong> receive this deploy's schema migration(s) "
                  . "and are now running with incomplete/stale schema — their users may hit runtime errors on any "
                  . "page or feature that depends on the missing schema, until this is fixed.</p>"
                  . "<table style=\"border-collapse:collapse;width:100%;font-size:13px;\">"
                  . "<tr><th style=\"padding:6px 10px;border:1px solid #dee2e6;text-align:left;background:#f8f9fa;\">Tenant</th>"
                  . "<th style=\"padding:6px 10px;border:1px solid #dee2e6;text-align:left;background:#f8f9fa;\">Where it stopped</th>"
                  . "<th style=\"padding:6px 10px;border:1px solid #dee2e6;text-align:left;background:#f8f9fa;\">Error</th></tr>"
                  . $rows . "</table>"
                  . "<p style=\"margin-top:16px;\">Full detail: <code>migrations/tenant_deploy.log</code> and the "
                  . "<code>tenant_migration_log</code> control table. Retry one tenant after fixing the cause: "
                  . "<code>php core/tenant_migration_runner.php --tenant=&lt;id&gt;</code></p>";

            $emails = getControlPdo()->query("SELECT email FROM superadmins")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($emails as $email) {
                sendEmail($email, $subject, $body, $mailer['opts']);
            }
        } catch (Throwable $e) {
            error_log('notifySuperadminsOfTenantMigrationFailures: ' . $e->getMessage());
        }
    }
}

// ── CLI entry point ─────────────────────────────────────────────────────────
// Guarded so `require_once`-ing this file (from a test, or from a future
// deploy.yml wiring that wants the functions directly) never triggers a run.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $logFile = __DIR__ . '/../migrations/tenant_deploy.log';
    $log = function (string $msg) use ($logFile) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
        echo $line . "\n";
        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    };

    $dryRun = in_array('--dry-run', $argv, true);
    $onlyId = null;
    foreach ($argv as $a) {
        if (preg_match('/^--tenant=(\d+)$/', $a, $m)) $onlyId = (int)$m[1];
    }

    $log('=========================================');
    $log('BMS Tenant Migration Runner' . ($dryRun ? ' [DRY RUN]' : '') . ($onlyId ? " [tenant #$onlyId]" : ''));
    $log('=========================================');

    // Sync the code feature-registry into the control DB's `features` table
    // before touching any tenant — low-privilege DML only (see
    // syncFeatureCatalogue()'s own docblock for why this is safe here and
    // scripts/setup_control_db.php deliberately is not). Skipped on
    // --dry-run, matching that flag's "report only, change nothing" contract.
    if (!$dryRun) {
        require_once __DIR__ . '/feature_registry.php';
        $catalogue = syncFeatureCatalogue();
        if ($catalogue['ran']) {
            $log("Feature catalogue synced" . ($catalogue['added'] ? " ({$catalogue['added']} new key(s))" : ' (no new keys)') . '.');
        } else {
            $log('· Feature catalogue sync skipped: ' . $catalogue['reason']);
        }
    }

    $summary = runTenantMigrations($onlyId, $dryRun);

    if (!$summary['ran']) {
        $log('· Nothing to do: ' . $summary['reason']);
        $log('=========================================');
        exit(0);
    }

    $log("Files found: {$summary['files']}   Tenants: {$summary['tenants_total']}");
    $anyFailed = false;

    foreach ($summary['tenants'] as $t) {
        $log("→ tenant #{$t['id']} ({$t['subdomain']})");
        foreach ($t['applied'] as $a) $log("    ✓ $a");
        if ($t['failed']) {
            $anyFailed = true;
            $log("    ✗ FAILED: {$t['failed']}");
            foreach (explode("\n", (string)$t['error']) as $l) $log("      $l");
            $log("    Stopped for this tenant — every other tenant is unaffected.");
        } elseif ($t['error']) {
            $anyFailed = true;
            $log("    ✗ SKIPPED: {$t['error']}");
        } elseif (!$t['applied']) {
            $log('    (already up to date)');
        }
    }

    $log('=========================================');
    $log($anyFailed
        ? 'Result: COMPLETED WITH FAILURES — see above. Every unaffected tenant still received its migrations.'
        : 'Result: SUCCESS — every tenant is up to date.');
    $log('=========================================');

    if ($anyFailed) {
        $failedTenants = array_values(array_filter($summary['tenants'], fn($t) => $t['failed'] !== null || $t['error'] !== null));
        notifySuperadminsOfTenantMigrationFailures($failedTenants);
        $log('Alert emailed to every superadmin (if platform SMTP is configured — see core/platform_settings.php).');
        $log('=========================================');
    }

    exit($anyFailed ? 1 : 0);
}
