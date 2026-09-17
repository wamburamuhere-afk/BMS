<?php
/**
 * migrations/2026_09_17_warehouses_public_catalog_token_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_17_warehouses_public_catalog_token.php
 * onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_network_printer_legacy_db.php for why this pairing exists.
 *
 * Degrades instead of failing: this runs as part of migrations/runner.php —
 * a failure here halts the deploy for EVERY host. If `warehouses` doesn't
 * exist at all on this database, that's a separate, pre-existing gap outside
 * this migration's job to fix — log it and exit 0 rather than blocking
 * every other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add warehouses.public_catalog_token_hash on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'warehouses'")->fetch();
    if (!$table) {
        echo "  · warehouses does not exist on this database — nothing to add. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $has = $pdo->query("SHOW COLUMNS FROM warehouses LIKE 'public_catalog_token_hash'")->fetch();
    if ($has) {
        echo "  warehouses.public_catalog_token_hash already exists — skipping column add.\n";
    } else {
        $pdo->exec("ALTER TABLE warehouses ADD COLUMN public_catalog_token_hash CHAR(64) NULL AFTER notes");
        echo "  + Added warehouses.public_catalog_token_hash (CHAR(64) NULL).\n";
    }

    $hasCreated = $pdo->query("SHOW COLUMNS FROM warehouses LIKE 'public_catalog_token_created_at'")->fetch();
    if ($hasCreated) {
        echo "  warehouses.public_catalog_token_created_at already exists — skipping column add.\n";
    } else {
        $pdo->exec("ALTER TABLE warehouses ADD COLUMN public_catalog_token_created_at DATETIME NULL AFTER public_catalog_token_hash");
        echo "  + Added warehouses.public_catalog_token_created_at (DATETIME NULL).\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM warehouses WHERE Key_name = 'idx_warehouses_public_catalog_token_hash'")->fetch();
    if ($hasIdx) {
        echo "  Index idx_warehouses_public_catalog_token_hash already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE warehouses ADD UNIQUE INDEX idx_warehouses_public_catalog_token_hash (public_catalog_token_hash)");
        echo "  + Added unique index idx_warehouses_public_catalog_token_hash.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
