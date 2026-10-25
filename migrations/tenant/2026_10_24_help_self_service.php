<?php
/**
 * migrations/tenant/2026_10_24_help_self_service.php
 *
 * Help & Support is open to every signed-in user (canView('help') — core/permissions.php,
 * support_center_plan.md D8). Hide its row from Users › Roles & Permissions so no admin
 * sees a switch that would do nothing. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: help_self_service...\n";
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
