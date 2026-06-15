<?php
/**
 * 2026_06_15_finance_costs_bank_charges_setup.php
 * ------------------------------------------------
 * FINANCE COSTS — make bank charges the system's finance cost, and remove the
 * loan/interest concept (BMS has no loans).
 *
 * 1. SEED the canonical Bank-Charges account setting (`default_bank_charges_account_id`
 *    → the 6-1900 Bank Charges finance-cost account) so bank fees resolve to one place
 *    and land in the Income Statement's FINANCE COSTS section. Admin-configurable later.
 *
 * 2. RETIRE the loan-interest finance-cost account(s): any `finance_cost` account whose
 *    name implies interest-on-borrowings, that is NOT a system account and has ZERO
 *    posted activity, is set inactive. Criteria-based + activity-guarded, so it only
 *    ever touches an unused interest account (never one carrying real entries) and is a
 *    no-op on a clean/used server. This leaves FINANCE COSTS = Bank Charges only.
 *
 * SAFE TO RE-RUN — idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: finance costs = bank charges (retire loan/interest)...\n";

try {
    // ── 1. Seed the canonical Bank Charges account setting ────────────────────
    $bankChargesId = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='6-1900' LIMIT 1")->fetchColumn() ?: 0);
    if ($bankChargesId > 0) {
        $exists = (int)($pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_key='default_bank_charges_account_id'")->fetchColumn());
        if ($exists === 0) {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, description, updated_at)
                           VALUES ('default_bank_charges_account_id', ?, 'accounting', 0, 'Finance cost account bank charges post to', NOW())")
                ->execute([$bankChargesId]);
            echo "  + seeded default_bank_charges_account_id = {$bankChargesId} (6-1900 Bank Charges).\n";
        } else {
            echo "  ~ default_bank_charges_account_id already set — left as-is.\n";
        }

        // A settings-referenced control account must be system-flagged (protected
        // from deletion) — the chart invariant the COA tests enforce.
        $refId = (int)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_bank_charges_account_id' LIMIT 1")->fetchColumn() ?: 0);
        if ($refId > 0) {
            $u = $pdo->prepare("UPDATE accounts SET is_system = 1 WHERE account_id = ? AND COALESCE(is_system,0) = 0");
            $u->execute([$refId]);
            if ($u->rowCount() > 0) echo "  + flagged account #{$refId} is_system=1 (settings-referenced control account).\n";
        }
    } else {
        echo "  ! 6-1900 Bank Charges account not found — skipped setting seed.\n";
    }

    // ── 2. Retire unused loan-interest finance-cost account(s) ────────────────
    // Criteria: finance_cost + name implies interest + not a system account + ZERO
    // posted activity. The activity guard makes this safe on any dataset.
    $stmt = $pdo->prepare("
        UPDATE accounts a
          JOIN account_types at ON at.type_id = a.account_type_id
           SET a.status = 'inactive'
         WHERE at.category   = 'finance_cost'
           AND a.account_name LIKE '%interest%'
           AND a.status      = 'active'
           AND COALESCE(a.is_system, 0) = 0
           AND NOT EXISTS (
                 SELECT 1 FROM journal_entry_items jei
                   JOIN journal_entries je ON je.entry_id = jei.entry_id
                  WHERE jei.account_id = a.account_id AND je.status = 'posted'
           )
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) {
        echo "  + retired {$n} unused loan-interest finance-cost account(s) — FINANCE COSTS is now bank charges only.\n";
    } else {
        echo "  ~ no unused loan-interest account to retire (already done, none, or carries activity).\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
