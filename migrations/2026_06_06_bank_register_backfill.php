<?php
/**
 * 2026_06_06_bank_register_backfill.php
 * -------------------------------------
 * GAP 2 — populate the bank-statement register (`bank_transactions`) from the
 * expenses that were already POSTED at create (transaction_id set), so the new
 * Bank Statement view and reconciliation have history. Forward (new) expenses
 * write their register row at the Paid step via core/bank_register.php.
 *
 * Per bank/cash account, oldest → newest, each row carries the running
 * `balance_after` (seeded from the account's opening_balance). Idempotent:
 * recordBankTransaction() skips a row that already exists for the same
 * (bank_account_id, reference_number='EXP-{id}', withdrawal). Safe to re-run.
 *
 * Additive — no existing row touched.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/bank_register.php';
global $pdo;

echo "Starting migration: backfill bank_transactions from posted expenses...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'bank_transactions'")->fetch()) {
        echo "  ! bank_transactions table not found — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // Posted expenses (money already moved) with a real Paid-From account + amount.
    $stmt = $pdo->query("
        SELECT expense_id, bank_account_id, amount, expense_date, description, created_by
          FROM expenses
         WHERE transaction_id IS NOT NULL
           AND bank_account_id IS NOT NULL
           AND amount > 0
      ORDER BY bank_account_id ASC, expense_date ASC, expense_id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $written = 0; $skipped = 0;
    foreach ($rows as $r) {
        $ref  = 'EXP-' . (int)$r['expense_id'];
        $desc = 'Expense #' . (int)$r['expense_id'] . ': ' . substr((string)$r['description'], 0, 100);

        // Pre-check for the idempotent skip count (helper also guards).
        $dup = $pdo->prepare("SELECT 1 FROM bank_transactions
                               WHERE bank_account_id = ? AND reference_number = ? AND transaction_type = 'withdrawal' LIMIT 1");
        $dup->execute([(int)$r['bank_account_id'], $ref]);
        if ($dup->fetch()) { $skipped++; continue; }

        recordBankTransaction(
            $pdo,
            (int)$r['bank_account_id'],
            (float)$r['amount'],
            'withdrawal',
            $r['expense_date'],
            $ref,
            $desc,
            $r['created_by'] !== null ? (int)$r['created_by'] : null
        );
        $written++;
    }

    echo "  + processed " . count($rows) . " posted expense(s): {$written} register row(s) written, {$skipped} already present.\n";
    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
