<?php
/**
 * 2026_06_11_salary_component_deleted_enum.php
 * --------------------------------------------
 * Plan H1 — add 'deleted' to salary_components.status so the master list supports the
 * BMS soft-delete convention (§12) without erroring under strict SQL mode. Additive,
 * idempotent. Existing 'active'/'inactive' rows untouched.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: salary_components.status add 'deleted'...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM salary_components LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'deleted'") === false) {
        $pdo->exec("ALTER TABLE salary_components MODIFY status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active'");
        echo "  + 'deleted' added to salary_components.status.\n";
    } else {
        echo "  = salary_components.status already supports 'deleted' — skipped.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
