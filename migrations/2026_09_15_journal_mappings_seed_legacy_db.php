<?php
/**
 * migrations/2026_09_15_journal_mappings_seed_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_15_journal_mappings_seed.php onto the
 * LEGACY / non-tenant database. See 2026_09_08_pos_network_printer_legacy_db.php
 * for why this pairing exists.
 *
 * Degrades instead of failing: this file, unlike a tenant migration, runs as
 * part of migrations/runner.php — a failure here (exit 1) is `script_stop:
 * true` on the deploy script, halting the release for EVERY host. The legacy
 * `bms` database already got these rows from the original
 * migrations/2026_05_28_journal_mappings_schema.php, so this is expected to
 * be a no-op there; if `journal_mappings` doesn't exist at all on some other
 * legacy database, that's a separate, pre-existing gap outside this
 * migration's job to fix — log it and exit 0 rather than blocking every
 * other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: journal_mappings seed defaults on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch();
    if (!$table) {
        echo "  · journal_mappings does not exist on this database — nothing to seed. Skipping (not this migration's job to create it).\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $seed = [
        ['invoice_approved',  'Sales invoice approved — Dr Accounts Receivable / Cr Revenue'],
        ['payment_received',  'Customer payment received — Dr Cash / Cr Accounts Receivable'],
        ['expense_paid',      'Expense marked paid — Dr Expense / Cr Cash'],
        ['payroll_paid',      'Payroll approved/paid — Dr Salaries Expense / Cr Cash'],
        ['grn_approved',      'Goods Received Note approved — Dr Inventory / Cr Accounts Payable'],
        ['supplier_payment',  'Supplier payment recorded — Dr Accounts Payable / Cr Cash'],
        ['asset_purchased',   'Fixed asset purchased — Dr PP&E / Cr Cash (or AP)'],
        ['depreciation_run',  'Depreciation run — Dr Depreciation Expense / Cr Accumulated Depreciation'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO `journal_mappings` (`event_type`, `description`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)
    ");
    $inserted = 0; $updated = 0;
    foreach ($seed as [$event, $desc]) {
        $stmt->execute([$event, $desc]);
        if ($stmt->rowCount() === 1)      $inserted++;
        elseif ($stmt->rowCount() === 2)  $updated++;
    }
    echo "  + seed: {$inserted} inserted, {$updated} description(s) refreshed, " .
         (count($seed) - $inserted - $updated) . " unchanged.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
