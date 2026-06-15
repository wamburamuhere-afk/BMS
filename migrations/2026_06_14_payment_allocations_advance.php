<?php
/**
 * 2026_06_14_payment_allocations_advance.php
 * ------------------------------------------
 * IN-7 (money.md) — customer advances / deposits. A customer can pay money BEFORE
 * an invoice exists (a deposit / advance), which must sit as a liability (Client
 * Deposits, 2-1600) until applied to an invoice. This models the WorkDo "Retainer"
 * pattern on BMS's existing receipt + allocation infrastructure.
 *
 * This migration widens `payment_allocations.target_type` to admit 'advance', so an
 * advance receipt can be marked as a deposit allocation (target_id = customer_id) and
 * its later draw-downs recorded as ordinary 'invoice' allocations against the same
 * advance payment. Additive + idempotent: it only adds the new enum member; existing
 * rows and values are untouched, and re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: payment_allocations.target_type += 'advance'...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM payment_allocations LIKE 'target_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! payment_allocations.target_type not found — aborting.\n"; exit(1); }

    $type = strtolower($col['Type']);   // e.g. enum('invoice','bill','credit_note','debit_note')
    if (strpos($type, "'advance'") !== false) {
        echo "  ~ 'advance' already present in the enum — nothing to do.\n";
        echo "Migration complete (no-op).\n";
        return;
    }

    $null    = ($col['Null'] === 'YES') ? 'NULL' : 'NOT NULL';
    $default = ($col['Default'] !== null) ? " DEFAULT " . $pdo->quote($col['Default']) : '';
    $newType = "enum('invoice','bill','credit_note','debit_note','advance')";

    $pdo->exec("ALTER TABLE payment_allocations MODIFY COLUMN target_type $newType $null$default");
    echo "  + target_type widened to: $newType\n";
    echo "Migration complete.\n";

} catch (Throwable $e) {
    echo "  ! migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
