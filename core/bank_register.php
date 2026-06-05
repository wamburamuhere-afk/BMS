<?php
/**
 * core/bank_register.php
 * ----------------------
 * Writes the bank-statement register (`bank_transactions`) so every cash
 * movement leaves an auditable per-account line with a running balance —
 * the data a real bank reconciliation needs. BMS already had the table but
 * nothing ever wrote it (GAP 2). For now only the expense Paid step calls
 * this; other outflows can be wired in later.
 *
 * In BMS there is no separate bank_accounts table — a "bank account" IS an
 * `accounts` row (asset / cash_flow_category='cash'), so `bank_account_id`
 * and `account_id` on the register both hold that accounts.account_id.
 *
 * Running balance: seeded from the account's opening_balance, then each row
 * carries the prior row's balance_after ± its amount. The register currently
 * reflects expense movements only, so it is a cumulative recorded-movements
 * trail, not (yet) the full bank balance.
 */

if (!function_exists('bankRegisterRunningSeed')) {
    /** Prior running balance for an account: last register row, else opening_balance. */
    function bankRegisterRunningSeed(PDO $pdo, int $bankAccountId): float
    {
        $last = $pdo->prepare("SELECT balance_after FROM bank_transactions
                                WHERE bank_account_id = ?
                             ORDER BY transaction_date DESC, transaction_id DESC LIMIT 1");
        $last->execute([$bankAccountId]);
        $prev = $last->fetchColumn();
        if ($prev !== false && $prev !== null) return (float)$prev;

        $ob = $pdo->prepare("SELECT COALESCE(opening_balance, 0) FROM accounts WHERE account_id = ?");
        $ob->execute([$bankAccountId]);
        return (float)($ob->fetchColumn() ?: 0);
    }
}

if (!function_exists('recordBankTransaction')) {
    /**
     * Append a register row. Idempotent: if a row with the same
     * (bank_account_id, reference_number, transaction_type) already exists it is
     * left as-is and its id returned (so a re-post never double-writes).
     *
     * @param string $type 'withdrawal' | 'deposit'
     * @return int|null bank_transactions.transaction_id, or null on bad input.
     */
    function recordBankTransaction(PDO $pdo, int $bankAccountId, float $amount, string $type,
                                   string $date, ?string $reference, string $description,
                                   ?int $createdBy = null, ?string $category = null): ?int
    {
        if ($bankAccountId <= 0 || $amount <= 0 || !in_array($type, ['withdrawal', 'deposit'], true)) {
            return null;
        }

        // Idempotency guard.
        if ($reference !== null && $reference !== '') {
            $dup = $pdo->prepare("SELECT transaction_id FROM bank_transactions
                                   WHERE bank_account_id = ? AND reference_number = ? AND transaction_type = ?
                                   LIMIT 1");
            $dup->execute([$bankAccountId, $reference, $type]);
            if ($existing = $dup->fetchColumn()) return (int)$existing;
        }

        $prev  = bankRegisterRunningSeed($pdo, $bankAccountId);
        $delta = ($type === 'deposit') ? $amount : -$amount;
        $balanceAfter = round($prev + $delta, 2);

        $stmt = $pdo->prepare("
            INSERT INTO bank_transactions
                (bank_account_id, account_id, transaction_date, value_date, description,
                 reference_number, transaction_type, amount, balance_after, category,
                 matching_status, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unmatched', 'cleared', ?, NOW(), NOW())
        ");
        $stmt->execute([
            $bankAccountId, $bankAccountId, $date, $date, $description,
            ($reference !== '' ? $reference : null), $type, $amount, $balanceAfter, $category,
            $createdBy,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('reverseBankTransaction')) {
    /**
     * Remove a register row on void/reverse, identified by its reference on the
     * account. Deletes the row (the void's ledger reversal restores the balance).
     * Safe when no row exists.
     */
    function reverseBankTransaction(PDO $pdo, int $bankAccountId, ?string $reference, string $type = 'withdrawal'): void
    {
        if ($bankAccountId <= 0 || $reference === null || $reference === '') return;
        $pdo->prepare("DELETE FROM bank_transactions
                        WHERE bank_account_id = ? AND reference_number = ? AND transaction_type = ?")
            ->execute([$bankAccountId, $reference, $type]);
    }
}
