<?php
/**
 * 2026_07_17_user_scope_overrides_unique_key.php
 * ------------------------------------------------
 * Phase 6 (Warehouse Access Control, pos_upgrade_plan.md) starts writing real
 * rows into user_scope_overrides for the first time — until now the table
 * existed but was empty. Adds a uniqueness guard so the assignment UI's
 * full-replace save (DELETE then re-INSERT the desired set) can never leave
 * duplicate (user_id, resource_type, resource_id) rows behind if the save
 * endpoint is ever called twice for the same request.
 *
 * Purely additive — no existing rows to deduplicate (table is empty today).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: unique key on user_scope_overrides...\n";

try {
    $existing = $pdo->query("SHOW INDEX FROM user_scope_overrides WHERE Key_name = 'uq_user_resource'")->fetch();
    if ($existing) {
        echo "  ~ uq_user_resource already exists — skipped.\n";
    } else {
        $pdo->exec("ALTER TABLE user_scope_overrides ADD UNIQUE KEY uq_user_resource (user_id, resource_type, resource_id)");
        echo "  + Added UNIQUE KEY uq_user_resource (user_id, resource_type, resource_id).\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
