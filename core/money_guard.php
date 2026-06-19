<?php
/**
 * core/money_guard.php
 * --------------------
 * Step 2 of the "no money moves silently" hardening.
 *
 * ADDITIVE ONLY. This file DEFINES new helpers and changes NO existing behaviour.
 * The legacy postInflow()/postOutflow()/postPettyCashLedger() in payment_source.php
 * are left exactly as they are and keep working. Money in/out handlers opt in to the
 * strict path ONE AT A TIME in later steps — so nothing is blocked or left hanging by
 * loading this file.
 *
 * The problem it solves (see the audit): the legacy posters return a bare `null` on
 * failure and the callers ignore it, so a document can save and report success while
 * NOTHING reaches the books. These helpers make a failed money movement LOUD and force
 * the caller to surface the REAL reason (not a generic "posting failed").
 *
 * Provides:
 *   - MoneyPostingException     — carries a machine `reason` + a human message.
 *   - MONEY_ERR_* constants      — the reason catalog (so alerts can state the real issue).
 *   - requireCashBankAccount()   — validate the chosen account, or throw the specific reason.
 *   - accountFundsWarning()       — I3 "warn but allow" funds check; NEVER blocks (returns a
 *                                   warning string or null).
 *   - postOutflowOrFail()        — strict money-OUT: returns the transaction_id, or THROWS the
 *                                   specific reason. Delegates the actual write to postOutflow().
 *   - postInflowOrFail()         — strict money-IN: same contract, delegates to postInflow().
 */

require_once __DIR__ . '/payment_source.php';   // postInflow / postOutflow / cashBankAccounts
require_once __DIR__ . '/account_balance.php';   // accountLedgerBalance (funds check)

/* ── Reason catalog — every money failure names its real cause ───────────────── */
if (!defined('MONEY_ERR_NO_ACCOUNT')) {
    define('MONEY_ERR_NO_ACCOUNT',       'no_account_selected');
    define('MONEY_ERR_NOT_CASH_BANK',    'account_not_cash_bank');
    define('MONEY_ERR_CONTROL_UNMAPPED', 'control_account_unmapped');
    define('MONEY_ERR_AMOUNT_INVALID',   'amount_invalid');
    define('MONEY_ERR_WHT_INVALID',      'wht_invalid');
    define('MONEY_ERR_LEDGER_WRITE',     'ledger_write_failed');
}

/* ── A money failure is an exception that carries its machine reason ──────────── */
if (!class_exists('MoneyPostingException')) {
    class MoneyPostingException extends RuntimeException
    {
        /** @var string machine-readable reason (one of MONEY_ERR_*) */
        public string $reason;

        public function __construct(string $reason, string $message)
        {
            parent::__construct($message);
            $this->reason = $reason;
        }
    }
}

if (!function_exists('requireCashBankAccount')) {
    /**
     * Validate that $accountId is an active cash/bank account money may move through.
     * Returns the int id on success; otherwise THROWS MoneyPostingException with the
     * exact reason — never returns a falsy value, never lets a blank slip past.
     *
     * @param string $label  Human label for the field ("Paid-From", "Received-Into").
     */
    function requireCashBankAccount(PDO $pdo, $accountId, string $label = 'cash/bank'): int
    {
        $id = (int)$accountId;
        if ($id <= 0) {
            throw new MoneyPostingException(
                MONEY_ERR_NO_ACCOUNT,
                "No $label account was selected. Choose the cash/bank account the money moves through so it is recorded in the books."
            );
        }

        // Must be in the canonical cash/bank list (active asset, Bank/Cash sub-type, leaf).
        foreach (cashBankAccounts($pdo) as $a) {
            if ((int)$a['account_id'] === $id) {
                return $id;   // valid
            }
        }

        // Not a cash/bank account — give the precise reason (missing vs. wrong nature).
        $look = $pdo->prepare("SELECT account_name, status FROM accounts WHERE account_id = ?");
        $look->execute([$id]);
        $row = $look->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new MoneyPostingException(
                MONEY_ERR_NOT_CASH_BANK,
                "The selected $label account (#$id) does not exist."
            );
        }
        throw new MoneyPostingException(
            MONEY_ERR_NOT_CASH_BANK,
            "\"{$row['account_name']}\" is not an active cash/bank account — money can only move through a Bank or Cash account. "
            . "Pick a valid $label account or tag this account's Sub Type as Bank/Cash."
        );
    }
}

if (!function_exists('accountFundsWarning')) {
    /**
     * I3 policy = "warn but allow". This NEVER blocks: it returns a human warning
     * string when the source account's ledger balance is less than the amount being
     * paid, or null when funds are sufficient (or unknowable). The caller surfaces the
     * warning to the user but still records the transaction.
     */
    function accountFundsWarning(PDO $pdo, int $sourceAccountId, float $needed): ?string
    {
        if ($sourceAccountId <= 0 || $needed <= 0) return null;
        if (!function_exists('accountLedgerBalance')) return null;
        $bal = accountLedgerBalance($pdo, $sourceAccountId);
        if ($bal < $needed) {
            return 'Note: this account\'s available balance (' . number_format($bal, 2)
                 . ') is less than the amount (' . number_format($needed, 2)
                 . '). The transaction was still recorded.';
        }
        return null;
    }
}

if (!function_exists('postOutflowOrFail')) {
    /**
     * Strict money-OUT. Same arguments as postOutflow(), but returns the transaction_id
     * on success and THROWS MoneyPostingException (with the specific reason) on any
     * failure — so a caller wrapped in a DB transaction rolls back and NOTHING saves
     * half-recorded. Delegates the real double-entry write to the existing postOutflow().
     *
     * @return int transaction_id (always > 0 on return)
     * @throws MoneyPostingException
     */
    function postOutflowOrFail(
        PDO $pdo, string $type, $paidFromAccountId, ?int $debitAccountId,
        float $amount, string $date, ?string $reference, string $description,
        ?int $projectId = null, float $whtAmount = 0.0, ?int $whtAccountId = null
    ): int {
        if ($amount <= 0) {
            throw new MoneyPostingException(MONEY_ERR_AMOUNT_INVALID, 'Amount must be greater than zero.');
        }
        $src = requireCashBankAccount($pdo, $paidFromAccountId, 'Paid-From');
        if (!$debitAccountId || (int)$debitAccountId <= 0) {
            throw new MoneyPostingException(
                MONEY_ERR_CONTROL_UNMAPPED,
                'The offsetting account for this payment (e.g. Accounts Payable / the expense account) is not configured. Ask an admin to set it.'
            );
        }
        if ($whtAmount > 0) {
            if (!$whtAccountId) {
                throw new MoneyPostingException(MONEY_ERR_WHT_INVALID, 'Withholding tax was requested but no WHT Payable account is configured.');
            }
            if ($whtAmount >= $amount) {
                throw new MoneyPostingException(MONEY_ERR_WHT_INVALID, 'Withholding tax cannot meet or exceed the payment amount.');
            }
        }

        $txn = postOutflow($pdo, $type, $src, (int)$debitAccountId, $amount, $date,
                           $reference, $description, $projectId, $whtAmount, $whtAccountId);
        if (!$txn) {
            throw new MoneyPostingException(
                MONEY_ERR_LEDGER_WRITE,
                'The payment could not be written to the ledger — the double entry did not post. Nothing was saved.'
            );
        }
        return (int)$txn;
    }
}

if (!function_exists('postInflowOrFail')) {
    /**
     * Strict money-IN. Mirror of postOutflowOrFail() for receipts/income: returns the
     * transaction_id, or THROWS the specific reason. Delegates to postInflow().
     *
     * @return int transaction_id (always > 0 on return)
     * @throws MoneyPostingException
     */
    function postInflowOrFail(
        PDO $pdo, string $type, $receivedIntoAccountId, ?int $creditAccountId,
        float $amount, string $date, ?string $reference, string $description,
        ?int $projectId = null
    ): int {
        if ($amount <= 0) {
            throw new MoneyPostingException(MONEY_ERR_AMOUNT_INVALID, 'Amount must be greater than zero.');
        }
        $dst = requireCashBankAccount($pdo, $receivedIntoAccountId, 'Received-Into');
        if (!$creditAccountId || (int)$creditAccountId <= 0) {
            throw new MoneyPostingException(
                MONEY_ERR_CONTROL_UNMAPPED,
                'The income/source account for this receipt is not configured. Ask an admin to set it.'
            );
        }

        $txn = postInflow($pdo, $type, $dst, (int)$creditAccountId, $amount, $date,
                          $reference, $description, $projectId);
        if (!$txn) {
            throw new MoneyPostingException(
                MONEY_ERR_LEDGER_WRITE,
                'The receipt could not be written to the ledger — the double entry did not post. Nothing was saved.'
            );
        }
        return (int)$txn;
    }
}
