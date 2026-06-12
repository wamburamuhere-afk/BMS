<?php
/**
 * 2026_06_07_seed_standard_chart_of_accounts.php
 * ----------------------------------------------
 * scope-audit: skip — standard chart seed migration (queries accounts/account_types only).
 *
 * Seeds a professional, MYOB-style chart of accounts as a proper parent→child
 * TREE so the Chart of Accounts page shows the indented structure from the
 * reference image (Assets › Current Assets › Cash On Hand › Cheque Account …).
 *
 * - Codes use the 1-xxxx / 2-xxxx … hierarchy (no collision with existing codes).
 * - Each account is mapped to a real account_type_id by category; sections whose
 *   category has no type on this server are skipped.
 * - Contra accounts (Accum Dep, Amortisation, Prov'n for Doubtful Debts) carry a
 *   per-account normal_balance override (credit) — demonstrating that feature.
 * - All balances are 0; nothing is a system account (fully editable/deletable).
 * - Idempotent: an account whose code already exists is left untouched, and its
 *   id is still used to parent its children. Safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed standard chart of accounts (tree)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  accounts table missing — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // category → a real account_type_id (portable; skip a section if its category
    // has no type configured here).
    $typeByCat = [];
    foreach ($pdo->query("SELECT category, MIN(type_id) AS type_id FROM account_types WHERE category IS NOT NULL GROUP BY category") as $r) {
        $typeByCat[$r['category']] = (int)$r['type_id'];
    }

    // category → accounts.account_type ENUM value + default natural side
    $enumByCat = ['asset' => 'asset', 'liability' => 'liability', 'equity' => 'equity', 'revenue' => 'income', 'expense' => 'expense'];
    $sideByCat = ['asset' => 'debit', 'liability' => 'credit', 'equity' => 'credit', 'revenue' => 'credit', 'expense' => 'debit'];

    // [ code, name, parent_code|null, category, normal_balance|null(=category default) ]
    $rows = [
        // ── ASSETS (1-xxxx) ──────────────────────────────────────────────
        ['1-0000', 'Assets',                       null,     'asset', null],
        ['1-1000', 'Current Assets',               '1-0000', 'asset', null],
        ['1-1100', 'Cash On Hand',                 '1-1000', 'asset', null],
        ['1-1110', 'Cheque Account',               '1-1100', 'asset', null],
        ['1-1120', 'Payroll Cheque Account',       '1-1100', 'asset', null],
        ['1-1130', 'Cash Drawer',                  '1-1100', 'asset', null],
        ['1-1140', 'Undeposited Cash & Cheques',   '1-1100', 'asset', null],
        ['1-1150', 'Petty Cash',                   '1-1100', 'asset', null],
        ['1-1160', 'Undeposited Funds',            '1-1100', 'asset', null],
        ['1-1190', 'Electronic Payments',          '1-1100', 'asset', null],
        ['1-1200', 'Trade Debtors',                '1-1000', 'asset', null],
        ['1-1210', "Less Prov'n for Doubtful Debts",'1-1000','asset', 'credit'],   // contra-asset
        ['1-1300', 'Inventory',                    '1-1000', 'asset', null],
        ['1-1950', 'Withholding Credits',          '1-0000', 'asset', null],
        ['1-1960', 'Voluntary Withholding Credits','1-1950', 'asset', null],
        ['1-1970', 'ABN Withholding Credits',      '1-1950', 'asset', null],
        ['1-2000', 'Other Assets',                 '1-0000', 'asset', null],
        ['1-2100', 'Deposits Paid',                '1-2000', 'asset', null],
        ['1-2200', 'Prepayments',                  '1-2000', 'asset', null],
        ['1-3000', 'Fixed Assets',                 '1-0000', 'asset', null],
        ['1-3100', 'Office Equipment',             '1-3000', 'asset', null],
        ['1-3110', 'Office Equipment at Cost',     '1-3100', 'asset', null],
        ['1-3120', 'Office Equipment Accum Dep',   '1-3100', 'asset', 'credit'],   // contra
        ['1-3200', 'Computer Equipment',           '1-3000', 'asset', null],
        ['1-3210', 'Computer at Cost',             '1-3200', 'asset', null],
        ['1-3220', 'Computer Accum Dep',           '1-3200', 'asset', 'credit'],   // contra
        ['1-3300', 'Leasehold Improvements',       '1-3000', 'asset', null],
        ['1-3310', 'Improvements at Cost',         '1-3300', 'asset', null],
        ['1-3320', 'Improvements Amortisation',    '1-3300', 'asset', 'credit'],   // contra

        // ── LIABILITIES (2-xxxx) ─────────────────────────────────────────
        ['2-0000', 'Liabilities',                  null,     'liability', null],
        ['2-1000', 'Current Liabilities',          '2-0000', 'liability', null],
        ['2-1100', 'Credit Cards',                 '2-1000', 'liability', null],
        ['2-1110', 'Bankcard',                     '2-1100', 'liability', null],
        ['2-1120', 'Diners Club',                  '2-1100', 'liability', null],
        ['2-1130', 'MasterCard',                   '2-1100', 'liability', null],
        ['2-1200', 'GST/VAT Liabilities',          '2-1000', 'liability', null],
        ['2-1300', 'Payroll Liabilities',          '2-1000', 'liability', null],
        ['2-2000', 'Long Term Liabilities',        '2-0000', 'liability', null],
        ['2-2100', 'Bank Loans',                   '2-2000', 'liability', null],

        // ── EQUITY (3-xxxx) ──────────────────────────────────────────────
        ['3-0000', 'Equity',                       null,     'equity', null],
        ['3-1000', "Owner's Capital",              '3-0000', 'equity', null],
        ['3-2000', 'Retained Earnings',            '3-0000', 'equity', null],
        ['3-9000', 'Current Year Earnings',        '3-0000', 'equity', null],

        // ── INCOME (4-xxxx) ──────────────────────────────────────────────
        ['4-0000', 'Income',                       null,     'revenue', null],
        ['4-1000', 'Sales',                        '4-0000', 'revenue', null],
        ['4-2000', 'Other Income',                 '4-0000', 'revenue', null],

        // ── EXPENSES (6-xxxx) ────────────────────────────────────────────
        ['6-0000', 'Expenses',                     null,     'expense', null],
        ['6-1000', 'Operating Expenses',           '6-0000', 'expense', null],
        ['6-1100', 'Wages & Salaries',             '6-1000', 'expense', null],
        ['6-1200', 'Rent',                         '6-1000', 'expense', null],
        ['6-1300', 'Electricity',                  '6-1000', 'expense', null],
    ];

    $idByCode = [];
    $levelByCode = [];
    $inserted = 0; $skipped = 0; $skippedNoType = 0;

    $find = $pdo->prepare("SELECT account_id, level FROM accounts WHERE account_code = ?");
    $ins = $pdo->prepare("INSERT INTO accounts
        (account_code, account_name, account_type_id, account_type, category_id, description,
         opening_balance, current_balance, parent_account_id, level, normal_balance, is_system, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, NULL, NULL, 0, 0, ?, ?, ?, 0, 'active', NOW(), NOW())");

    foreach ($rows as [$code, $name, $parentCode, $cat, $nb]) {
        if (!isset($typeByCat[$cat])) { $skippedNoType++; continue; }

        // Resolve parent id + level (from this run's map first, else the DB).
        $parentId = null; $level = 1;
        if ($parentCode !== null) {
            if (isset($idByCode[$parentCode])) {
                $parentId = $idByCode[$parentCode];
                $level = $levelByCode[$parentCode] + 1;
            } else {
                $find->execute([$parentCode]);
                if ($pr = $find->fetch(PDO::FETCH_ASSOC)) {
                    $parentId = (int)$pr['account_id'];
                    $level = (int)$pr['level'] + 1;
                }
            }
        }

        // Idempotent: if the code already exists, keep it and reuse its id for children.
        $find->execute([$code]);
        if ($ex = $find->fetch(PDO::FETCH_ASSOC)) {
            $idByCode[$code] = (int)$ex['account_id'];
            $levelByCode[$code] = (int)$ex['level'];
            $skipped++;
            continue;
        }

        $side = $nb ?: $sideByCat[$cat];
        $ins->execute([$code, $name, $typeByCat[$cat], $enumByCat[$cat], $parentId, $level, $side]);
        $newId = (int)$pdo->lastInsertId();
        $idByCode[$code] = $newId;
        $levelByCode[$code] = $level;
        $inserted++;
    }

    echo "  + inserted $inserted account(s); skipped $skipped already-present; $skippedNoType skipped (no type for category).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
