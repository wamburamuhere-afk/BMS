<?php
/**
 * 2026_06_15_cogs_finance_account_types.php
 * -----------------------------------------
 * Income-Statement classification fix (Phase 1). The chart only used 5 coarse
 * account types (asset/liability/equity/revenue/expense), so the COGS and FINANCE
 * COSTS sections of the P&L could never populate — every cost-of-sales account
 * (5-xxxx) and finance account (Interest, Bank Charges) was category 'expense' and
 * landed in Operating Expenses.
 *
 * This adds two Income-Statement account types and re-points the relevant accounts:
 *   - "Cost of Goods Sold"  (category=cogs)         ← 5-xxxx cost-of-sales accounts
 *   - "Finance Costs"       (category=finance_cost) ← Interest Expense (6-1800),
 *                                                      Bank Charges (6-1900)
 *
 * After this, any COGS already in the GL (POS / sub-contractor) moves into the COGS
 * section and Gross Profit becomes real; finance accounts populate FINANCE COSTS.
 * Idempotent, additive, deploy-safe: it creates types only when absent and re-points
 * by stable account_code criteria (no hard-coded local ids); re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: COGS + Finance Costs account types...\n";

try {
    // Clone structural columns from the existing 'expense' type (both new types are
    // debit-normal Income-Statement types, just like expense).
    $tmpl = $pdo->query("SELECT * FROM account_types WHERE category='expense' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$tmpl) { echo "  ! no 'expense' type to clone — aborting.\n"; exit(1); }

    /** Ensure an account type with the given category exists; return its type_id. */
    $ensureType = function (string $category, string $name, string $display) use ($pdo, $tmpl): int {
        $id = (int)($pdo->query("SELECT type_id FROM account_types WHERE category=" . $pdo->quote($category) . " LIMIT 1")->fetchColumn() ?: 0);
        if ($id) { echo "  ~ type '$name' (category=$category) already exists (#$id).\n"; return $id; }
        $row = $tmpl;
        unset($row['type_id']);
        $row['type_name']    = $name;
        $row['category']     = $category;
        $row['statement']    = 'IS';
        $row['normal_side']  = 'debit';
        if (array_key_exists('display_name', $row)) $row['display_name'] = $display;
        $cols = array_keys($row);
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare("INSERT INTO account_types (`" . implode('`,`', $cols) . "`) VALUES ($ph)")->execute(array_values($row));
        $newId = (int)$pdo->lastInsertId();
        echo "  + created type '$name' (category=$category) #$newId.\n";
        return $newId;
    };

    $cogsType    = $ensureType('cogs',         'Cost of Goods Sold', 'Cost of Goods Sold');
    $financeType = $ensureType('finance_cost', 'Finance Costs',      'Finance Costs');

    // Re-point cost-of-sales accounts (the 5-xxxx block) → COGS type.
    $st = $pdo->prepare("UPDATE accounts SET account_type_id = ?
                          WHERE account_code LIKE '5-%' AND (account_type_id <> ? OR account_type_id IS NULL)");
    $st->execute([$cogsType, $cogsType]);
    echo "  + re-pointed {$st->rowCount()} cost-of-sales (5-xxxx) account(s) → Cost of Goods Sold.\n";

    // Re-point finance accounts (Interest Expense, Bank Charges) → Finance Costs.
    $st = $pdo->prepare("UPDATE accounts SET account_type_id = ?
                          WHERE account_code IN ('6-1800','6-1900')
                            AND (account_type_id <> ? OR account_type_id IS NULL)");
    $st->execute([$financeType, $financeType]);
    echo "  + re-pointed {$st->rowCount()} finance account(s) (Interest/Bank Charges) → Finance Costs.\n";

    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
