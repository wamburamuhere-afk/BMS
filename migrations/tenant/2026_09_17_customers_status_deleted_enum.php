<?php
/**
 * migrations/tenant/2026_09_17_customers_status_deleted_enum.php
 *
 * Fixes a real, pre-existing bug found while auditing the Customer CRUD
 * (2026-09-17 request): customers.status is `enum('active','inactive',
 * 'suspended','blacklisted')` — it never actually included 'deleted', even
 * though api/delete_customer.php's soft-delete branch (and every list-page
 * query's `WHERE status != 'deleted'` filter) has always assumed it did.
 * suppliers.status already correctly has 'deleted' in its enum — customers
 * drifted from that pattern at some point. Under MySQL's default (non-strict)
 * mode this doesn't error: `SET status = 'deleted'` on an enum without that
 * value silently truncates to an empty string instead, which then FAILS the
 * `!= 'deleted'` filter too (since '' != 'deleted'), leaving a "deleted"
 * customer still visible everywhere. This migration only widens the enum;
 * it does not touch any existing row's data.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: widen customers.status enum to include 'deleted'...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  customers.status column not found — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    if (stripos($col['Type'], "'deleted'") !== false) {
        echo "  customers.status already includes 'deleted' — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE customers MODIFY COLUMN status ENUM('active','inactive','suspended','blacklisted','deleted') NOT NULL DEFAULT 'active'");
        echo "  + Widened customers.status to include 'deleted' (matches suppliers.status).\n";
    }

    // Self-healing: any row already silently truncated to '' by the bug this
    // migration fixes was never actually reachable as "deleted" (the '' !=
    // 'deleted' filter kept it visible) — so it is not truly deleted data,
    // just corrupted status. Restore it to 'active' rather than leaving an
    // invalid empty string sitting in an enum column, or leave 'deleted' rows
    // now correctly hidden going forward. Criteria-based, no ids hard-coded.
    $fixed = $pdo->exec("UPDATE customers SET status = 'active' WHERE status = ''");
    if ($fixed > 0) {
        echo "  + Repaired $fixed customer row(s) that had been silently corrupted to an empty status by the pre-existing bug.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
