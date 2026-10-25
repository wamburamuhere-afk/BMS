<?php
/**
 * migrations/2026_10_24_help_self_service_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_24_help_self_service.php — the root
 * install (BJP's own books on the legacy DB) only runs migrations/*.php.
 *
 * Help & Support is open to every signed-in user (canView('help')), so its row is
 * hidden from Users › Roles & Permissions. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting legacy migration: help_self_service...\n";
try {
    $hasCol = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'permissions' AND column_name = 'is_hidden'")->fetchColumn();
    if ($hasCol) {
        $n = $pdo->exec("UPDATE permissions SET is_hidden = 1 WHERE page_key = 'help' AND COALESCE(is_hidden, 0) = 0");
        echo "  help permission hidden from the roles matrix ({$n} row(s) changed).\n";
    } else {
        echo "  permissions.is_hidden not present — nothing to do.\n";
    }
    echo "Migration complete: help_self_service.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
