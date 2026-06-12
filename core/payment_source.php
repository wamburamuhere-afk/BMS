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
            SELECT a.account_id, a.account_code, a.account_name
              FROM accounts a
             WHERE a.status = 'active'
               AND a.account_type = 'asset'
               AND a.cash_flow_category = 'cash'
               AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id)
          ORDER BY a.account_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('bankCashAccountsForDisplay')) {
    /**
     * Cash/bank accounts for the Bank Accounts management VIEW. Same "bank nature"
     * marker as cashBankAccounts() — asset + cash_flow_category='cash' — but it
     * KEEPS group headers (e.g. "Cash On Hand") so the page can show the hierarchy,
     * whereas cashBankAccounts() is leaf-only because you can only pay FROM a real
     * (postable) account. Ordered by code so parents sit above their children.
     *
     * @return array<int,array{account_id:int,account_code:string,account_name:string,
     *                          level:int,parent_account_id:?int,has_children:int}>
     */
    function bankCashAccountsForDisplay(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT a.account_id, a.account_code, a.account_name, a.account_type,
                   a.level, a.parent_account_id, a.is_system, a.status,
                   (CASE WHEN EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id
                                       AND ch.account_id <> a.account_id) THEN 1 ELSE 0 END) AS has_children
              FROM accounts a
             WHERE a.status = 'active'
               AND a.account_type = 'asset'
               AND a.cash_flow_category = 'cash'
          ORDER BY a.account_code, a.account_name
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

if (!function_exists('reverseJournalBalances')) {
    /**
     * Generic reversal of ANY posted journal: undo each leg's balance effect
     * (debit↔credit) then delete the ledger rows. Used to unwind a payroll accrual
     * or SDL accrual on delete/edit/recompute. Safe with null/0.
     */
    function reverseJournalBalances(PDO $pdo, ?int $transactionId): void
    {
        if (!$transactionId) return;
        $legs = $pdo->prepare("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id = ?");
        $legs->execute([$transactionId]);
        foreach ($legs->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $opp = ($l['type'] === 'debit') ? 'credit' : 'debit';
            applyAccountBalanceDelta($pdo, (int)$l['account_id'], $opp, (float)$l['amount']);
        }
        unmirrorTransactionFromJournal($pdo, (int)$transactionId);   // keep the canonical journal in sync
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$transactionId]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$transactionId]);
    }
}

if (!function_exists('postPayrollAccrual')) {
    /**
     * Accrual journal for ONE payroll record, posted when it is APPROVED (incurred):
     *   Dr Salaries Expense (gross)             → Income Statement (period earned)
     *   Cr PAYE Payable / Cr NSSF Payable       → owed to TRA / NSSF regardless of pay
     *   Cr Accounts Payable (other deductions)
     *   Cr Salaries Payable (net)               → owed to STAFF until paid (Balance Sheet)
     * Returns transaction_id, or null when accounts are unmapped / amount invalid.
     */
    function postPayrollAccrual(PDO $pdo, array $p, ?int $userId = null): ?int
    {
        $gross = round((float)($p['gross_salary']  ?? 0), 2);
        $paye  = round((float)($p['tax_amount']    ?? 0), 2);
        $nssf  = round((float)($p['nssf_employee'] ?? 0), 2);
        $other = round((float)($p['deductions']    ?? 0), 2);
        $ref   = (string)($p['payroll_number'] ?? ('PAY-' . ($p['payroll_id'] ?? '')));
        $date  = !empty($p['payroll_date']) ? $p['payroll_date'] : date('Y-m-d');
        $projectId = (isset($p['project_id']) && $p['project_id'] !== null) ? (int)$p['project_id'] : null;
        if ($gross <= 0) return null;

        $salExp = (int)getSetting('default_salaries_expense_account_id', 0);
        $payeAcc= (int)getSetting('default_paye_payable_account_id', 0);
        $nssfAcc= (int)getSetting('default_nssf_payable_account_id', 0);
        $salPay = (int)getSetting('default_salaries_payable_account_id', 0);
        $apAcc  = defaultPayableAccountId($pdo);
        if (!$salExp || !$salPay || ($paye>0 && !$payeAcc) || ($nssf>0 && !$nssfAcc) || ($other>0 && !$apAcc)) return null;

        $items = [['account_id'=>$salExp,'type'=>'debit','amount'=>$gross,'description'=>"Payroll {$ref} — gross (accrued)"]];
        $credits = 0.0;
        if ($paye>0)  { $items[]=['account_id'=>$payeAcc,'type'=>'credit','amount'=>$paye,'description'=>"PAYE payable {$ref}"];      $credits+=$paye; }
        if ($nssf>0)  { $items[]=['account_id'=>$nssfAcc,'type'=>'credit','amount'=>$nssf,'description'=>"NSSF payable {$ref}"];      $credits+=$nssf; }
        if ($other>0) { $items[]=['account_id'=>$apAcc, 'type'=>'credit','amount'=>$other,'description'=>"Staff deductions {$ref}"]; $credits+=$other; }
        $netPay = round($gross - $credits, 2);   // Salaries Payable = the balancing remainder
        if ($netPay < 0) return null;
        if ($netPay > 0) $items[] = ['account_id'=>$salPay,'type'=>'credit','amount'=>$netPay,'description'=>"Net wages payable {$ref}"];

        $res = recordGlobalTransaction([
            'transaction_date'=>$date,'amount'=>$gross,'transaction_type'=>'payroll_accrual',
            'reference_number'=>$ref,'description'=>"Payroll {$ref} accrual",'project_id'=>$projectId,
            'journal_items'=>$items,
        ], $pdo);
        if (empty($res['success'])) return null;

        applyAccountBalanceDelta($pdo, $salExp, 'debit', $gross);
        if ($paye>0)  applyAccountBalanceDelta($pdo, $payeAcc, 'credit', $paye);
        if ($nssf>0)  applyAccountBalanceDelta($pdo, $nssfAcc, 'credit', $nssf);
        if ($other>0) applyAccountBalanceDelta($pdo, $apAcc,   'credit', $other);
        if ($netPay>0) applyAccountBalanceDelta($pdo, $salPay, 'credit', $netPay);
        return (int)$res['transaction_id'];
    }
}

if (!function_exists('ensurePayrollAccrued')) {
    /** Idempotently post a payroll record's accrual and store accrual_transaction_id. */
    function ensurePayrollAccrued(PDO $pdo, int $payrollId, ?int $userId = null): ?int
    {
        if ($payrollId <= 0) return null;
        $row = $pdo->prepare("SELECT * FROM payroll WHERE payroll_id = ?");
        $row->execute([$payrollId]);
        $p = $row->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;
        if (!empty($p['accrual_transaction_id'])) return (int)$p['accrual_transaction_id'];
        $txn = postPayrollAccrual($pdo, $p, $userId);
        if ($txn) $pdo->prepare("UPDATE payroll SET accrual_transaction_id = ? WHERE payroll_id = ?")->execute([$txn, $payrollId]);
        return $txn;
    }
}

if (!function_exists('postPayrollPayment')) {
    /**
     * Settle ONE approved payslip to staff (accrual model):
     *   Dr Salaries Payable (net) / Cr Bank (net)
     * The expense + PAYE/NSSF were booked at approval (postPayrollAccrual); payment
     * only clears the staff liability and reduces the bank. The accrual is ensured
     * first (defensive), so the books are correct even if a record reaches payment
     * without an explicit approval step. Returns transaction_id or null.
     */
    function postPayrollPayment(PDO $pdo, array $p, int $paidFromAccountId, ?int $userId = null): ?int
    {
        $pid  = (int)($p['payroll_id'] ?? 0);
        $net  = round((float)($p['net_salary'] ?? 0), 2);
        $ref  = (string)($p['payroll_number'] ?? ('PAY-' . $pid));
        $date = !empty($p['payroll_date']) ? $p['payroll_date'] : date('Y-m-d');
        $projectId = (isset($p['project_id']) && $p['project_id'] !== null) ? (int)$p['project_id'] : null;
        if ($net <= 0 || !$paidFromAccountId) return null;

        // Make sure the liability exists before we clear it.
        if ($pid > 0 && empty($p['accrual_transaction_id'])) ensurePayrollAccrued($pdo, $pid, $userId);

        $salPay = (int)getSetting('default_salaries_payable_account_id', 0);
        if (!$salPay) {
            // No payable mapped → plain outflow against AP so the bank still reduces.
            return postOutflow($pdo, 'payroll', $paidFromAccountId, defaultPayableAccountId($pdo), $net, $date, $ref, "Payroll {$ref} paid (net)", $projectId);
        }

        $res = recordGlobalTransaction([
            'transaction_date'=>$date,'amount'=>$net,'transaction_type'=>'payroll',
            'reference_number'=>$ref,'description'=>"Payroll {$ref} paid (net)",'project_id'=>$projectId,
            'journal_items'=>[
                ['account_id'=>$salPay,'type'=>'debit','amount'=>$net,'description'=>"Settle net wages {$ref}"],
                ['account_id'=>$paidFromAccountId,'type'=>'credit','amount'=>$net,'description'=>"Payroll {$ref} paid"],
            ],
        ], $pdo);
        if (empty($res['success'])) return null;
        applyAccountBalanceDelta($pdo, $salPay, 'debit', $net);             // staff liability ↓
        applyAccountBalanceDelta($pdo, $paidFromAccountId, 'credit', $net); // bank ↓
        return (int)$res['transaction_id'];
    }
}

if (!function_exists('postSdlAccrual')) {
    /**
     * Period SDL accrual (employer cost, recognised regardless of payment):
     *   Dr SDL Expense / Cr SDL Payable.
     * Idempotent with recompute: if the period's SDL changed (more employees, edits)
     * the prior accrual is reversed and re-posted; if it dropped to zero it is removed.
     * Caller supplies the computed SDL amount (from computeSdl()).
     */
    function postSdlAccrual(PDO $pdo, string $period, float $sdlAmount, ?int $userId = null): ?int
    {
        $sdlAmount = round(max(0.0, $sdlAmount), 2);
        $ref = 'SDL-ACC-' . $period;
        $ex = $pdo->prepare("SELECT transaction_id, amount FROM transactions WHERE transaction_type = 'sdl_accrual' AND reference_number = ? LIMIT 1");
        $ex->execute([$ref]);
        $existing = $ex->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (abs((float)$existing['amount'] - $sdlAmount) < 0.01) return (int)$existing['transaction_id'];
            reverseJournalBalances($pdo, (int)$existing['transaction_id']);   // changed → unwind, repost below
        }
        if ($sdlAmount <= 0) return null;
        $sdlExp = (int)getSetting('default_sdl_expense_account_id', 0);
        $sdlPay = (int)getSetting('default_sdl_payable_account_id', 0);
        if (!$sdlExp || !$sdlPay) return null;
        $res = recordGlobalTransaction([
            'transaction_date'=>date('Y-m-d'),'amount'=>$sdlAmount,'transaction_type'=>'sdl_accrual',
            'reference_number'=>$ref,'description'=>"SDL accrual {$period}",
            'journal_items'=>[
                ['account_id'=>$sdlExp,'type'=>'debit','amount'=>$sdlAmount,'description'=>"SDL expense {$period}"],
                ['account_id'=>$sdlPay,'type'=>'credit','amount'=>$sdlAmount,'description'=>"SDL payable {$period}"],
            ],
        ], $pdo);
        if (empty($res['success'])) return null;
        applyAccountBalanceDelta($pdo, $sdlExp, 'debit', $sdlAmount);
        applyAccountBalanceDelta($pdo, $sdlPay, 'credit', $sdlAmount);
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
        unmirrorTransactionFromJournal($pdo, (int)$transactionId);   // keep the canonical journal in sync
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$transactionId]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$transactionId]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Canonical account-slice helpers (Chart of Accounts upgrade, Phase 4).
//
// Every Finance dropdown should pull its accounts from ONE source of truth —
// the `accounts` master table — filtered on the canonical `account_types.category`
// (the same column every financial report groups on), NOT the denormalised
// `accounts.account_type` string. This keeps the chart and every form in sync:
// e.g. a `finance_cost` account now appears wherever expense accounts are picked.
// cashBankAccounts() (above) is already canonical; these mirror its shape.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('expenseAccounts')) {
    /**
     * Active expense accounts selectable on expense / payment / transfer-charge
     * forms. Includes finance costs (interest, bank charges) since they are
     * expenses for posting purposes.
     * @return array<int,array{account_id:int,account_code:string,account_name:string}>
     */
    function expenseAccounts(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT a.account_id, a.account_code, a.account_name
              FROM accounts a
              JOIN account_types at ON a.account_type_id = at.type_id
             WHERE a.status = 'active'
               AND at.category IN ('expense','finance_cost')
               AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id)
          ORDER BY a.account_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('incomeAccounts')) {
    /**
     * Active income/revenue accounts selectable on revenue / receipt forms.
     * @return array<int,array{account_id:int,account_code:string,account_name:string}>
     */
    function incomeAccounts(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT a.account_id, a.account_code, a.account_name
              FROM accounts a
              JOIN account_types at ON a.account_type_id = at.type_id
             WHERE a.status = 'active'
               AND at.category = 'revenue'
               AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id)
          ORDER BY a.account_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('allActiveAccounts')) {
    /**
     * Every active account (for journal debit/credit pickers and any all-account
     * dropdown). Carries type/category + tree metadata for richer rendering.
     * @return array<int,array<string,mixed>>
     */
    function allActiveAccounts(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT a.account_id, a.account_code, a.account_name,
                   at.display_name AS type_name, at.category,
                   a.level, a.is_system
              FROM accounts a
              LEFT JOIN account_types at ON a.account_type_id = at.type_id
             WHERE a.status = 'active'
          ORDER BY a.account_code, a.account_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('postInflow')) {
    /**
     * Post a money-IN entry to the consolidated ledger AND move the money:
     *   Dr $receivedIntoAccountId (cash/bank), Cr $creditAccountId (income),
     * then increment the received-into account's current_balance. The mirror of
     * postOutflow(); reverseInflow() undoes both. Like postOutflow we move ONLY
     * the cash leg's stored balance (the income leg still lives in the ledger).
     *
     * Best-effort: returns null when accounts are missing or amount <= 0; never
     * throws (the caller's own record still saves).
     *
     * @return int|null transaction_id, or null when not posted.
     */
    function postInflow(PDO $pdo, string $type, ?int $receivedIntoAccountId, ?int $creditAccountId,
                        float $amount, string $date, ?string $reference, string $description,
                        ?int $projectId = null): ?int
    {
        if (!$receivedIntoAccountId || !$creditAccountId || $amount <= 0) return null;

        $res = recordGlobalTransaction([
            'transaction_date'  => $date,
            'amount'            => $amount,
            'transaction_type'  => $type,
            'reference_number'  => $reference,
            'description'       => $description,
            'account_id'        => $receivedIntoAccountId,  // Dr (money in)
            'contra_account_id' => $creditAccountId,        // Cr (income)
            'project_id'        => $projectId,
        ], $pdo);
        if (empty($res['success'])) return null;

        // Move the money INTO the source account (a debit increases a cash/bank
        // asset balance). The income contra leg stays in books_transactions for
        // the report but its stored balance is left alone — mirror of postOutflow.
        applyAccountBalanceDelta($pdo, $receivedIntoAccountId, 'debit', $amount);

        return (int)$res['transaction_id'];
    }
}

if (!function_exists('reverseInflow')) {
    /**
     * Reverse a previously posted inflow (on delete / void): restore the
     * received-into account balance, then remove the ledger rows. Safe with null/0.
     */
    function reverseInflow(PDO $pdo, ?int $transactionId): void
    {
        if (!$transactionId) return;
        // postInflow moved money in via the DEBIT (cash) leg; reverse by crediting
        // it back (a credit reduces a cash/bank asset balance).
        $lines = $pdo->prepare("SELECT account_id, amount FROM books_transactions WHERE transaction_id = ? AND type = 'debit'");
        $lines->execute([$transactionId]);
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            applyAccountBalanceDelta($pdo, (int)$ln['account_id'], 'credit', (float)$ln['amount']);
        }
        unmirrorTransactionFromJournal($pdo, (int)$transactionId);   // keep the canonical journal in sync
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([$transactionId]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$transactionId]);
    }
}

if (!function_exists('postPettyCashLedger')) {
    /**
     * Post the ledger effect of a petty-cash transaction (used by the petty cash page).
     *   - expense : Dr Accounts Payable / Cr Petty Cash (imprest source, fixed) — via postOutflow.
     *   - deposit : Dr Petty Cash / Cr funding bank/cash — a real transfer where BOTH balances
     *               move (mirrored into the canonical journal by recordGlobalTransaction). This is
     *               what makes a top-up reduce the bank AND raise petty cash on the Chart of Accounts.
     * @return int|null transaction_id (for later reverse/edit), or null when not posted.
     */
    function postPettyCashLedger(PDO $pdo, string $type, float $amount, string $date, ?string $ref, string $desc, ?int $sourceId): ?int
    {
        $pettyId = pettyCashAccountId($pdo);
        if (!$pettyId || $amount <= 0) return null;

        if ($type === 'expense') {
            return postOutflow($pdo, 'petty_cash', $pettyId, defaultPayableAccountId($pdo),
                               $amount, $date, $ref, "Petty cash: " . ($desc !== '' ? $desc : 'expense'), null);
        }
        if ($type === 'deposit') {
            if (!$sourceId) return null;   // a top-up must name a funding account
            $res = recordGlobalTransaction([
                'transaction_date' => $date,
                'amount'           => $amount,
                'transaction_type' => 'petty_cash_topup',
                'reference_number' => $ref,
                'description'      => "Petty cash top-up: " . ($desc !== '' ? $desc : 'deposit'),
                'journal_items'    => [
                    ['account_id' => $pettyId,  'type' => 'debit',  'amount' => $amount, 'description' => 'Petty cash top-up'],
                    ['account_id' => $sourceId, 'type' => 'credit', 'amount' => $amount, 'description' => 'Funded petty cash'],
                ],
            ], $pdo);
            if (empty($res['success'])) return null;
            applyAccountBalanceDelta($pdo, $pettyId,  'debit',  $amount);   // petty cash ↑
            applyAccountBalanceDelta($pdo, $sourceId, 'credit', $amount);   // funding account ↓
            return (int)$res['transaction_id'];
        }
        return null;
    }
}

if (!function_exists('reversePettyCashLedger')) {
    /** Reverse a petty-cash posting using the method matching its original type. */
    function reversePettyCashLedger(PDO $pdo, string $oldType, ?int $oldTxn): void
    {
        if (!$oldTxn) return;
        if ($oldType === 'deposit') {
            reverseJournalBalances($pdo, $oldTxn);   // transfer → undo BOTH legs
        } else {
            reverseOutflow($pdo, $oldTxn);           // expense outflow → undo the source leg
        }
    }
}
