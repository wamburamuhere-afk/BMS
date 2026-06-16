<?php
/**
 * 2026_06_14_asset_gl_wiring.php
 * ------------------------------
 * money.md OUT-12 / OUT-13 enablers. Two idempotent, additive, deploy-safe steps:
 *
 *   1. depreciation_entries.journal_entry_id (INT NULL)
 *      The anchor that links a posted depreciation period to its GL journal entry,
 *      so the run posts each charge to the ledger exactly once (idempotent +
 *      backfill-capable) independent of the existing `posted` flag, and the link
 *      is auditable.
 *
 *   2. A generic "Accumulated Depreciation" control account (code 1-3900)
 *      The chart only had per-category accum-dep accounts (Office Equipment,
 *      Computer); Buildings/Vehicles/Machinery/Land had none, so depreciation had
 *      nowhere to credit. This adds one canonical contra-asset, cloned from the
 *      Fixed Assets (1-3000) row so every NOT-NULL/classification column matches
 *      the live schema. Only created when absent.
 *
 * Both steps are safe to run on a live database: additive schema + one standard
 * account. No data is mutated or deleted.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset GL wiring...\n";

try {
    // ── 1. depreciation_entries.journal_entry_id ────────────────────────────
    if ($pdo->query("SHOW TABLES LIKE 'depreciation_entries'")->fetch()) {
        if (!$pdo->query("SHOW COLUMNS FROM depreciation_entries LIKE 'journal_entry_id'")->fetch()) {
            $pdo->exec("ALTER TABLE depreciation_entries
                          ADD COLUMN `journal_entry_id` INT NULL DEFAULT NULL AFTER `posted`");
            echo "  + depreciation_entries.journal_entry_id added.\n";
        } else {
            echo "  ~ depreciation_entries.journal_entry_id already exists.\n";
        }
    } else {
        echo "  ~ depreciation_entries table absent — skipping column.\n";
    }

    // ── 2. Ensure a generic Accumulated Depreciation account (1-3900) ────────
    $exists = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='1-3900' LIMIT 1")->fetchColumn() ?: 0);
    if ($exists) {
        echo "  ~ Accumulated Depreciation (1-3900) already exists (#$exists).\n";
    } else {
        // Clone the Fixed Assets (1-3000) row so every column (parent, sub_type,
        // classification, NOT-NULLs) matches the live schema; override identity.
        $tmpl = $pdo->query("SELECT * FROM accounts WHERE account_code='1-3000' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$tmpl) {
            echo "  ! Fixed Assets (1-3000) not found — cannot clone; Accum Dep NOT created.\n";
        } else {
            unset($tmpl['account_id']);                 // new PK
            $tmpl['account_code'] = '1-3900';
            $tmpl['account_name'] = 'Accumulated Depreciation';
            $tmpl['status']       = 'active';
            if (array_key_exists('description', $tmpl)) $tmpl['description'] = 'Contra-asset: total depreciation charged to date';
            if (array_key_exists('current_balance', $tmpl)) $tmpl['current_balance'] = 0;
            if (array_key_exists('opening_balance', $tmpl)) $tmpl['opening_balance'] = 0;
            if (array_key_exists('created_at', $tmpl)) $tmpl['created_at'] = date('Y-m-d H:i:s');
            if (array_key_exists('updated_at', $tmpl)) $tmpl['updated_at'] = date('Y-m-d H:i:s');

            $cols = array_keys($tmpl);
            $ph   = implode(',', array_fill(0, count($cols), '?'));
            $sql  = "INSERT INTO accounts (`" . implode('`,`', $cols) . "`) VALUES ($ph)";
            $pdo->prepare($sql)->execute(array_values($tmpl));
            echo "  + Accumulated Depreciation (1-3900) created (#" . (int)$pdo->lastInsertId() . ").\n";
        }
    }

    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
