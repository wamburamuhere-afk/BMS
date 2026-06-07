<?php
/**
 * 2026_06_07_clear_orphan_account_settings.php
 * --------------------------------------------
 * scope-audit: skip — config hygiene on system_settings only.
 *
 * Clears any system_settings `*_account_id` value that points to an account
 * which no longer exists (a dangling reference left behind when an account was
 * deleted). A dangling default account silently breaks the feature that reads it
 * (e.g. WHT receivable, default cash/AP), so we blank it — the admin re-points it
 * in Settings. delete_account.php now clears these on deletion, so this is a
 * one-time clean-up of pre-existing orphans. Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: clear orphan *_account_id settings...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'system_settings'")->fetch()) {
        echo "  system_settings table absent — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $orphans = $pdo->query("
        SELECT setting_key, setting_value
          FROM system_settings
         WHERE setting_key REGEXP '_account_id$'
           AND setting_value REGEXP '^[0-9]+$'
           AND CAST(setting_value AS UNSIGNED) NOT IN (SELECT account_id FROM accounts)
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orphans as $o) {
        echo "  · {$o['setting_key']} -> {$o['setting_value']} (account missing) — clearing\n";
    }

    $n = $pdo->exec("
        UPDATE system_settings SET setting_value = ''
         WHERE setting_key REGEXP '_account_id$'
           AND setting_value REGEXP '^[0-9]+$'
           AND CAST(setting_value AS UNSIGNED) NOT IN (SELECT account_id FROM accounts)
    ");

    echo "\n  Cleared {$n} orphan setting(s).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
