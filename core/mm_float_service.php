<?php
/**
 * core/mm_float_service.php
 *
 * Float tracking service for MM agent operations.
 *
 * Handles:
 * - Commission computation from the rate schedule
 * - Float movement records (top-ups, withdrawals)
 * - Float balance snapshots
 */

if (!function_exists('mmComputeCommission')) {
    /**
     * Compute commission for a transaction using the active rate schedule.
     *
     * @param PDO    $pdo
     * @param int    $networkId
     * @param string $txnType   cash_in|cash_out|send|bill_pay|airtime|bank_to_wallet|wallet_to_bank|international
     * @param float  $amount    Principal amount
     * @param string $date      YYYY-MM-DD (for effective date check)
     * @return float Commission amount (0.00 if no rate found)
     */
    function mmComputeCommission(PDO $pdo, int $networkId, string $txnType, float $amount, string $date): float {
        $stmt = $pdo->prepare(
            "SELECT rate_type, rate_value, min_commission, max_commission
             FROM mm_commission_rates
             WHERE network_id   = ?
               AND txn_type     = ?
               AND amount_from <= ?
               AND (amount_to IS NULL OR amount_to >= ?)
               AND status = 'active'
               AND effective_from <= ?
               AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY amount_from DESC
             LIMIT 1"
        );
        $stmt->execute([$networkId, $txnType, $amount, $amount, $date, $date]);
        $rate = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$rate) return 0.0;

        $comm = $rate['rate_type'] === 'percent'
            ? round($amount * $rate['rate_value'] / 100, 2)
            : round((float)$rate['rate_value'], 2);

        if ((float)$rate['min_commission'] > 0 && $comm < (float)$rate['min_commission']) {
            $comm = (float)$rate['min_commission'];
        }
        if ((float)$rate['max_commission'] > 0 && $comm > (float)$rate['max_commission']) {
            $comm = (float)$rate['max_commission'];
        }
        return $comm;
    }
}

if (!function_exists('mmRecordFloatMovement')) {
    require_once __DIR__ . '/mm_posting.php';

    /**
     * Record a float top-up, withdrawal, or adjustment.
     * For float_topup and float_withdrawal, also posts a GL entry.
     *
     * @param PDO    $pdo
     * @param int    $tillId
     * @param int    $networkId
     * @param string $movType     float_topup|float_withdrawal|opening_balance|adjustment
     * @param float  $amount
     * @param string $date        YYYY-MM-DD
     * @param int    $userId
     * @param string $ref
     * @param int    $bankAcctId  GL bank account (required for float_topup/float_withdrawal)
     * @return int movement_id
     */
    function mmRecordFloatMovement(PDO $pdo, int $tillId, int $networkId, string $movType, float $amount, string $date, int $userId, string $ref, int $bankAcctId = 0): int {
        $validTypes = ['float_topup', 'float_withdrawal', 'opening_balance', 'adjustment'];
        if (!in_array($movType, $validTypes)) {
            throw new \InvalidArgumentException("Invalid float movement type: $movType");
        }

        $code = nextCode($pdo, 'MM-FLT');

        $pdo->prepare(
            "INSERT INTO mm_float_movements
                (movement_code, till_id, movement_type, movement_date, amount, bank_account_id, reference_no, notes, status, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())"
        )->execute([$code, $tillId, $movType, $date, $amount, $bankAcctId ?: null, $ref, $ref, 'draft', $userId]);
        $movId = (int)$pdo->lastInsertId();

        // Post GL for top-ups and withdrawals
        if (in_array($movType, ['float_topup', 'float_withdrawal']) && $bankAcctId) {
            $desc = strtoupper(str_replace('_', ' ', $movType)) . " TZS " . number_format($amount) . " — $code";
            $entryId = postMMFloatMovement($pdo, $movId, $networkId, $movType, $amount, $bankAcctId, $date, $userId, $desc);
            if ($entryId) {
                $pdo->prepare("UPDATE mm_float_movements SET journal_entry_id=?, status='posted', posted_at=NOW(), posted_by=? WHERE movement_id=?")
                    ->execute([$entryId, $userId, $movId]);
            }
        }

        return $movId;
    }
}

if (!function_exists('mmTakeFloatSnapshot')) {
    /**
     * Record a float balance snapshot for a till.
     * Called when closing a shift or completing reconciliation.
     *
     * @param PDO    $pdo
     * @param int    $tillId
     * @param float  $floatBalance  E-float balance
     * @param float  $cashBalance   Cash balance
     * @return int snapshot_id
     */
    function mmTakeFloatSnapshot(PDO $pdo, int $tillId, float $floatBalance, float $cashBalance): int {
        $pdo->prepare(
            "INSERT INTO mm_float_snapshots (till_id, snapshot_at, float_balance, cash_balance)
             VALUES (?,NOW(),?,?)"
        )->execute([$tillId, $floatBalance, $cashBalance]);
        return (int)$pdo->lastInsertId();
    }
}
