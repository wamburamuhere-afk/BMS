<?php
/**
 * 2026_05_30_account_cashflow_classification.php
 * ----------------------------------------------
 * Cash-flow / current classification at the ACCOUNT level.
 *
 * The five generic account_types (asset/liability/equity/income/expense) are
 * too coarse to drive a Statement of Cash Flows: Cash, Receivables, Inventory
 * and Fixed Assets are all "asset" yet must route to different IAS 7 sections
 * (cash / operating / operating / investing). The cash-flow classification is
 * NOT a matter of opinion — it is the standard, well-defined mapping used by
 * every accounting system. We encode it per account here, once, so the report
 * reads a clean flag at runtime (no name heuristics in the report).
 *
 * Adds two nullable columns to `accounts`:
 *   cash_flow_category  ENUM('operating','investing','financing','cash','none')
 *   is_current          TINYINT(1)   -- 1 = current, 0 = non-current (IAS 1)
 *
 * Seeding rules (canonical IAS 7 / IAS 1):
 *   ASSET  + name looks like cash/bank/petty/mobile-money  -> cash,      current
 *   ASSET  + name looks like fixed/PPE/vehicle/land/etc.    -> investing, non-current
 *   ASSET  (everything else: AR, inventory, prepaid)        -> operating, current
 *   LIAB.  + name looks like loan/borrowing/long-term       -> financing, non-current
 *   LIAB.  (everything else: AP, accruals, tax payable)     -> operating, current
 *   EQUITY (capital, retained earnings, drawings)           -> financing
 *   REVENUE / EXPENSE / COGS                                -> none
 *
 * Idempotent: column adds guarded by SHOW COLUMNS; every UPDATE only touches
 * rows still NULL, so an admin's later manual correction is preserved on re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: account-level cash-flow / current classification...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  ! accounts table missing — cannot proceed.\n";
        exit(1);
    }

    // ── 1. Add columns (idempotent) ───────────────────────────────────────
    $hasCf = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'cash_flow_category'")->fetch();
    if ($hasCf) {
        echo "  · accounts.cash_flow_category already exists, skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE accounts
              ADD COLUMN cash_flow_category
                  ENUM('operating','investing','financing','cash','none') NULL
                  COMMENT 'IAS 7 cash-flow section for this account'
                  AFTER account_type_id
        ");
        echo "  + added accounts.cash_flow_category.\n";
    }

    $hasCur = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'is_current'")->fetch();
    if ($hasCur) {
        echo "  · accounts.is_current already exists, skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE accounts
              ADD COLUMN is_current TINYINT(1) NULL
                  COMMENT 'IAS 1: 1 = current, 0 = non-current'
                  AFTER cash_flow_category
        ");
        echo "  + added accounts.is_current.\n";
    }

    // ── 2. Seed per canonical rules (only rows still NULL) ─────────────────
    $rules = [
        // [label, SET clause, category filter, name REGEXP (or null)]
        ['cash accounts',
         "a.cash_flow_category='cash', a.is_current=1",
         "asset",
         'cash|bank|petty|m-?pesa|tigo|airtel|halopesa|mobile.?money|wallet|float|crdb|nmb|nbc|n\\.?m\\.?b|crdb|equity bank|stanbic|absa|exim|azania|dtb'],

        ['investing (non-current assets)',
         "a.cash_flow_category='investing', a.is_current=0",
         "asset",
         'fixed|property|plant|equipment|machinery|vehicle|motor|lorry|truck|land|building|furniture|fitting|computer hardware|intangible|investment|goodwill|depreciation'],

        ['operating (current assets)',
         "a.cash_flow_category='operating', a.is_current=1",
         "asset",
         null],

        ['financing (non-current liabilities)',
         "a.cash_flow_category='financing', a.is_current=0",
         "liability",
         'loan|borrow|mortgage|debenture|bond|long.?term|note payable|lease liability'],

        ['operating (current liabilities)',
         "a.cash_flow_category='operating', a.is_current=1",
         "liability",
         null],

        ['financing (equity)',
         "a.cash_flow_category='financing'",
         "equity",
         null],
    ];

    foreach ($rules as [$label, $set, $cat, $regexp]) {
        $sql = "UPDATE accounts a
                  JOIN account_types at ON a.account_type_id = at.type_id
                   SET $set
                 WHERE at.category = ?
                   AND a.cash_flow_category IS NULL";
        $params = [$cat];
        if ($regexp !== null) {
            $sql .= " AND LOWER(a.account_name) REGEXP ?";
            $params[] = $regexp;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo "  + {$label}: {$stmt->rowCount()} account(s) classified.\n";
    }

    // P&L accounts -> none
    $stmt = $pdo->prepare("
        UPDATE accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
           SET a.cash_flow_category = 'none'
         WHERE at.category IN ('revenue','expense','cogs')
           AND a.cash_flow_category IS NULL
    ");
    $stmt->execute();
    echo "  + P&L (none): {$stmt->rowCount()} account(s) classified.\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
