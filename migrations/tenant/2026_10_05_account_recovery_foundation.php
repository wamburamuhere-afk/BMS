<?php
/**
 * migrations/tenant/2026_10_05_account_recovery_foundation.php
 *
 * Account recovery, Phase 0 (superadmin_recovery_plan): the reset-token tables,
 * the two new `users` columns, the lookup indexes, and the backfill that
 * recovers each account's real email/phone out of `username` — where
 * provisionTenant() had been leaving it while writing `users.email = ''`.
 *
 * Calls accountRecoveryEnsureSchema(), which only adds what is missing
 * (idempotent, no DDL inside a transaction). Notes it returns are printed so
 * the deploy log shows exactly what each tenant needed — including the two
 * conditions it deliberately will NOT fix on its own (admins with no email,
 * duplicate usernames).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: account_recovery_foundation...\n";

try {
    require_once __DIR__ . '/../../core/account_recovery_schema.php';
    $notes = accountRecoveryEnsureSchema($pdo);

    foreach ($notes as $n) {
        echo (strncmp($n, 'WARNING', 7) === 0 ? "  ! " : "  + ") . $n . "\n";
    }
    if (!$notes) {
        echo "  · already up to date.\n";
    }

    echo "Migration complete: account_recovery_foundation.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
