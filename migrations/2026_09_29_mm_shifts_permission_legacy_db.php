<?php
/**
 * migrations/2026_09_29_mm_shifts_permission_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_09_29_mm_shifts_permission.php.
 *
 * Adds the missing mm_shifts permission row to the Mobile Money module.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'permissions'")->fetch()) {
    echo "Legacy DB has no permissions table — skipping mm_shifts_permission migration.\n";
    exit(0);
}

echo "Starting legacy migration: mm_shifts_permission...\n";

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
    echo "Migration complete: mm_shifts_permission (legacy).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
