<?php
/**
 * migrations/tenant/2026_09_29_mm_shifts_permission.php
 *
 * Adds the missing mm_shifts permission row to the Mobile Money module.
 * mm_shifts was omitted from 2026_09_26_mm_permissions — this backfills it.
 * Idempotent via INSERT IGNORE.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mm_shifts_permission...\n";

try {
    $pdo->prepare("
        INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
        VALUES (?, ?, ?, ?)
    ")->execute([
        'mm_shifts',
        'MM Shifts',
        'Open and close teller shifts; view shift history',
        'Mobile Money',
    ]);

    echo "  · permission 'mm_shifts' ensured.\n";
    echo "Migration complete: mm_shifts_permission.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
