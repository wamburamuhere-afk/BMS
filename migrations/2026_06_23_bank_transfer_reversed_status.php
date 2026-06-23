<?php
/**
 * 2026_06_23_bank_transfer_reversed_status.php
 * --------------------------------------------
 * Bank transfers are being simplified: an internal transfer between our own
 * cash/bank accounts is a low-risk move, so it now AUTO-POSTS on creation (no
 * pending → reviewed → approved workflow) and can be undone with a single
 * "Reverse" action. This adds the honest terminal state `reversed` to the
 * status enum (replacing the old `rejected` void label).
 *
 * Additive + idempotent: it only widens the enum, and existing rows keep their
 * current status. No data is changed.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add 'reversed' to bank_transfers.status...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM bank_transfers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  bank_transfers.status column not found — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    if (strpos($col['Type'], "'reversed'") !== false) {
        echo "  'reversed' already present — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    // Re-declare the enum with 'reversed' appended; default stays 'pending' so any
    // legacy code path that still inserts a default is unaffected. New transfers are
    // inserted explicitly as 'posted' by the create endpoint.
    $pdo->exec("ALTER TABLE bank_transfers MODIFY status
        ENUM('pending','reviewed','approved','posted','rejected','reversed') NOT NULL DEFAULT 'pending'");
    echo "  + 'reversed' added to bank_transfers.status.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
