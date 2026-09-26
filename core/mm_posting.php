<?php
/**
 * core/mm_posting.php
 *
 * Mobile Money GL posting engine.
 *
 * The fundamental MM accounting identity:
 *   E-Float + Cash = Constant (total float stays same, just changes form)
 *
 * Transaction GL logic:
 *  cash_in:  Dr Cash-at-Outlets | Cr E-Float  (+commission: Dr E-Float | Cr Commission Income)
 *  cash_out: Dr E-Float | Cr Cash-at-Outlets  (+commission same)
 *  send/bill_pay/airtime/international: Dr Cash-at-Outlets | Cr E-Float (+commission)
 *  bank_to_wallet: Dr E-Float | Cr Cash-at-Outlets
 *  wallet_to_bank: Dr Cash-at-Outlets | Cr E-Float
 *
 * Float movement GL logic (handled separately):
 *  float_topup:    Dr E-Float | Cr Bank Account
 *  float_withdraw: Dr Bank Account | Cr E-Float
 */

if (!function_exists('mmGLAccountIds')) {
    function mmGLAccountIds(PDO $pdo, int $networkId): array {
        static $cache = [];
        if (!isset($cache[$networkId])) {
            $n = $pdo->prepare("SELECT float_account_id, commission_account_id FROM mm_networks WHERE network_id=? LIMIT 1");
            $n->execute([$networkId]);
            $row = $n->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['float_account_id']) {
                throw new \RuntimeException("MM network $networkId has no e-float GL account configured. Go to Mobile Money → Networks → Edit to set it.");
            }
            $cashFloatId = (int)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='mm_gl_cash_float' LIMIT 1")->fetchColumn();
            if (!$cashFloatId) {
                throw new \RuntimeException("mm_gl_cash_float not set in system_settings. Please run the MM GL accounts migration.");
            }
            $commIncome = (int)($row['commission_account_id'] ?: $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='mm_gl_commission_income' LIMIT 1")->fetchColumn());
            $cache[$networkId] = [
                'efloat'     => (int)$row['float_account_id'],
                'cash_float' => $cashFloatId,
                'commission' => $commIncome,
            ];
        }
        return $cache[$networkId];
    }
}

if (!function_exists('postMMTransaction')) {
    require_once __DIR__ . '/ledger_post.php';

    /**
     * Post a Mobile Money transaction to the canonical double-entry ledger.
     *
     * @param PDO    $pdo
     * @param int    $txnId       mm_transactions.mm_txn_id (already inserted)
     * @param int    $networkId   mm_networks.network_id
     * @param string $txnType     cash_in|cash_out|send|bill_pay|airtime|bank_to_wallet|wallet_to_bank|international
     * @param float  $amount      Transaction principal (TZS)
     * @param float  $commission  Commission earned (TZS, 0 if none)
     * @param string $date        YYYY-MM-DD
     * @param int    $userId
     * @param string $reference   Human-readable description for GL
     * @return int   entry_id
     */
    function postMMTransaction(PDO $pdo, int $txnId, int $networkId, string $txnType, float $amount, float $commission, string $date, int $userId, string $reference): int {
        $accts = mmGLAccountIds($pdo, $networkId);
        $efloat    = $accts['efloat'];
        $cashFloat = $accts['cash_float'];
        $commAcct  = $accts['commission'];

        $lines = [];

        switch ($txnType) {
            case 'cash_in':
                // Customer deposits cash: agent receives cash, sends e-float to network
                $lines[] = ['account_id' => $cashFloat, 'type' => 'debit',  'amount' => $amount, 'description' => 'Cash received from customer'];
                $lines[] = ['account_id' => $efloat,    'type' => 'credit', 'amount' => $amount, 'description' => 'E-Float disbursed to customer'];
                break;

            case 'cash_out':
                // Customer withdraws cash: agent gives cash, receives e-float from network
                $lines[] = ['account_id' => $efloat,    'type' => 'debit',  'amount' => $amount, 'description' => 'E-Float received from customer'];
                $lines[] = ['account_id' => $cashFloat, 'type' => 'credit', 'amount' => $amount, 'description' => 'Cash paid out to customer'];
                break;

            case 'send':
            case 'bill_pay':
            case 'airtime':
            case 'international':
                // Agent collects cash, disburses from e-float
                $lines[] = ['account_id' => $cashFloat, 'type' => 'debit',  'amount' => $amount, 'description' => 'Cash collected from customer'];
                $lines[] = ['account_id' => $efloat,    'type' => 'credit', 'amount' => $amount, 'description' => 'E-Float disbursed (' . $txnType . ')'];
                break;

            case 'bank_to_wallet':
                // Cash deposited to bank, e-float received
                $lines[] = ['account_id' => $efloat,    'type' => 'debit',  'amount' => $amount, 'description' => 'E-Float received (bank deposit)'];
                $lines[] = ['account_id' => $cashFloat, 'type' => 'credit', 'amount' => $amount, 'description' => 'Cash sent to bank'];
                break;

            case 'wallet_to_bank':
                // E-float sent to bank, cash received
                $lines[] = ['account_id' => $cashFloat, 'type' => 'debit',  'amount' => $amount, 'description' => 'Cash received from bank'];
                $lines[] = ['account_id' => $efloat,    'type' => 'credit', 'amount' => $amount, 'description' => 'E-Float sent to bank'];
                break;

            default:
                throw new \InvalidArgumentException("postMMTransaction: unknown txn_type '$txnType'");
        }

        // Commission leg: network credits agent's e-float, agent recognises income
        if ($commission > 0 && $commAcct) {
            $lines[] = ['account_id' => $efloat,   'type' => 'debit',  'amount' => $commission, 'description' => 'Commission earned — network credited e-float'];
            $lines[] = ['account_id' => $commAcct, 'type' => 'credit', 'amount' => $commission, 'description' => 'MM Commission Income'];
        }

        return postLedgerEntry(
            $pdo,
            $reference,
            $lines,
            null,               // project_id — MM has no project scope
            $txnId,
            'mm_transaction',
            $date,
            $userId,
            null                // warehouse_id — MM uses its own scope
        );
    }
}

if (!function_exists('postMMFloatMovement')) {
    require_once __DIR__ . '/ledger_post.php';

    /**
     * Post a float top-up or withdrawal to the GL.
     *
     * float_topup:    Dr E-Float | Cr Bank Account (agent buys e-float with cash)
     * float_withdraw: Dr Bank Account | Cr E-Float (agent converts e-float to cash)
     *
     * @param PDO    $pdo
     * @param int    $movementId  mm_float_movements.movement_id
     * @param int    $networkId
     * @param string $movType     float_topup | float_withdrawal
     * @param float  $amount
     * @param int    $bankAcctId  GL account for the bank/cash side
     * @param string $date        YYYY-MM-DD
     * @param int    $userId
     * @param string $reference
     * @return int   entry_id
     */
    function postMMFloatMovement(PDO $pdo, int $movementId, int $networkId, string $movType, float $amount, int $bankAcctId, string $date, int $userId, string $reference): int {
        $accts = mmGLAccountIds($pdo, $networkId);
        $efloat = $accts['efloat'];

        if ($movType === 'float_topup') {
            $lines = [
                ['account_id' => $efloat,     'type' => 'debit',  'amount' => $amount, 'description' => 'E-Float purchased'],
                ['account_id' => $bankAcctId, 'type' => 'credit', 'amount' => $amount, 'description' => 'Cash/bank paid for e-float top-up'],
            ];
        } elseif ($movType === 'float_withdrawal') {
            $lines = [
                ['account_id' => $bankAcctId, 'type' => 'debit',  'amount' => $amount, 'description' => 'Cash/bank received from e-float withdrawal'],
                ['account_id' => $efloat,     'type' => 'credit', 'amount' => $amount, 'description' => 'E-Float withdrawn'],
            ];
        } else {
            // adjustment / opening_balance — simple balance entry, no GL posting here
            return 0;
        }

        return postLedgerEntry(
            $pdo,
            $reference,
            $lines,
            null,
            $movementId,
            'mm_float_move',
            $date,
            $userId,
            null
        );
    }
}
