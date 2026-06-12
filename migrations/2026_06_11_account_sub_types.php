<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: account sub-types tier (WorkDo-style)...\n";

try {
    // ──────────────────────────────────────────────────────────────────────
    // 1. Lookup table: account_sub_types
    //    A semantic sub-classification under each top class (account_types).
    //    e.g. Asset → Bank / Cash / Accounts Receivable / Fixed Asset …
    //    No hard FK constraint (engine/charset on live may differ) — type_id
    //    is a plain indexed column, integrity enforced in the app layer.
    // ──────────────────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `account_sub_types` (
            `sub_type_id`        INT AUTO_INCREMENT PRIMARY KEY,
            `type_id`            INT NOT NULL,
            `name`               VARCHAR(80)  NOT NULL,
            `code`               VARCHAR(50)  NOT NULL,
            `cash_flow_category` ENUM('operating','investing','financing','cash','none') NOT NULL DEFAULT 'operating',
            `is_bank`            TINYINT(1)   NOT NULL DEFAULT 0,
            `liquidity`          ENUM('current','non_current') NULL DEFAULT NULL,
            `display_order`      INT          NOT NULL DEFAULT 0,
            `is_system`          TINYINT(1)   NOT NULL DEFAULT 1,
            `status`             ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_type_code` (`type_id`, `code`),
            KEY `idx_type` (`type_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + account_sub_types table ready.\n";

    // ──────────────────────────────────────────────────────────────────────
    // 2. accounts.sub_type_id — nullable FK column (optional classification)
    // ──────────────────────────────────────────────────────────────────────
    $col = $pdo->query("SHOW COLUMNS FROM `accounts` LIKE 'sub_type_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `accounts` ADD COLUMN `sub_type_id` INT NULL DEFAULT NULL AFTER `account_type_id`");
        echo "  + sub_type_id column added to accounts.\n";
    } else {
        echo "  ~ sub_type_id already exists in accounts — skipping.\n";
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Resolve type_id by CATEGORY (criteria-based — never hard-coded ids)
    // ──────────────────────────────────────────────────────────────────────
    $typeIdByCat = [];
    foreach ($pdo->query("SELECT type_id, category FROM account_types") as $r) {
        if (!empty($r['category']) && !isset($typeIdByCat[$r['category']])) {
            $typeIdByCat[$r['category']] = (int)$r['type_id'];
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Seed standard sub-types (idempotent via INSERT IGNORE + UNIQUE KEY)
    //    Columns: category, name, code, cash_flow, is_bank, liquidity, order
    // ──────────────────────────────────────────────────────────────────────
    $seed = [
        // Asset
        ['asset', 'Current Asset',       'current_asset',       'operating', 0, 'current',     10],
        ['asset', 'Bank',                'bank',                'cash',      1, 'current',     20],
        ['asset', 'Cash',                'cash',                'cash',      1, 'current',     30],
        ['asset', 'Accounts Receivable', 'accounts_receivable', 'operating', 0, 'current',     40],
        ['asset', 'Inventory',           'inventory',           'operating', 0, 'current',     50],
        ['asset', 'Fixed Asset',         'fixed_asset',         'investing', 0, 'non_current', 60],
        ['asset', 'Other Asset',         'other_asset',         'operating', 0, null,          70],
        // Liability
        ['liability', 'Current Liability',   'current_liability',   'operating', 0, 'current',     10],
        ['liability', 'Accounts Payable',    'accounts_payable',    'operating', 0, 'current',     20],
        ['liability', 'Credit Card',         'credit_card',         'operating', 0, 'current',     30],
        ['liability', 'Tax Payable',         'tax_payable',         'operating', 0, 'current',     40],
        ['liability', 'Long-Term Liability', 'long_term_liability', 'financing', 0, 'non_current', 50],
        ['liability', 'Other Liability',     'other_liability',     'operating', 0, null,          60],
        // Equity
        ['equity', "Owner's Capital",   'owners_capital',    'financing', 0, null, 10],
        ['equity', 'Retained Earnings', 'retained_earnings', 'financing', 0, null, 20],
        ['equity', 'Equity',            'equity',            'financing', 0, null, 30],
        // Income (category 'revenue')
        ['revenue', 'Operating Revenue', 'operating_revenue', 'operating', 0, null, 10],
        ['revenue', 'Other Income',      'other_income',      'operating', 0, null, 20],
        // Expense
        ['expense', 'Operating Expense', 'operating_expense', 'operating', 0, null, 10],
        ['expense', 'Cost of Sales',     'cost_of_sales',     'operating', 0, null, 20],
        ['expense', 'Finance Cost',      'finance_cost',      'financing', 0, null, 30],
        ['expense', 'Other Expense',     'other_expense',     'operating', 0, null, 40],
    ];

    $ins = $pdo->prepare("
        INSERT IGNORE INTO `account_sub_types`
            (type_id, name, code, cash_flow_category, is_bank, liquidity, display_order, is_system, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'active')
    ");
    $seeded = 0; $skippedCats = [];
    foreach ($seed as $s) {
        [$cat, $name, $code, $cf, $isBank, $liq, $ord] = $s;
        if (!isset($typeIdByCat[$cat])) { $skippedCats[$cat] = true; continue; }
        $ins->execute([$typeIdByCat[$cat], $name, $code, $cf, $isBank, $liq, $ord]);
        $seeded += $ins->rowCount();
    }
    echo "  + Seeded $seeded new sub-type(s).\n";
    if ($skippedCats) {
        echo "  ~ No account_type row for categories: " . implode(', ', array_keys($skippedCats)) . " — those sub-types skipped.\n";
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Best-effort backfill of existing accounts (criteria-based, only
    //    where sub_type_id IS NULL so manual classifications are preserved).
    //    Joins each account to a sub-type sharing its account_type_id.
    // ──────────────────────────────────────────────────────────────────────
    $backfill = function (string $code, string $whereExtra) use ($pdo) {
        $sql = "
            UPDATE accounts a
            JOIN account_sub_types st
              ON st.type_id = a.account_type_id AND st.code = :code
            SET a.sub_type_id = st.sub_type_id
            WHERE a.sub_type_id IS NULL
              AND $whereExtra
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':code' => $code]);
        return $st->rowCount();
    };

    $n = 0;
    // Cash: liquid + cash/petty/drawer/undeposited in the name
    $n += $backfill('cash', "a.cash_flow_category = 'cash' AND (
            a.account_name LIKE '%petty%' OR a.account_name LIKE '%cash%' OR
            a.account_name LIKE '%drawer%' OR a.account_name LIKE '%undeposited%')");
    // Bank: liquid but not matched as cash above
    $n += $backfill('bank', "a.cash_flow_category = 'cash'");
    // Accounts Receivable
    $n += $backfill('accounts_receivable', "(a.account_name LIKE '%debtor%' OR a.account_name LIKE '%receivable%')");
    // Inventory
    $n += $backfill('inventory', "(a.account_name LIKE '%inventory%' OR a.account_name LIKE '%stock%')");
    // Fixed Asset
    $n += $backfill('fixed_asset', "a.cash_flow_category = 'investing'");
    // Accounts Payable
    $n += $backfill('accounts_payable', "(a.account_name LIKE '%creditor%' OR a.account_name LIKE '%payable%')");
    // Tax Payable
    $n += $backfill('tax_payable', "(a.account_name LIKE '%vat%' OR a.account_name LIKE '%paye%' OR
            a.account_name LIKE '%wht%' OR a.account_name LIKE '%sdl%' OR a.account_name LIKE '% tax%')");

    echo "  + Backfilled sub_type_id on $n existing account(s).\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
