<?php
// scope-audit: skip — callers pass a warehouse already checked with userCan('warehouse', …); shift lookups are by the caller's own user_id
/**
 * core/pos_old_sales.php
 * ----------------------
 * "Record Old Sale" (Rekodi Mauzo ya Zamani): a sale that really happened on an
 * earlier day but was never entered, typed in now from the POS terminal.
 *
 * The rules, shared by api/pos/process_sale.php, api/pos/old_sale_context.php,
 * api/pos/void_sale.php and the migrations:
 *   - Only within the last N days (setting pos_old_sale_max_days, default 30),
 *     never in the future.
 *   - The sale lands in the cashier's OWN shift that covered that time, so it
 *     shows in that shift's Z-report. No such shift (a forgotten day) creates a
 *     closed back-dated shift for that user and day, reused by later entries
 *     of the same day.
 *   - A closed shift that receives a sale has its saved totals, expected cash
 *     and cash difference recomputed.
 *   - Not allowed into a finalized bank reconciliation period of the account
 *     the payment lands in.
 */

if (!function_exists('posOldSaleMaxDays')) {
    /** How far back an old sale may be dated (POS Settings), 1–365, default 30. */
    function posOldSaleMaxDays(): int
    {
        $v = function_exists('get_setting') ? (int)get_setting('pos_old_sale_max_days', '30') : 30;
        return ($v >= 1 && $v <= 365) ? $v : 30;
    }
}

if (!function_exists('posOldSaleTimeError')) {
    /**
     * Why $soldAt cannot be used for an old sale, or null when it can.
     *
     * @param string   $soldAt 'Y-m-d H:i' or 'Y-m-d H:i:s'
     * @param int|null $now    unix time, for tests
     */
    function posOldSaleTimeError(string $soldAt, ?int $now = null): ?string
    {
        $now = $now ?? time();
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $soldAt) || ($ts = strtotime($soldAt)) === false) {
            return t('Choose the date and time of the sale.');
        }
        if ($ts > $now + 60) {
            return t('An old sale cannot have a time that has not happened yet.');
        }
        $oldest = strtotime(date('Y-m-d', $now) . ' 00:00:00') - posOldSaleMaxDays() * 86400;
        if ($ts < $oldest) {
            return sprintf(t('This date is more than %d days ago. Pick a closer date, or ask an administrator to change the limit.'), posOldSaleMaxDays());
        }
        return null;
    }
}

if (!function_exists('posOldSaleShiftAt')) {
    /**
     * The user's own shift that covered $soldAt: opened before it and either
     * still open or closed after it. Null when none did.
     */
    function posOldSaleShiftAt(PDO $pdo, int $userId, string $soldAt): ?array
    {
        $st = $pdo->prepare("
            SELECT * FROM cash_register_shifts
             WHERE user_id = ? AND start_time <= ?
               AND (status = 'active' OR end_time >= ?)
          ORDER BY start_time DESC LIMIT 1
        ");
        $st->execute([$userId, $soldAt, $soldAt]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('posOldSaleBackdatedShift')) {
    /** The user's back-dated shift for that calendar day, if one was already created. */
    function posOldSaleBackdatedShift(PDO $pdo, int $userId, string $soldAt): ?array
    {
        $st = $pdo->prepare("
            SELECT * FROM cash_register_shifts
             WHERE user_id = ? AND is_past_entry = 1 AND DATE(start_time) = DATE(?)
          ORDER BY shift_id LIMIT 1
        ");
        $st->execute([$userId, $soldAt]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('posOldSaleShiftPreview')) {
    /**
     * What WOULD happen, without writing anything — for the POS pop-up.
     *
     * @return array{kind: string, shift_id: ?int, shift_code: ?string, start_time: ?string, end_time: ?string}
     *   kind: 'active' (the shift still open), 'closed' (a closed shift of theirs),
     *         'backdated' (their back-dated shift for that day), 'new' (one will be created)
     */
    function posOldSaleShiftPreview(PDO $pdo, int $userId, string $soldAt): array
    {
        $s = posOldSaleShiftAt($pdo, $userId, $soldAt);
        $kind = null;
        if ($s) {
            $kind = $s['status'] === 'active' ? 'active' : (!empty($s['is_past_entry']) ? 'backdated' : 'closed');
        } elseif ($s = posOldSaleBackdatedShift($pdo, $userId, $soldAt)) {
            $kind = 'backdated';
        }
        if (!$s) {
            return ['kind' => 'new', 'shift_id' => null, 'shift_code' => null, 'start_time' => null, 'end_time' => null];
        }
        return ['kind' => $kind, 'shift_id' => (int)$s['shift_id'], 'shift_code' => $s['shift_code'],
                'start_time' => $s['start_time'], 'end_time' => $s['end_time']];
    }
}

if (!function_exists('posOldSaleResolveShift')) {
    /**
     * The shift an old sale goes into, creating the back-dated one when needed.
     * Call inside the sale's transaction.
     *
     * @return array the cash_register_shifts row, plus 'created' => bool
     */
    function posOldSaleResolveShift(PDO $pdo, int $userId, string $soldAt, ?int $warehouseId, ?int $registerId): array
    {
        if ($s = posOldSaleShiftAt($pdo, $userId, $soldAt)) {
            return $s + ['created' => false];
        }
        if ($s = posOldSaleBackdatedShift($pdo, $userId, $soldAt)) {
            // Widen it so its open/close times cover every sale typed into it.
            $pdo->prepare("UPDATE cash_register_shifts
                              SET start_time = LEAST(start_time, ?), end_time = GREATEST(COALESCE(end_time, ?), ?), updated_at = NOW()
                            WHERE shift_id = ?")
                ->execute([$soldAt, $soldAt, $soldAt, (int)$s['shift_id']]);
            $s['start_time'] = min($s['start_time'], $soldAt);
            return $s + ['created' => false];
        }

        $ts   = strtotime($soldAt);
        $code = 'SHIFT-' . date('Ymd-His', $ts) . '-' . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO cash_register_shifts
                          (shift_code, user_id, register_id, warehouse_id, start_time, end_time, starting_cash, ending_cash,
                           expected_cash, cash_difference, status, is_past_entry, notes, closed_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 'closed', 1, ?, ?, NOW())")
            ->execute([$code, $userId, $registerId ?: 1, $warehouseId, $soldAt, $soldAt,
                       'Back-dated shift for sales recorded later (no cash count).', $userId]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ?");
        $st->execute([$id]);
        return ($st->fetch(PDO::FETCH_ASSOC) ?: []) + ['created' => true];
    }
}

if (!function_exists('posOldSaleRecomputeShift')) {
    /**
     * Refresh a CLOSED shift's saved figures after a sale was added to or
     * voided out of it — the Z-report of a closed shift reads these columns.
     * Same arithmetic as api/pos/close_shift.php. A back-dated shift was never
     * counted, so its ending cash follows expected cash and its difference is 0.
     * No-op for an active shift (its totals are computed live).
     */
    function posOldSaleRecomputeShift(PDO $pdo, int $shiftId): void
    {
        require_once __DIR__ . '/pos_shift_reporting.php';
        $st = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ?");
        $st->execute([$shiftId]);
        $shift = $st->fetch(PDO::FETCH_ASSOC);
        if (!$shift || $shift['status'] !== 'closed') return;

        $c = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END), 0) AS cash_out,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'sale' THEN amount ELSE 0 END), 0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'refund' THEN amount ELSE 0 END), 0) AS cash_refunds
              FROM cash_register_transactions WHERE shift_id = ?
        ");
        $c->execute([$shiftId]);
        $cash = $c->fetch(PDO::FETCH_ASSOC);

        $expected = round((float)$shift['starting_cash'] + $cash['cash_in'] - $cash['cash_out'] + $cash['cash_sales'] - $cash['cash_refunds'], 2);
        $ending   = !empty($shift['is_past_entry']) ? $expected : (float)$shift['ending_cash'];
        $t        = posShiftTenderTotals($pdo, $shiftId);

        $pdo->prepare("UPDATE cash_register_shifts
                          SET ending_cash = ?, expected_cash = ?, cash_difference = ?, cash_in = ?, cash_out = ?,
                              total_sales = ?, total_cash_sales = ?, total_card_sales = ?, total_mobile_sales = ?,
                              total_credit_sales = ?, total_refunds = ?, updated_at = NOW()
                        WHERE shift_id = ?")
            ->execute([$ending, $expected, round($ending - $expected, 2), $cash['cash_in'], $cash['cash_out'],
                       $t['total_sales'], $t['total_cash_sales'], $t['total_card_sales'], $t['total_mobile_sales'],
                       $t['total_credit_sales'], $t['total_refunds'], $shiftId]);
    }
}

if (!function_exists('posOldSaleReconLock')) {
    /**
     * A finalized bank reconciliation of $accountId covering $date, or null.
     * Same rule as core/recon_period_lock.php, which guards edits of existing
     * entries; this guards adding a new one into that period.
     */
    function posOldSaleReconLock(PDO $pdo, int $accountId, string $date): ?array
    {
        if ($accountId <= 0) return null;
        try {
            $st = $pdo->prepare("SELECT reconciliation_number, period_start, period_end FROM bank_reconciliations
                                  WHERE bank_account_id = ? AND status = 'reconciled' AND period_start <= ? AND period_end >= ? LIMIT 1");
            $st->execute([$accountId, $date, $date]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;   // no reconciliation module on this database
        }
    }
}

if (!function_exists('posOldSaleSimilar')) {
    /**
     * Sales already recorded on that day for the same total — a likely
     * double entry. Shown as a warning, never a block.
     */
    function posOldSaleSimilar(PDO $pdo, string $date, float $total, ?int $warehouseId): array
    {
        if ($total <= 0) return [];
        $sql = "SELECT sale_id, receipt_number, sale_date, grand_total FROM pos_sales
                 WHERE DATE(sale_date) = ? AND ABS(grand_total - ?) < 0.5
                   AND sale_status != 'voided' AND is_return_sale = 0";
        $params = [$date, $total];
        if ($warehouseId) { $sql .= " AND warehouse_id = ?"; $params[] = $warehouseId; }
        $st = $pdo->prepare($sql . " ORDER BY sale_date LIMIT 5");
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
