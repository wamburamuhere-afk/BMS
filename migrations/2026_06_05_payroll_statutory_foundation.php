<?php
/**
 * 2026_06_05_payroll_statutory_foundation.php
 * -------------------------------------------
 * Foundation for compliant Tanzanian payroll: PAYE (on gross − NSSF), employee
 * NSSF, and employer SDL. Mirrors the WHT Payable foundation pattern
 * (2026_06_03_wht_payable_foundation.php).
 *
 *   1. payroll_settings.category — the Settings UI groups rows by this column,
 *      but it is missing on some DBs (the page breaks without it). Add + backfill.
 *
 *   2. PAYE bands — the existing seed has the OUTDATED 9% first band (and +1
 *      boundaries). Replace with the correct mainland 2024/25 set (0/8/20/25/30%),
 *      deactivating the stale rows for audit. Idempotent: skips once correct.
 *
 *   3. Statutory RATE settings (shown in the Settings UI):
 *        - nssf_rate         already present (= 10) → just categorised 'statutory'
 *        - sdl_rate          = 3.5  (statutory tab, %)
 *        - sdl_min_employees = 10   (general tab — a headcount, not a %)
 *
 *   4. Statutory control accounts + system_settings mappings:
 *        Salaries & Wages Expense, PAYE Payable, NSSF Payable, SDL Payable, SDL Expense.
 *      cash_flow_category 'operating' keeps liabilities out of the Paid-From picker.
 *
 *   5. statutory_remittances — one obligation per (tax_type, period); drives the
 *      intelligent remittance schedule (due = month-end + 7 days).
 *
 *   6. payroll.nssf_employee + payment-tracking columns.
 *
 * Purely ADDITIVE and idempotent. No transactions around DDL (MySQL auto-commits).
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payroll_tax.php';   // defaultTanzaniaPayeBrackets()
global $pdo;

echo "Starting migration: Payroll statutory foundation (PAYE / NSSF / SDL)...\n";

try {
    // ── 1. payroll_settings.category (UI grouping) ──────────────────────────
    if (!$pdo->query("SHOW COLUMNS FROM payroll_settings LIKE 'category'")->fetch()) {
        $pdo->exec("ALTER TABLE payroll_settings ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'general' AFTER setting_value");
        echo "  + payroll_settings.category added.\n";
    } else {
        echo "  · payroll_settings.category already present.\n";
    }
    // Classify the statutory % rates so they land in the Statutory tab.
    $pdo->exec("UPDATE payroll_settings SET category = 'statutory'
                 WHERE setting_key IN ('nssf_rate','nhif_rate','wcf_rate','sdl_rate')");

    // ── 2. Correct PAYE bands (replace stale 9% seed with 2024/25 set) ───────
    $correctActive = (int)$pdo->query("SELECT COUNT(*) FROM tax_brackets
                                        WHERE is_active = 1 AND min_income = 270000 AND tax_rate = 8")->fetchColumn();
    if ($correctActive === 0) {
        // Deactivate any current active bands (kept for audit, dated out).
        $pdo->exec("UPDATE tax_brackets
                       SET is_active = 0,
                           effective_to = COALESCE(effective_to, CURDATE()),
                           updated_at = NOW()
                     WHERE is_active = 1");
        $ins = $pdo->prepare("INSERT INTO tax_brackets
                (bracket_name, country, min_income, max_income, tax_rate, fixed_amount, effective_from, effective_to, is_active)
                VALUES (?, 'Tanzania', ?, ?, ?, 0, '2021-07-01', NULL, 1)");
        foreach (defaultTanzaniaPayeBrackets() as $b) {
            $ins->execute([$b['bracket_name'], $b['min_income'], $b['max_income'], $b['tax_rate']]);
        }
        echo "  + replaced stale PAYE bands with correct 2024/25 set (0/8/20/25/30%).\n";
    } else {
        echo "  · correct 2024/25 PAYE bands already active — not changing.\n";
    }

    // ── 3. Statutory rate settings (reuse nssf_rate; add SDL) ───────────────
    $setting = $pdo->prepare("INSERT IGNORE INTO payroll_settings (setting_key, setting_value, category, description, updated_at)
                              VALUES (?, ?, ?, ?, NOW())");
    foreach ([
        ['sdl_rate',          '3.5', 'statutory', 'Skills Development Levy (% of total gross; employer cost, not deducted from staff)'],
        ['sdl_min_employees', '10',  'general',   'Minimum employees before SDL applies (Tanzania Mainland: 10 or more)'],
    ] as $s) {
        $setting->execute($s);
        echo "  + payroll_setting '{$s[0]}' ensured (= {$s[1]}).\n";
    }

    // ── 4. Statutory control accounts + mappings ────────────────────────────
    $typeIdFor = function (string $category) use ($pdo): ?int {
        $v = $pdo->query("SELECT type_id FROM account_types WHERE category = " . $pdo->quote($category) . " LIMIT 1")->fetchColumn();
        return ($v !== false) ? (int)$v : null;
    };
    $ensureAccount = function (string $code, string $name, string $type, string $category, string $settingKey)
                     use ($pdo, $typeIdFor): int {
        $typeId = $typeIdFor($category);
        $id = $pdo->query("SELECT account_id FROM accounts WHERE account_name = " . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
                ->execute([$typeId, (int)$id]);
            $id = (int)$id;
            echo "  · account '{$name}' already exists, id = {$id}.\n";
        } else {
            $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id,
                              cash_flow_category, opening_balance, current_balance, status, created_at)
                           VALUES (?, ?, ?, ?, 'operating', 0, 0, 'active', NOW())")
                ->execute([$code, $name, $type, $typeId]);
            $id = (int)$pdo->lastInsertId();
            echo "  + account '{$name}' created, id = {$id} (type '{$type}').\n";
        }
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
            ->execute([$settingKey, (string)$id]);
        echo "  + setting {$settingKey} = {$id}.\n";
        return $id;
    };

    $ensureAccount('SAL-EXP',  'Salaries & Wages Expense', 'expense',   'expense',   'default_salaries_expense_account_id');
    $ensureAccount('PAYE-PAY', 'PAYE Payable',             'liability', 'liability', 'default_paye_payable_account_id');
    $ensureAccount('NSSF-PAY', 'NSSF Payable',             'liability', 'liability', 'default_nssf_payable_account_id');
    $ensureAccount('SDL-PAY',  'SDL Payable',              'liability', 'liability', 'default_sdl_payable_account_id');
    $ensureAccount('SDL-EXP',  'SDL Expense',              'expense',   'expense',   'default_sdl_expense_account_id');

    // ── 5. statutory_remittances — the remittance schedule ──────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS statutory_remittances (
        remittance_id        INT AUTO_INCREMENT PRIMARY KEY,
        tax_type             ENUM('paye','nssf','sdl') NOT NULL,
        period               VARCHAR(7) NOT NULL,
        amount               DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        due_date             DATE NULL,
        status               ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
        paid_date            DATE NULL,
        paid_from_account_id INT NULL,
        transaction_id       INT NULL,
        notes                TEXT NULL,
        created_by           INT NULL,
        created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tax_period (tax_type, period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + table 'statutory_remittances' ensured.\n";

    // ── 6. payroll columns (NSSF split + payment tracking) ──────────────────
    $addCol = function (string $table, string $col, string $ddl) use ($pdo) {
        if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            echo "  + {$table}.{$col} added.\n";
        } else {
            echo "  · {$table}.{$col} already present.\n";
        }
    };
    $addCol('payroll', 'nssf_employee',          "nssf_employee DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER tax_amount");
    $addCol('payroll', 'paid_from_account_id',   "paid_from_account_id INT NULL DEFAULT NULL");
    $addCol('payroll', 'payment_transaction_id', "payment_transaction_id INT NULL DEFAULT NULL");
    $addCol('payroll', 'payment_date',           "payment_date DATETIME NULL DEFAULT NULL");

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
