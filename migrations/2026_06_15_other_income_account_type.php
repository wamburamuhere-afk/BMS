<?php
/**
 * 2026_06_15_other_income_account_type.php
 * ----------------------------------------
 * Income-Statement classification (Area A). The chart had only ONE credit-normal
 * Income-Statement category — `revenue` — so every credit-normal account collapsed
 * into Revenue (real sales PLUS non-operating income and gains). IFRS / IFRS for SMEs
 * (which NBAA adopted unmodified) require Revenue = income from ORDINARY activities
 * only; non-ordinary income and GAINS (e.g. gain on asset disposal, interest income,
 * sundry income) are "Other Income", presented separately.
 *
 * This adds a credit-normal Income-Statement type `other_income` and re-points, BY ROLE
 * (never by hard-coded id/amount), the accounts that are non-operating income:
 *   - the "Other Income" chart branch (account_code prefix '8-')   → other_income
 *   - the asset-disposal GAIN account (resolved via gl_accounts)   → other_income
 *
 * Sales Returns (a contra inside Revenue) and Supplier Credit Notes (a purchase-side
 * item — handled by Area B) are deliberately NOT touched here.
 *
 * Idempotent, additive, deploy-safe: widens the enum only if needed, creates the type
 * only when absent, re-points by stable criteria; re-running is a no-op. Pure
 * classification — no journal postings move, so the BS/TB are unaffected; only the
 * Revenue vs Other Income split on the P&L changes.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/gl_accounts.php';   // disposalGainAccountId (role-based)
global $pdo;

echo "Starting migration: other_income account type...\n";

try {
    // 1. Widen the account_types.category ENUM to include 'other_income' (idempotent).
    $col = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! account_types.category not found — aborting.\n"; exit(1); }
    if (strpos(strtolower($col['Type']), "'other_income'") === false) {
        // Append the new member, preserving the existing set + null-ability.
        $newEnum = "enum('asset','liability','equity','revenue','expense','cogs','finance_cost','other_income')";
        $null = ($col['Null'] === 'YES') ? 'NULL' : 'NOT NULL';
        $pdo->exec("ALTER TABLE account_types MODIFY category $newEnum $null");
        echo "  + category enum widened with 'other_income'.\n";
    } else {
        echo "  ~ category enum already has 'other_income'.\n";
    }

    // 2. Ensure the other_income TYPE exists (clone the credit-normal revenue type).
    $tmpl = $pdo->query("SELECT * FROM account_types WHERE category='revenue' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$tmpl) { echo "  ! no 'revenue' type to clone — aborting.\n"; exit(1); }
    $otherType = (int)($pdo->query("SELECT type_id FROM account_types WHERE category='other_income' LIMIT 1")->fetchColumn() ?: 0);
    if (!$otherType) {
        $row = $tmpl;
        unset($row['type_id']);
        $row['type_name']   = 'Other Income';
        $row['category']    = 'other_income';
        $row['statement']   = 'IS';
        $row['normal_side'] = 'credit';
        if (array_key_exists('display_name', $row)) $row['display_name'] = 'Other Income';
        $cols = array_keys($row);
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare("INSERT INTO account_types (`" . implode('`,`', $cols) . "`) VALUES ($ph)")->execute(array_values($row));
        $otherType = (int)$pdo->lastInsertId();
        echo "  + created type 'Other Income' (category=other_income) #$otherType.\n";
    } else {
        echo "  ~ type 'Other Income' already exists (#$otherType).\n";
    }

    // 3a. Re-point the "Other Income" chart branch (code prefix '8-') → other_income.
    $st = $pdo->prepare("UPDATE accounts SET account_type_id = ?
                          WHERE account_code LIKE '8-%' AND (account_type_id <> ? OR account_type_id IS NULL)");
    $st->execute([$otherType, $otherType]);
    echo "  + re-pointed {$st->rowCount()} Other-Income-branch (8-xxxx) account(s) → Other Income.\n";

    // 3b. Re-point the asset-disposal GAIN account (resolved by role) → other_income.
    //     Disposal gains are gains, not revenue (IFRS). Resolver, not a hard-coded id.
    $gainId = disposalGainAccountId($pdo);
    if ($gainId) {
        $st = $pdo->prepare("UPDATE accounts SET account_type_id = ? WHERE account_id = ? AND (account_type_id <> ? OR account_type_id IS NULL)");
        $st->execute([$otherType, (int)$gainId, $otherType]);
        echo "  + re-pointed the disposal-gain account (#$gainId) → Other Income ({$st->rowCount()} changed).\n";
    } else {
        echo "  ~ no disposal-gain account resolved — skipped (config will resolve it later).\n";
    }

    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
