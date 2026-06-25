<?php
/**
 * core/stock_posting.php
 *
 * GL posting for stock adjustments.
 * Covers all 6 accounting requirements:
 *   1. Two-sided: Dr Inventory ↔ Cr Opening Balance Equity (or reversed for DOWN)
 *   2. Condition: on create, update, or delete of a stock_movement row
 *   3. Accounts: Inventory (asset) + Opening Balance Equity
 *   4. Reports affected: yes (Balance Sheet, Trial Balance)
 *   5. Specific reports: Balance Sheet (both accounts are BS), Trial Balance
 *   6. Delete reverses: reverseStockAdjustmentGl() posts a contra-entry
 */

require_once __DIR__ . '/ledger_post.php';
require_once __DIR__ . '/gl_accounts.php';

if (!function_exists('postStockAdjustmentGl')) {
    /**
     * Post a balanced journal entry for a stock adjustment.
     *
     * UP  (adjustment_in, found)  → Dr Inventory / Cr Opening Balance Equity
     * DOWN (all other types)       → Dr Opening Balance Equity / Cr Inventory
     *
     * Called inside the caller's transaction; commit/rollback is the caller's job.
     * Silent no-op when either account is not yet configured in the chart.
     *
     * @param PDO     $pdo
     * @param int     $movementId   stock_movements.movement_id (entity_id link)
     * @param float   $quantity     Signed or unsigned — abs() is applied internally
     * @param string  $movementType movement_type from stock_movements
     * @param float   $unitCost     cost per unit (must be > 0 for a GL entry to post)
     * @param ?int    $projectId
     * @param int     $userId
     * @param string  $date         YYYY-MM-DD
     * @param string  $refNum       reference_number for the description
     */
    function postStockAdjustmentGl(
        PDO     $pdo,
        int     $movementId,
        float   $quantity,
        string  $movementType,
        float   $unitCost,
        ?int    $projectId,
        int     $userId,
        string  $date,
        string  $refNum
    ): void {
        $inventoryId = inventoryAccountId($pdo);
        $equityId    = takeOnEquityAccountId($pdo);

        if (!$inventoryId || !$equityId) return; // accounts not configured

        $amount = round(abs($quantity) * $unitCost, 2);
        if ($amount <= 0.0) return; // zero-value adjustment — nothing to post

        // Direction: UP types add to inventory; everything else (including 'set'
        // with a positive delta) subtracts; 'set' with negative delta also subtracts.
        if ($movementType === 'set') {
            $isUp = $quantity > 0;
        } else {
            $isUp = in_array($movementType, ['adjustment_in', 'found'], true);
        }

        $label = ucfirst(str_replace('_', ' ', $movementType));
        $desc  = "Stock adjustment $refNum — $label";

        $lines = $isUp
            ? [
                ['account_id' => $inventoryId, 'type' => 'debit',  'amount' => $amount],
                ['account_id' => $equityId,    'type' => 'credit', 'amount' => $amount],
              ]
            : [
                ['account_id' => $equityId,    'type' => 'debit',  'amount' => $amount],
                ['account_id' => $inventoryId, 'type' => 'credit', 'amount' => $amount],
              ];

        postLedgerEntry($pdo, $desc, $lines, $projectId, $movementId, 'stock_adjustment', $date, $userId);
    }
}

if (!function_exists('reverseStockAdjustmentGl')) {
    /**
     * Reverse any posted stock_adjustment journal entry for this movement.
     *
     * Reads the original posted lines, flips Dr↔Cr, and posts a contra-entry
     * with entity_type='stock_adjustment_void'. No-op if no posted entry exists
     * (safe to call on pre-feature adjustments that have no GL entry).
     *
     * Called inside the caller's transaction; commit/rollback is the caller's job.
     *
     * @param PDO    $pdo
     * @param int    $movementId  stock_movements.movement_id
     * @param int    $userId
     * @param string $refNum      reference_number for the description
     */
    function reverseStockAdjustmentGl(
        PDO    $pdo,
        int    $movementId,
        int    $userId,
        string $refNum
    ): void {
        $stmt = $pdo->prepare("
            SELECT je.project_id, jei.account_id, jei.type, jei.amount
              FROM journal_entries je
              JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
             WHERE je.entity_type = 'stock_adjustment'
               AND je.entity_id   = ?
               AND je.status      = 'posted'
             ORDER BY je.entry_id DESC
        ");
        $stmt->execute([$movementId]);
        $origLines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($origLines)) return; // nothing posted — skip (pre-feature row)

        $projectId     = $origLines[0]['project_id'] ? (int)$origLines[0]['project_id'] : null;
        $reversalLines = [];
        foreach ($origLines as $l) {
            $reversalLines[] = [
                'account_id' => (int)$l['account_id'],
                'type'       => $l['type'] === 'debit' ? 'credit' : 'debit',
                'amount'     => (float)$l['amount'],
            ];
        }

        postLedgerEntry(
            $pdo,
            "Stock adjustment $refNum — reversed",
            $reversalLines,
            $projectId,
            $movementId,
            'stock_adjustment_void',
            date('Y-m-d'),
            $userId
        );
    }
}
