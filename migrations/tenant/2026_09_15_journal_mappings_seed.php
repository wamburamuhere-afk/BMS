<?php
/**
 * migrations/tenant/2026_09_15_journal_mappings_seed.php
 *
 * migrations/2026_05_28_journal_mappings_schema.php created `journal_mappings`
 * AND seeded its 8 canonical event_type rows — but only ever ran against the
 * legacy `bms` database (no tenant-side counterpart exists). Every tenant
 * provisioned since multi-tenancy launched (2026-08-31) got the table via
 * schema/tenant_schema_template.sql (DDL-only, no seed rows) but NONE of the
 * seed data, so `journal_mappings` has been completely empty on every tenant.
 *
 * core/auto_post_hook.php::autoPostEvent() degrades gracefully when the TABLE
 * is missing, but not when a row for the event_type simply isn't found — that
 * throws a hard LedgerException ("unknown event_type '...' — add it to the
 * journal_mappings seed migration"), inside the SAME transaction as the real
 * ledger posting (e.g. api/account/update_expense_status.php's postOutflow()
 * call), so the whole action rolls back. Confirmed live: marking an expense
 * paid failed outright with exactly this error for event_type='expense_paid'.
 *
 * This seed is harmless to add: every row ships with is_active=0 (the
 * table's own column default, kill-switch off) exactly as the original
 * migration always seeded it, so this only stops the crash — it does NOT
 * start a second posting path. The real double-entry for every one of these
 * events already happens through its own dedicated function (postOutflow(),
 * postExpenseAccrual(), postPayrollPayment(), etc. — core/payment_source.php
 * and friends), which posts directly to journal_entries/journal_entry_items,
 * the one canonical ledger (.claude/reporting-source.md). This journal_mappings
 * hook is a separate, currently-inert layer kept wired "for the contract";
 * an admin must deliberately configure real Dr/Cr accounts and flip is_active
 * before it would ever post anything itself.
 *
 * Idempotent: ON DUPLICATE KEY UPDATE only refreshes `description` — an
 * admin's own debit_account_id/credit_account_id/is_active/notes on an
 * existing row are never touched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: journal_mappings seed defaults...\n";

try {
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
