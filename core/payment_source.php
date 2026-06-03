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

if (!function_exists('postOutflow')) {
    /**
     * Post a money-out entry to the consolidated ledger:
     *   Dr $debitAccountId, Cr $paidFromAccountId  (amount).
     *
     * Best-effort: if accounts are missing it returns null (the caller's own
     * record still saves); never throws.
     *
     * @return int|null transaction_id, or null when not posted.
     */
    function postOutflow(PDO $pdo, string $type, ?int $paidFromAccountId, ?int $debitAccountId,
                         float $amount, string $date, ?string $reference, string $description,
                         ?int $projectId = null): ?int
    {
        if (!$paidFromAccountId || !$debitAccountId || $amount <= 0) return null;
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
        return !empty($res['success']) ? (int)$res['transaction_id'] : null;
    }
}

if (!function_exists('reverseOutflow')) {
    /**
     * Reverse a previously posted outflow (on delete / void) by removing its
     * ledger rows. Safe to call with null / 0.
     */
    function reverseOutflow(PDO $pdo, ?int $transactionId): void
    {
        if (!$transactionId) return;
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$transactionId]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$transactionId]);
    }
}
