<?php
/**
 * 2026_06_02_payment_source_foundation.php
 * ----------------------------------------
 * Foundation for "Paid From" source accounts + consolidated expenses.
 *
 *   1. Ensures a default "Accounts Payable" account exists (the debit side when
 *      settling supplier / sub-contractor / voucher / payroll payments) and
 *      records its id in system_settings as default_accounts_payable_account_id.
 *   2. Ensures a "Petty Cash" account exists (the imprest float) and records its
 *      id as default_petty_cash_account_id.
 *
 * These let every outflow post a balanced entry to the consolidated transactions
 * ledger (Dr expense/AP, Cr the Paid-From cash/bank account).
 *
 * Idempotent: accounts created only if missing; settings upserted.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: payment source foundation...\n";

try {
    // Resolve account_type_id by category so the accounts pass the chart-of-
    // accounts integrity guard (every account must have a valid type_id).
    $typeIdFor = function (PDO $pdo, string $category): ?int {
        $s = $pdo->prepare("SELECT type_id FROM account_types WHERE category = ? LIMIT 1");
        $s->execute([$category]);
        $v = $s->fetchColumn();
        return $v !== false ? (int)$v : null;
    };
    $liabTypeId  = $typeIdFor($pdo, 'liability');
    $assetTypeId = $typeIdFor($pdo, 'asset');

    /** Find an account id by name, or create it; ensure its type_id is set. */
    $ensureAccount = function (PDO $pdo, string $name, string $code, string $type, string $cf, ?int $typeId) {
        $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE account_name = ? LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id) {
            // Backfill the type_id if it was created NULL by an earlier run.
            $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
                ->execute([$typeId, (int)$id]);
            return (int)$id;
        }
        $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id, cash_flow_category,
                          opening_balance, current_balance, status, created_at)
                       VALUES (?, ?, ?, ?, ?, 0, 0, 'active', NOW())")
            ->execute([$code, $name, $type, $typeId, $cf]);
        return (int)$pdo->lastInsertId();
    };

    $apId = $ensureAccount($pdo, 'Accounts Payable', 'AP-001', 'liability', 'operating', $liabTypeId);
    echo "  + Accounts Payable account id = {$apId} (type_id {$liabTypeId}).\n";

    $pcId = $ensureAccount($pdo, 'Petty Cash', 'CASH-PC', 'asset', 'cash', $assetTypeId);
    echo "  + Petty Cash account id = {$pcId} (type_id {$assetTypeId}).\n";

    $upsert = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                             VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $upsert->execute(['default_accounts_payable_account_id', (string)$apId]);
    $upsert->execute(['default_petty_cash_account_id', (string)$pcId]);
    echo "  + settings upserted (default AP + petty cash account ids).\n";

    // 3. Widen transactions.transaction_type so each consolidated outflow keeps
    //    its own type (for the consolidated-expenses breakdown). Idempotent.
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'supplier_payment'") === false) {
        $pdo->exec("ALTER TABLE transactions MODIFY COLUMN transaction_type
                    ENUM('disbursement','repayment','fee','interest','expense','general',
                         'supplier_payment','received_invoice_payment','sc_payment','payroll','voucher','petty_cash')
                    NOT NULL DEFAULT 'general'");
        echo "  + transaction_type enum widened with the new outflow types.\n";
    } else {
        echo "  · transaction_type enum already widened, skipping.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
