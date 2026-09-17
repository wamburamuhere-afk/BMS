<?php
/**
 * migrations/tenant/2026_09_17_warehouses_public_catalog_token.php
 *
 * "Shareable shop catalog link" (Simple POS only) — a shop owner can generate
 * a public, unauthenticated, read-only link scoped to ONE warehouse, so a
 * customer can browse what's in stock and its price without any login.
 *
 * Same security pattern already proven by sign_document.php's external
 * signing links: the raw token is shown to the admin exactly once (returned
 * in the generate API's JSON response, never persisted anywhere) and only
 * its SHA-256 hash is stored — a database leak never exposes a usable link.
 * There is deliberately no way to "view the link again" later; losing it
 * means generating a new one (same UX as a GitHub/Stripe API key), which is
 * also how regeneration invalidates the old link (this table only ever
 * holds one hash per warehouse).
 *
 * Purely additive, nullable columns. NULL token_hash = no link generated /
 * link revoked — every existing warehouse is unaffected until an admin
 * deliberately generates one.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: add warehouses.public_catalog_token_hash...\n";

try {
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
        // UNIQUE — two warehouses (even across tenants, since this index is
        // per-tenant-database anyway) must never collide; also makes the
        // public page's lookup a fast, indexed equality match.
        $pdo->exec("ALTER TABLE warehouses ADD UNIQUE INDEX idx_warehouses_public_catalog_token_hash (public_catalog_token_hash)");
        echo "  + Added unique index idx_warehouses_public_catalog_token_hash.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
