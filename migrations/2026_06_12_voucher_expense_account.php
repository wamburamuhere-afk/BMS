<?php
/**
 * 2026_06_12_voucher_expense_account.php
 * --------------------------------------
 * Gives payment vouchers a real EXPENSE ACCOUNT (the QuickBooks/Xero model the
 * petty cash module already uses) so a paid voucher posts Dr [expense account] /
 * Cr [paid-from], landing the cost in the Profit & Loss — instead of the current
 * Dr Accounts Payable, where the cost never reaches the P&L.
 *
 *   payment_vouchers.expense_account_id  — the expense the voucher is booked to
 *   payment_vouchers.needs_review        — flag for entries given a safe default
 *
 * Back-fills existing vouchers with a safe default expense account (the shared
 * "Petty Cash – Uncategorised" holding account, falling back to the first expense
 * account) and flags them, so going forward they post correctly and can be
 * re-categorised. Idempotent; criteria-based.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';
global $pdo;

echo "Starting migration: voucher expense account...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'payment_vouchers'")->fetch()) {
        echo "  ~ payment_vouchers absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }

    foreach ([
        'expense_account_id' => "ADD COLUMN `expense_account_id` INT NULL DEFAULT NULL AFTER `expense_category_id`",
        'needs_review'       => "ADD COLUMN `needs_review` TINYINT(1) NOT NULL DEFAULT 0 AFTER `expense_account_id`",
    ] as $col => $ddl) {
        if (!$pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE " . $pdo->quote($col))->fetch()) {
            $pdo->exec("ALTER TABLE payment_vouchers $ddl");
            echo "  + {$col} column added.\n";
        } else {
            echo "  ~ {$col} already exists.\n";
        }
    }

    // Safe default expense account: the shared holding account, else first expense.
    $default = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='PC-UNCAT' OR account_name='Petty Cash – Uncategorised' LIMIT 1")->fetchColumn() ?: 0);
    if (!$default) {
        $exp = expenseAccounts($pdo);
        $default = !empty($exp) ? (int)$exp[0]['account_id'] : 0;
    }

    if ($default) {
        $st = $pdo->prepare("UPDATE payment_vouchers SET expense_account_id = ?, needs_review = 1
                             WHERE expense_account_id IS NULL");
        $st->execute([$default]);
        echo "  + Back-filled {$st->rowCount()} voucher(s) with a default expense account (flagged for review).\n";
    } else {
        echo "  ~ No expense account available — back-fill skipped.\n";
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
