<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/control_db.php';
global $pdo;

/**
 * Multi-tenancy Phase 4 — brute-force protection for the superadmin login.
 *
 * Adds failed_attempts / locked_until / last_login to bms_control.superadmins,
 * matching the lockout contract in .claude/security.md §20.
 *
 * This login is the single most valuable credential on the platform: it governs
 * every tenant's lifecycle. Leaving it unthrottled while every tenant user login
 * is throttled would be the wrong way round.
 *
 * Idempotent — each column is checked before being added.
 */

$controlDb = controlDbName();
if (!preg_match('/^[a-z0-9_]{1,64}$/i', $controlDb)) {
    echo "Migration failed: invalid control database name '{$controlDb}'.\n";
    exit(1);
}

echo "Starting migration: superadmin login hardening ({$controlDb}.superadmins)...\n";

try {
    $exists = (bool)$pdo->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = " . $pdo->quote($controlDb) . " AND table_name = 'superadmins'"
    )->fetchColumn();

    if (!$exists) {
        echo "Migration failed: {$controlDb}.superadmins does not exist.\n";
        echo "  Run migrations/2026_08_31_control_db_foundation.php first.\n";
        exit(1);
    }

    $cols = $pdo->query(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = " . $pdo->quote($controlDb) . " AND table_name = 'superadmins'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $additions = [
        'failed_attempts' => "ADD COLUMN `failed_attempts` INT NOT NULL DEFAULT 0 AFTER `password_hash`",
        'locked_until'    => "ADD COLUMN `locked_until` DATETIME NULL AFTER `failed_attempts`",
        'last_login'      => "ADD COLUMN `last_login` DATETIME NULL AFTER `locked_until`",
    ];

    foreach ($additions as $col => $clause) {
        if (in_array($col, $cols, true)) {
            echo "  · {$col} already exists — skipped.\n";
        } else {
            $pdo->exec("ALTER TABLE `{$controlDb}`.`superadmins` {$clause}");
            echo "  + {$col} added.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
