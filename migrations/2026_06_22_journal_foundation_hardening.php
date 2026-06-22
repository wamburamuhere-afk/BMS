<?php
/**
 * 2026_06_22_journal_foundation_hardening.php
 * -------------------------------------------
 * Foundation hardening for the ONE canonical double-entry ledger
 * (journal_entries + journal_entry_items) — the single, fully-explainable
 * source every report reads from.
 *
 * ACCOUNT-AGNOSTIC: this migration does NOT decide which account is debit or
 * credit for any business area. It only strengthens the table's structure.
 *
 *   1. Items become the single source of truth → relax the legacy header
 *      debit_account_id / credit_account_id / amount to NULL (a 4-leg entry
 *      like a sale can no longer be forced into a fake 2-line header).
 *   2. Lifecycle + tracing links on journal_entries:
 *        - reverses_entry_id  → a void/reversal points at the original it cancels
 *                               (source status-change / delete stays explainable).
 *        - parent_entity_type/id → every part-payment can group to its parent
 *                               invoice, so "all payments of INV-X" is traceable.
 *   3. journal_entry_items integrity: indexes + FKs.
 *        - entry_id  → journal_entries  ON DELETE CASCADE  (delete an entry, its
 *                      legs go too — no orphaned half-entries / "lost money").
 *        - account_id → accounts        ON DELETE RESTRICT (can't delete an
 *                      account that still carries ledger history).
 *   4. journal_source_types: a controlled vocabulary catalog so every entry's
 *      origin (expense, sales_invoice, invoice_payment, credit_note, ...) is
 *      consistently explainable. (Catalog only — entity_type is aligned to it
 *      per-area; no destructive backfill here.)
 *
 * Idempotent & re-runnable. FKs are added only when no violating rows exist; if
 * a server has dirty data the FK is SKIPPED with a loud message (deploy is not
 * failed for a pre-existing data condition), while indexes/columns still apply.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: journal foundation hardening...\n";

try {
    $schema = $pdo->query("SELECT DATABASE()")->fetchColumn();

    $colExists = function (string $table, string $col) use ($pdo): bool {
        return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
    };
    $indexExists = function (string $table, string $index) use ($pdo, $schema): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $st->execute([$schema, $table, $index]);
        return (int)$st->fetchColumn() > 0;
    };
    $fkExists = function (string $name) use ($pdo, $schema): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                              WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $st->execute([$schema, $name]);
        return (int)$st->fetchColumn() > 0;
    };

    // ── 1. Relax legacy header trio to NULL (items = the single truth) ──────────
    $pdo->exec("ALTER TABLE `journal_entries` MODIFY `debit_account_id` INT NULL");
    $pdo->exec("ALTER TABLE `journal_entries` MODIFY `credit_account_id` INT NULL");
    $pdo->exec("ALTER TABLE `journal_entries` MODIFY `amount` DECIMAL(15,2) NULL");
    echo "  ~ header debit_account_id / credit_account_id / amount relaxed to NULL.\n";

    // ── 2. Lifecycle + parent-trace columns on journal_entries ──────────────────
    if (!$colExists('journal_entries', 'reverses_entry_id')) {
        $pdo->exec("ALTER TABLE `journal_entries`
                    ADD COLUMN `reverses_entry_id` INT NULL DEFAULT NULL
                    COMMENT 'entry_id this entry reverses/voids (lifecycle consistency)' AFTER `status`");
        echo "  + reverses_entry_id added.\n";
    } else { echo "  ~ reverses_entry_id already exists.\n"; }

    if (!$colExists('journal_entries', 'parent_entity_type')) {
        $pdo->exec("ALTER TABLE `journal_entries`
                    ADD COLUMN `parent_entity_type` VARCHAR(50) NULL DEFAULT NULL
                    COMMENT 'Parent document type (e.g. invoice for a payment entry)' AFTER `entity_type`");
        echo "  + parent_entity_type added.\n";
    } else { echo "  ~ parent_entity_type already exists.\n"; }

    if (!$colExists('journal_entries', 'parent_entity_id')) {
        $pdo->exec("ALTER TABLE `journal_entries`
                    ADD COLUMN `parent_entity_id` INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'Parent document PK (e.g. invoice_id) so all part-payments group to it' AFTER `parent_entity_type`");
        echo "  + parent_entity_id added.\n";
    } else { echo "  ~ parent_entity_id already exists.\n"; }

    if (!$indexExists('journal_entries', 'ix_je_parent')) {
        $pdo->exec("ALTER TABLE `journal_entries` ADD KEY `ix_je_parent` (`parent_entity_type`, `parent_entity_id`)");
        echo "  + ix_je_parent index added.\n";
    } else { echo "  ~ ix_je_parent already exists.\n"; }

    if (!$indexExists('journal_entries', 'ix_je_reverses')) {
        $pdo->exec("ALTER TABLE `journal_entries` ADD KEY `ix_je_reverses` (`reverses_entry_id`)");
        echo "  + ix_je_reverses index added.\n";
    } else { echo "  ~ ix_je_reverses already exists.\n"; }

    if (!$fkExists('fk_je_reverses')) {
        $bad = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries c
                                  LEFT JOIN journal_entries p ON c.reverses_entry_id = p.entry_id
                                  WHERE c.reverses_entry_id IS NOT NULL AND p.entry_id IS NULL")->fetchColumn();
        if ($bad === 0) {
            $pdo->exec("ALTER TABLE `journal_entries`
                        ADD CONSTRAINT `fk_je_reverses` FOREIGN KEY (`reverses_entry_id`)
                        REFERENCES `journal_entries`(`entry_id`) ON DELETE SET NULL ON UPDATE CASCADE");
            echo "  + fk_je_reverses self-FK added (ON DELETE SET NULL).\n";
        } else {
            echo "  ! fk_je_reverses SKIPPED — $bad row(s) point at a missing entry. Clean up, then re-run.\n";
        }
    } else { echo "  ~ fk_je_reverses already exists.\n"; }

    // ── 3. journal_entry_items integrity: indexes then FKs ──────────────────────
    if (!$indexExists('journal_entry_items', 'ix_jei_entry')) {
        $pdo->exec("ALTER TABLE `journal_entry_items` ADD KEY `ix_jei_entry` (`entry_id`)");
        echo "  + ix_jei_entry index added.\n";
    } else { echo "  ~ ix_jei_entry already exists.\n"; }

    if (!$indexExists('journal_entry_items', 'ix_jei_account')) {
        $pdo->exec("ALTER TABLE `journal_entry_items` ADD KEY `ix_jei_account` (`account_id`)");
        echo "  + ix_jei_account index added.\n";
    } else { echo "  ~ ix_jei_account already exists.\n"; }

    if (!$fkExists('fk_jei_entry')) {
        $orphan = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items ji
                                     LEFT JOIN journal_entries je ON ji.entry_id = je.entry_id
                                     WHERE je.entry_id IS NULL")->fetchColumn();
        if ($orphan === 0) {
            $pdo->exec("ALTER TABLE `journal_entry_items`
                        ADD CONSTRAINT `fk_jei_entry` FOREIGN KEY (`entry_id`)
                        REFERENCES `journal_entries`(`entry_id`) ON DELETE CASCADE ON UPDATE CASCADE");
            echo "  + fk_jei_entry FK added (ON DELETE CASCADE).\n";
        } else {
            echo "  ! fk_jei_entry SKIPPED — $orphan orphan line(s) reference a missing entry. Clean up, then re-run.\n";
        }
    } else { echo "  ~ fk_jei_entry already exists.\n"; }

    if (!$fkExists('fk_jei_account')) {
        $orphan = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items ji
                                     LEFT JOIN accounts a ON ji.account_id = a.account_id
                                     WHERE a.account_id IS NULL")->fetchColumn();
        if ($orphan === 0) {
            $pdo->exec("ALTER TABLE `journal_entry_items`
                        ADD CONSTRAINT `fk_jei_account` FOREIGN KEY (`account_id`)
                        REFERENCES `accounts`(`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE");
            echo "  + fk_jei_account FK added (ON DELETE RESTRICT).\n";
        } else {
            echo "  ! fk_jei_account SKIPPED — $orphan line(s) reference a missing account. Clean up, then re-run.\n";
        }
    } else { echo "  ~ fk_jei_account already exists.\n"; }

    // ── 4. Controlled source-type catalog ───────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `journal_source_types` (
        `source_type` VARCHAR(50) NOT NULL,
        `label`       VARCHAR(100) NOT NULL,
        `area`        VARCHAR(50)  NOT NULL DEFAULT 'general',
        `description` VARCHAR(255) NULL,
        `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`source_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
      COMMENT='Controlled vocabulary for journal_entries.entity_type / source tracing'");
    echo "  ~ journal_source_types table ensured.\n";

    $seed = [
        ['expense',          'Expense',                    'expenses',  'Operating expense incurred / paid'],
        ['expense_payment',  'Expense Payment',            'expenses',  'Cash settlement of an expense or payable'],
        ['sales_invoice',    'Sales Invoice',              'sales',     'Customer invoice (revenue + COGS)'],
        ['invoice_payment',  'Invoice Payment',            'sales',     'Customer payment against an invoice (supports installments)'],
        ['credit_note',      'Credit Note',                'sales',     'Reduction / return against a sales invoice'],
        ['purchase_invoice', 'Purchase / Received Invoice','purchases', 'Supplier bill received'],
        ['supplier_payment', 'Supplier Payment',           'purchases', 'Cash settlement to a supplier (supports installments)'],
        ['debit_note',       'Debit Note',                 'purchases', 'Reduction / return against a purchase invoice'],
        ['grn',              'Goods Received Note',         'purchases', 'Inventory received into stock'],
        ['payment_voucher',  'Payment Voucher',            'payments',  'General payment voucher'],
        ['payroll',          'Payroll',                     'payroll',   'Payroll accrual / payment'],
        ['stock_adjustment', 'Stock Adjustment',            'inventory', 'Inventory quantity / value adjustment'],
        ['bank_transfer',    'Bank Transfer',               'treasury',  'Movement between two cash/bank accounts'],
        ['opening_balance',  'Opening Balance',             'equity',    'Opening balances via Opening Balance Equity'],
        ['manual',           'Manual Journal',              'general',   'Hand-entered journal'],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO journal_source_types (source_type, label, area, description) VALUES (?, ?, ?, ?)");
    foreach ($seed as $s) { $ins->execute($s); }
    echo "  + seeded " . count($seed) . " source types (INSERT IGNORE).\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
