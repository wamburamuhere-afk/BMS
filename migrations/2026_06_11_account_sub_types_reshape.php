<?php
/**
 * 2026_06_11_account_sub_types_reshape.php
 * -----------------------------------------
 * Trims the account_sub_types set to the curated per-class list the business
 * wants in the "Sub Type (optional)" dropdown:
 *
 *   Asset      → Asset, Bank, Accounts Receivable, Other Asset
 *   Liability  → Liability, Credit Card, Accounts Payable, Other Liability
 *   Equity     → Equity
 *   Income     → Income, Other Income
 *   Expense    → Expense, Cost of Sales, Other Expense
 *
 * Runs AFTER 2026_06_11_account_sub_types.php (which seeds the fuller set).
 * Converges any DB — fresh or already-seeded — to the same final state:
 *   1. add the generic per-class sub-types (Asset/Liability/Income/Expense)
 *   2. re-map accounts off the sub-types being removed to the nearest survivor
 *      (Cash→Bank, Fixed Asset/Inventory→Other Asset, Tax Payable→Other Liability,
 *       Operating Revenue→Income, Operating Expense→Expense)
 *   3. delete the now-unused sub-types
 *   4. renumber display_order to match the desired dropdown sequence
 *
 * Idempotent: INSERT IGNORE; re-maps/deletes are no-ops once applied.
 * Criteria-based — resolves type_id by category, never hard-coded ids.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: reshape account sub-types to curated list...\n";

try {
    // Skip cleanly if the base table isn't there yet.
    if (!$pdo->query("SHOW TABLES LIKE 'account_sub_types'")->fetch()) {
        echo "  ~ account_sub_types table absent — nothing to reshape.\n\nMigration complete.\n";
        exit(0);
    }

    // type_id by category (criteria-based)
    $typeIdByCat = [];
    foreach ($pdo->query("SELECT type_id, category FROM account_types") as $r) {
        if (!empty($r['category']) && !isset($typeIdByCat[$r['category']])) {
            $typeIdByCat[$r['category']] = (int)$r['type_id'];
        }
    }

    // ── 1. Add the generic per-class sub-types (idempotent) ────────────────
    //    category, name, code, cash_flow, is_bank, liquidity, display_order
    $add = [
        ['asset',     'Asset',     'asset',     'operating', 0, 'current', 10],
        ['liability', 'Liability', 'liability', 'operating', 0, 'current', 10],
        ['revenue',   'Income',    'income',    'operating', 0, null,      10],
        ['expense',   'Expense',   'expense',   'operating', 0, null,      10],
    ];
    $ins = $pdo->prepare("
        INSERT IGNORE INTO account_sub_types
            (type_id, name, code, cash_flow_category, is_bank, liquidity, display_order, is_system, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'active')
    ");
    $added = 0;
    foreach ($add as [$cat, $name, $code, $cf, $bank, $liq, $ord]) {
        if (!isset($typeIdByCat[$cat])) continue;
        $ins->execute([$typeIdByCat[$cat], $name, $code, $cf, $bank, $liq, $ord]);
        $added += $ins->rowCount();
    }
    echo "  + Added $added generic sub-type(s).\n";

    // Helper: resolve a sub_type_id by code (codes are unique per type, and the
    // codes used here are globally unique).
    $idByCode = [];
    foreach ($pdo->query("SELECT sub_type_id, code FROM account_sub_types") as $r) {
        $idByCode[$r['code']] = (int)$r['sub_type_id'];
    }

    // ── 2. Re-map accounts off removed sub-types to the nearest survivor ────
    $remap = [
        'cash'              => 'bank',
        'fixed_asset'       => 'other_asset',
        'inventory'         => 'other_asset',
        'tax_payable'       => 'other_liability',
        'operating_revenue' => 'income',
        'operating_expense' => 'expense',
        'current_asset'     => 'asset',
        'current_liability' => 'liability',
        'long_term_liability' => 'other_liability',
        'owners_capital'    => 'equity',
        'retained_earnings' => 'equity',
        'finance_cost'      => 'other_expense',
    ];
    $upd = $pdo->prepare("UPDATE accounts SET sub_type_id = ? WHERE sub_type_id = ?");
    $moved = 0;
    foreach ($remap as $from => $to) {
        if (!isset($idByCode[$from]) || !isset($idByCode[$to])) continue;
        $upd->execute([$idByCode[$to], $idByCode[$from]]);
        $moved += $upd->rowCount();
    }
    echo "  + Re-mapped $moved account(s) to surviving sub-types.\n";

    // ── 3. Delete the sub-types not in the curated keep-list ───────────────
    $keep = [
        'asset', 'bank', 'accounts_receivable', 'other_asset',
        'liability', 'credit_card', 'accounts_payable', 'other_liability',
        'equity',
        'income', 'other_income',
        'expense', 'cost_of_sales', 'other_expense',
    ];
    // Defensive: clear sub_type_id on any account still pointing at a doomed row.
    $place = implode(',', array_fill(0, count($keep), '?'));
    $pdo->prepare("
        UPDATE accounts SET sub_type_id = NULL
        WHERE sub_type_id IS NOT NULL
          AND sub_type_id NOT IN (SELECT sub_type_id FROM account_sub_types WHERE code IN ($place))
    ")->execute($keep);

    $del = $pdo->prepare("DELETE FROM account_sub_types WHERE code NOT IN ($place)");
    $del->execute($keep);
    echo "  + Removed " . $del->rowCount() . " sub-type(s) outside the curated list.\n";

    // ── 4. Renumber display_order to the desired dropdown sequence ─────────
    $order = [
        'asset' => 10, 'bank' => 20, 'accounts_receivable' => 30, 'other_asset' => 40,
        'liability' => 10, 'credit_card' => 20, 'accounts_payable' => 30, 'other_liability' => 40,
        'equity' => 10,
        'income' => 10, 'other_income' => 20,
        'expense' => 10, 'cost_of_sales' => 20, 'other_expense' => 30,
    ];
    $ord = $pdo->prepare("UPDATE account_sub_types SET display_order = ? WHERE code = ?");
    foreach ($order as $code => $o) {
        $ord->execute([$o, $code]);
    }
    echo "  + Display order normalised.\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
