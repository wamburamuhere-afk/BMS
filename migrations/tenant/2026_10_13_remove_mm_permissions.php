<?php
/**
 * migrations/tenant/2026_10_13_remove_mm_permissions.php
 *
 * Mobile Money module removed: delete its Roles & Permissions rows, the role
 * grants pointing at them, and the mm_simple_mode setting. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: remove_mm_permissions...
";

$keys = [
    'mm_dashboard', 'mm_agents', 'mm_networks', 'mm_transactions', 'mm_float',
    'mm_commissions', 'mm_commission_rates', 'mm_reconciliation', 'mm_reports',
    'mm_compliance', 'mm_shifts', 'mm_user_agent_grants',
];
$in = implode(',', array_fill(0, count($keys), '?'));
$has = fn(string $t): bool => (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();

try {
    if ($has('permissions')) {
        if ($has('role_permissions')) {
            $st = $pdo->prepare("DELETE rp FROM role_permissions rp
                                 JOIN permissions p ON p.permission_id = rp.permission_id
                                 WHERE p.page_key IN ($in)");
            $st->execute($keys);
            echo "  - role grants removed: " . $st->rowCount() . "\n";
        }
        $st = $pdo->prepare("DELETE FROM permissions WHERE page_key IN ($in)");
        $st->execute($keys);
        echo "  - permission rows removed: " . $st->rowCount() . "\n";
    }
    if ($has('system_settings')) {
        $st = $pdo->prepare("DELETE FROM system_settings WHERE setting_key = 'mm_simple_mode'");
        $st->execute();
        echo "  - mm_simple_mode setting removed: " . $st->rowCount() . "\n";
    }
    echo "Migration complete: remove_mm_permissions.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
