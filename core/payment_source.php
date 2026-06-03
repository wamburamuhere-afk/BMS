<?php
/**
 * core/payment_source.php
 * -----------------------
 * Shared "Paid From" source-account + consolidated-outflow helpers.
 *
 * Every money-out event (supplier payment, received-invoice payment, sub-
 * contractor payment, payroll, voucher, petty cash, expense) posts a balanced
 * entry to the central `transactions` ledger via recordGlobalTransaction():
 *
 *     Dr  <expense / Accounts Payable>      (what we paid for)
 *     Cr  <Paid-From cash/bank account>     (where the money came from)
 *
 * The consolidated-expenses report reads that one ledger. Reusing the existing
 * recordGlobalTransaction() keeps a single source of truth — no parallel
 * balance system.
 */

require_once __DIR__ . '/../api/helpers/transaction_helper.php';

if (!function_exists('cashBankAccounts')) {
    /**
     * Active cash/bank accounts selectable as a payment source (the "Paid From"
     * dropdown). These are asset accounts in the cash cash-flow category.
     * @return array<int,array{account_id:int,account_code:string,account_name:string}>
     */
    function cashBankAccounts(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT account_id, account_code, account_name
              FROM accounts
             WHERE status = 'active'
               AND account_type = 'asset'
               AND cash_flow_category = 'cash'
          ORDER BY account_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('paidFromSelectOptions')) {
    /**
     * Render <option> tags for a Paid-From <select> (excludes the leading
     * placeholder, which the caller supplies). $selected pre-selects one.
     */
    function paidFromSelectOptions(PDO $pdo, $selected = null): string
    {
        $html = '';
        foreach (cashBankAccounts($pdo) as $a) {
            $sel = ((string)$selected === (string)$a['account_id']) ? ' selected' : '';
            $label = $a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '');
            $html .= '<option value="' . (int)$a['account_id'] . '"' . $sel . '>'
                   . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return $html;
    }
}

if (!function_exists('defaultPayableAccountId')) {
    /** The configured Accounts Payable account (debit side for settlements). */
    function defaultPayableAccountId(PDO $pdo): ?int
    {
        $v = getSetting('default_accounts_payable_account_id', '');
        return $v !== '' ? (int)$v : null;
    }
}

if (!function_exists('pettyCashAccountId')) {
    /** The configured Petty Cash (imprest) account. */
    function pettyCashAccountId(PDO $pdo): ?int
    {
        $v = getSetting('default_petty_cash_account_id', '');
        return $v !== '' ? (int)$v : null;
    }
}

if (!function_exists('applyAccountBalanceDelta')) {
    /**
     * Apply a double-entry side to an account's stored current_balance,
     * respecting its normal side:
     *   - a debit increases a debit-normal account, decreases a credit-normal one
     *   - a credit decreases a debit-normal account, increases a credit-normal one
     * So crediting the cash/bank (asset, debit-normal) source REDUCES its balance.
     *
     * @param string $side 'debit' | 'credit'
     */
    function applyAccountBalanceDelta(PDO $pdo, int $accountId, string $side, float $amount): void
    {
        if (!$accountId || $amount == 0.0) return;
        // Resolve the account's normal side (debit-normal: asset/expense).
        $stmt = $pdo->prepare("SELECT at.normal_side
                                 FROM accounts a
                                 LEFT JOIN account_types at ON at.type_id = a.account_type_id
                                WHERE a.account_id = ?");
        $stmt->execute([$accountId]);
        $normal = $stmt->fetchColumn() ?: 'debit';
        $delta = ($side === $normal) ? $amount : -$amount;
        $pdo->prepare("UPDATE accounts SET current_balance = COALESCE(current_balance,0) + ?, updated_at = NOW() WHERE account_id = ?")
            ->execute([$delta, $accountId]);
    }
}

if (!function_exists('postOutflow')) {
    /**
     * Post a money-out entry to the consolidated ledger AND move the money:
     *   Dr $debitAccountId, Cr $paidFromAccountId  (amount),
     * then decrement the Paid-From account's current_balance (and adjust the
     * debit account per its normal side). reverseOutflow() undoes both.
     *
     * Best-effort: if accounts are missing it returns null (the caller's own
     * record still saves); never throws.
     *
     * @return int|null transaction_id, or null when not posted.
     */
    function postOutflow(PDO $pdo, string $type, ?int $paidFromAccountId, ?int $debitAccountId,
                         float $amount, string $date, ?string $reference, string $description,
                         ?int $projectId = null, float $whtAmount = 0.0, ?int $whtAccountId = null): ?int
    {
        if (!$paidFromAccountId || !$debitAccountId || $amount <= 0) return null;

        // Optional withholding-tax split. When WHT applies, the supplier's debt is
        // still cleared in FULL (Dr gross), but the cash that leaves is reduced by
        // the withheld amount, which is parked in WHT Payable (a liability owed to
        // TRA):  Dr Payable (gross) / Cr Cash (gross−WHT) / Cr WHT Payable (WHT).
        // reverseOutflow() reverses every credit leg generically, so it already
        // undoes both the cash and the WHT-payable sides — no change needed there.
        $wht = ($whtAmount > 0 && $whtAccountId) ? round($whtAmount, 2) : 0.0;
        $net = round($amount - $wht, 2);
        if ($wht > 0 && $net <= 0) return null;   // WHT must be a fraction of the payment

        if ($wht > 0) {
            $res = recordGlobalTransaction([
                'transaction_date' => $date,
                'amount'           => $amount,            // gross = the full bill value
                'transaction_type' => $type,
                'reference_number' => $reference,
                'description'      => $description,
                'project_id'       => $projectId,
                'journal_items'    => [
                    ['account_id' => $debitAccountId,    'type' => 'debit',  'amount' => $amount, 'description' => $description],
                    ['account_id' => $paidFromAccountId, 'type' => 'credit', 'amount' => $net,    'description' => $description],
                    ['account_id' => $whtAccountId,      'type' => 'credit', 'amount' => $wht,    'description' => 'WHT withheld'],
                ],
            ], $pdo);
            if (empty($res['success'])) return null;
            // Move balances to match the ledger: cash out by the NET, WHT Payable up
            // by the withheld amount. (Contra/AP balance left alone — see below.)
            applyAccountBalanceDelta($pdo, $paidFromAccountId, 'credit', $net);
            applyAccountBalanceDelta($pdo, $whtAccountId,      'credit', $wht);
            return (int)$res['transaction_id'];
        }

        // ── No WHT: original behaviour, unchanged (zero regression). ──────────
        $res = recordGlobalTransaction([
            'transaction_date'  => $date,
            'amount'            => $amount,
            'transaction_type'  => $type,
            'reference_number'  => $reference,
            'description'       => $description,
            'account_id'        => $debitAccountId,     // Dr
            'contra_account_id' => $paidFromAccountId,  // Cr (money out)
            'project_id'        => $projectId,
        ], $pdo);
        if (empty($res['success'])) return null;

        // Move the money OUT of the source account (credit reduces a cash/bank
        // asset balance). We intentionally do NOT mutate the contra (AP/expense)
        // balance here: BMS does not raise the payable when the bill is booked,
        // so debiting it would drive it misleadingly negative. The contra leg
        // still lives in the ledger (books_transactions) for the report.
        applyAccountBalanceDelta($pdo, $paidFromAccountId, 'credit', $amount);

        return (int)$res['transaction_id'];
    }
}

if (!function_exists('reverseOutflow')) {
    /**
     * Reverse a previously posted outflow (on delete / void): restore the
     * affected account balances, then remove the ledger rows. Safe with null/0.
     */
    function reverseOutflow(PDO $pdo, ?int $transactionId): void
    {
        if (!$transactionId) return;
        // Restore the source account only (mirror of postOutflow, which moved
        // the money out of the credit/source leg). Add the amount back with a
        // debit to the credited account(s).
        $lines = $pdo->prepare("SELECT account_id, amount FROM books_transactions WHERE transaction_id = ? AND type = 'credit'");
        $lines->execute([$transactionId]);
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            applyAccountBalanceDelta($pdo, (int)$ln['account_id'], 'debit', (float)$ln['amount']);
        }
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$transactionId]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$transactionId]);
    }
}
