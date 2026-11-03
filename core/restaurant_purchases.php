<?php
/**
 * core/restaurant_purchases.php — buying stock for a restaurant (restaurant focus pass, 2026-11-02).
 *
 * What a restaurant counts: drinks and ingredients. The existing retail "Receive Stock" cannot take an
 * ingredient (it demands a selling price) and never updates an item's cost price, so cost of sales and
 * profit per plate went stale as soon as a supplier changed a price. This does what a restaurant needs:
 *
 *   • an ingredient (no selling price) can be received;
 *   • the item's COST follows what you actually pay — weighted average: (on-hand × old cost + bought × new cost)
 *     ÷ (on-hand + bought) — so every sale's cost of goods and the dashboard's profit per plate move with it;
 *   • the purchase posts like Receive Stock does: a batch (first-expiry-first-out, optional expiry date) and
 *     Dr Inventory / Cr the account it was paid from — one ledger, no new accounting rules.
 *
 * Retail Receive Stock (api/pos/quick_restock.php) is untouched.
 *
 * Pay later (2026-11-02): a purchase is PAID by default. It can be PARTLY PAID or on CREDIT — then the unpaid part
 * is owed to the supplier as a supplier bill (core/restaurant_supplier_credit.php), paid off later.
 */
require_once __DIR__ . '/restaurant_supplier_credit.php';
require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/stock_intake.php';
require_once __DIR__ . '/payment_source.php';
require_once __DIR__ . '/gl_accounts.php';
require_once __DIR__ . '/code_generator.php';
require_once __DIR__ . '/sales_posting.php';
require_once __DIR__ . '/pos_nav.php';

if (!function_exists('restaurantStockItemSql')) {
    /**
     * SQL condition (alias $a) for "an item a restaurant counts": an active stock item (not a service / dish) that is
     * a bought Menu item, OR an ingredient — no selling price of its own, or used in a recipe.
     * Ordinary retail stock (it has a selling price and is not on the Menu or in a recipe) is NOT included.
     */
    function restaurantStockItemSql(string $a = 'p'): string
    {
        return "($a.status = 'active' AND COALESCE($a.is_service, 0) = 0 AND COALESCE($a.track_inventory, 1) = 1
                 AND COALESCE($a.is_combo, 0) = 0
                 AND ($a.is_menu_item = 1
                      OR COALESCE($a.selling_price, 0) = 0
                      OR EXISTS (SELECT 1 FROM product_assembly_components rac WHERE rac.component_product_id = $a.product_id)))";
    }
}

if (!function_exists('restaurantWeightedAvgCost')) {
    /**
     * New cost per unit after buying $newQty at $newUnitCost when $oldQty were on hand at $oldCost.
     * Negative stock (an ingredient used beyond what was recorded) counts as nothing on hand.
     */
    function restaurantWeightedAvgCost(float $oldQty, float $oldCost, float $newQty, float $newUnitCost): float
    {
        $oldQty = max(0.0, $oldQty);
        if ($newQty <= 0) return round($oldCost, 2);
        $total = $oldQty + $newQty;
        if ($total <= 0) return round($newUnitCost, 2);
        return round((($oldQty * $oldCost) + ($newQty * $newUnitCost)) / $total, 2);
    }
}

if (!function_exists('restaurantStockOnHand')) {
    /** Total on hand for an item across the shops (what its single cost price stands for). */
    function restaurantStockOnHand(PDO $pdo, int $productId): float
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(stock_quantity), 0) FROM product_stocks WHERE product_id = ?");
        $st->execute([$productId]);
        return (float)$st->fetchColumn();
    }
}

if (!function_exists('restaurantBuyStock')) {
    /**
     * Buy stock for a restaurant shop. Throws Exception with a user-facing message on any problem; changes nothing then.
     *
     * @param array $in product_id, warehouse_id, quantity, unit_cost, [date], [supplier_id], [expiry_date], [paid_from_account_id],
     *                  [payment] 'paid' (default) | 'partial' | 'credit', [amount_paid] (partial), [due_date]
     * @return array{product_id:int,product_name:string,quantity:float,unit_cost:float,total:float,old_cost:float,new_cost:float,on_hand_before:float,on_hand_after:float,reference:string,transaction_id:?int,batch_id:?int,movement_id:?int}
     */
    function restaurantBuyStock(PDO $pdo, array $in, int $userId): array
    {
        $productId   = (int)($in['product_id'] ?? 0);
        $warehouseId = (int)($in['warehouse_id'] ?? 0);
        $qty         = round((float)($in['quantity'] ?? 0), 3);
        $unitCost    = round((float)($in['unit_cost'] ?? 0), 2);
        // "Total paid" — for what is not bought per unit (a goat, a crate, a sack): the price for one is worked out from it.
        $totalPaid   = round((float)($in['total_paid'] ?? 0), 2);
        $sellIn      = (isset($in['selling_price']) && $in['selling_price'] !== '' && $in['selling_price'] !== null) ? round((float)$in['selling_price'], 2) : null;
        $date        = !empty($in['date']) ? (string)$in['date'] : date('Y-m-d');
        $supplierId  = (int)($in['supplier_id'] ?? 0);
        $expiry      = !empty($in['expiry_date']) ? (string)$in['expiry_date'] : null;
        $paidFrom    = (int)($in['paid_from_account_id'] ?? 0);

        if (!restaurantShopActive($pdo, $warehouseId)) throw new Exception(t('This shop is not set up as a restaurant.'));
        if ($productId <= 0)  throw new Exception(t('Select an item.'));
        if ($qty <= 0)        throw new Exception(t('Quantity must be greater than zero.'));
        if ($unitCost < 0)    throw new Exception(t('Prices cannot be negative.'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new Exception(t('Enter a valid date.'));
        if ($date > date('Y-m-d')) throw new Exception(t('The date cannot be in the future.'));
        if ($expiry !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || strtotime($expiry) === false)) throw new Exception(t('Enter a valid date.'));
        if ($expiry !== null && $expiry < $date) throw new Exception(t('The expiry date cannot be before the purchase date.'));

        $st = $pdo->prepare("SELECT p.product_id, p.product_name, p.selling_price, p.is_menu_item, COALESCE(p.cost_price, 0) AS cost_price
                               FROM products p WHERE p.product_id = ? AND " . restaurantStockItemSql('p'));
        $st->execute([$productId]);
        $prod = $st->fetch(PDO::FETCH_ASSOC);
        if (!$prod) throw new Exception(t('Pick a drink or an ingredient — an item you count.'));

        if ($totalPaid < 0) throw new Exception(t('Prices cannot be negative.'));
        if ($totalPaid > 0) {                       // the total is what was paid; the price for one follows from it
            $total    = $totalPaid;
            $unitCost = round($totalPaid / $qty, 4);
        } else {
            $total = round($qty * $unitCost, 2);
        }
        // A price you sell at may be changed here (like the retail Receive Stock) — only for an item sold on the Menu.
        $newSelling = null;
        if ($sellIn !== null && (int)$prod['is_menu_item'] === 1) {
            if ($sellIn <= 0) throw new Exception(t('Selling price must be greater than zero.'));
            if (abs($sellIn - (float)$prod['selling_price']) > 0.004) $newSelling = $sellIn;
        }
        // Paid (default), partly paid or on credit — what is not paid now is owed to the supplier.
        $split = restaurantPaymentSplit($total, (string)($in['payment'] ?? 'paid'), $in['amount_paid'] ?? 0);
        if ($split['owed'] > 0 && $supplierId <= 0) throw new Exception(t('Choose the supplier you owe.'));
        if ($total > 0 && ($split['paid'] > 0 || $split['owed'] > 0)) {
            if ($paidFrom <= 0 && posSimpleModeEnabled()) $paidFrom = (int)(posReceiptAccountId($pdo, 'cash') ?? 0);
            if ($paidFrom <= 0) throw new Exception(t('Select where this was paid from.'));
            $valid = false;
            foreach (cashBankAccounts($pdo) as $a) { if ((int)$a['account_id'] === $paidFrom) { $valid = true; break; } }
            if (!$valid) throw new Exception(t('Invalid Paid-From account.'));
            $inventoryAcc = inventoryAccountId($pdo);
            if (!$inventoryAcc) throw new Exception(t('Inventory account is not configured — contact your administrator before restocking.'));
        }
        if ($supplierId > 0) {
            if (!canView('suppliers') && !restaurantCanBuy()) throw new Exception(t('Permission denied'));
            restaurantCheckSupplier($pdo, $supplierId);
        }

        $onHand  = restaurantStockOnHand($pdo, $productId);
        $oldCost = (float)$prod['cost_price'];
        $newCost = restaurantWeightedAvgCost($onHand, $oldCost, $qty, $unitCost);
        $ref     = nextCode($pdo, 'ADJ');

        $txn = null;
        $pdo->beginTransaction();
        try {
            $intake = receiveProductBatch($pdo, [
                'product_id'       => $productId,
                'warehouse_id'     => $warehouseId,
                'quantity'         => $qty,
                'unit_cost'        => $unitCost,
                'supplier_id'      => $supplierId > 0 ? $supplierId : null,
                'write_batch'      => true,
                'expiry_date'      => $expiry,
                'selling_price'    => $newSelling ?? (float)$prod['selling_price'],
                'receipt_id'       => null,
                'movement_type'    => 'adjustment_in',
                'reference_type'   => 'stock_adjustment',
                'reference_id'     => null,
                'reference_number' => $ref,
                'movement_date'    => $date,
                'created_by'       => $userId,
                'notes'            => "Restaurant purchase: {$prod['product_name']} x{$qty}",
            ]);
            // The cost follows what is actually paid (weighted average); purchase_price keeps the last price paid.
            $pdo->prepare("UPDATE products SET cost_price = ?, purchase_price = ?, updated_by = ? WHERE product_id = ?")
                ->execute([$newCost, $unitCost, $userId, $productId]);
            if ($newSelling !== null) {
                $pdo->prepare("UPDATE products SET selling_price = ? WHERE product_id = ?")->execute([$newSelling, $productId]);
            }
            $bill = null;
            if ($total > 0 && $split['owed'] > 0) {
                // Partly paid / credit: a supplier bill for the whole purchase (Dr Inventory / Cr Payable) and the part paid now.
                $bill = restaurantRecordSupplierBill($pdo, [
                    'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId, 'date' => $date, 'total' => $total,
                    'paid' => $split['paid'], 'ref' => $ref, 'due_date' => $in['due_date'] ?? null, 'cash_account_id' => $paidFrom,
                    'notes' => "{$prod['product_name']} x{$qty}",
                ], $userId);
            } elseif ($total > 0) {
                $txn = postOutflow($pdo, 'stock_restock', $paidFrom, $inventoryAcc, $total, $date, $ref,
                    "Restaurant purchase: {$prod['product_name']} x{$qty} @ " . number_format($unitCost, 2));
                if (!$txn) throw new Exception(t('Could not post the payment to the ledger — please check your Chart of Accounts setup.'));
            }
            logActivity($pdo, $userId, "Restaurant purchase {$prod['product_name']} x{$qty} ($ref) — cost " . number_format($oldCost, 2) . ' → ' . number_format($newCost, 2));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['product_id' => $productId, 'product_name' => $prod['product_name'], 'quantity' => $qty, 'unit_cost' => $unitCost, 'total' => $total,
                'old_cost' => round($oldCost, 2), 'new_cost' => $newCost, 'selling_price' => $newSelling ?? (float)$prod['selling_price'], 'on_hand_before' => $onHand, 'on_hand_after' => $onHand + $qty,
                'reference' => $ref, 'transaction_id' => $txn ?: null,
                'payment' => $split['payment'], 'paid_now' => $split['paid'], 'owed' => $split['owed'], 'bill_id' => $bill['invoice_id'] ?? null, 'batch_id' => $intake['batch_id'] ?? null, 'movement_id' => $intake['movement_id'] ?? null];
    }
}
