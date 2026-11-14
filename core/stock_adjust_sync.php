<?php
/**
 * core/stock_adjust_sync.php
 * --------------------------
 * 2026-11-14 — keep the three stock figures in line after a STOCK ADJUSTMENT
 * (Edit product's Stock Quantity, Stock Adjustment page, bulk adjustment, editing
 * or deleting an adjustment, the app's stock adjust):
 *
 *   1. product_stocks.stock_quantity        — the shop's stock (what the till reads)
 *   2. products.stock_quantity/current_stock — the product's own copy = total of all shops
 *   3. product_batches.quantity_remaining   — what is left in the shop's batches (Smart POS)
 *
 * A sale, a return, a void and Receive Stock already move all three together. An
 * adjustment moved only (1) — Edit product did not even move (2) — so after
 * "correcting the opening count" in Edit product the shop showed 599 Coca cola, the
 * product 5 and its batches 5 (Piga Kazi Shop, 14 Nov 2026).
 *
 * Batches are reconciled from the STATE, not per adjustment: after the shop's stock
 * is written, the batches are brought to equal it — the difference added as one
 * "stock correction" batch at the adjustment's buying price, or taken off the batches
 * in the same order a sale uses (FEFO). So editing or deleting an adjustment later,
 * or running the repair twice, can never double-count. Smart POS only (Simple Mode),
 * where every stock intake writes a batch; Smart BMS keeps its sparse GRN batches as
 * they are. Services and combo products hold no batches and are skipped. The ledger
 * is not touched here — the adjustment's own posting already values it.
 *
 * No other includes — safe to load from tenant migrations.
 */

if (!function_exists('stockAdjustSmartPos')) {
    /** Simple Mode on, read straight from the database (get_setting() caches per request). */
    function stockAdjustSmartPos(PDO $pdo): bool
    {
        try {
            $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode' LIMIT 1");
            $s->execute();
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('stockAdjustSyncProductCopy')) {
    /** products.stock_quantity / current_stock = the total of the product's shop stock. */
    function stockAdjustSyncProductCopy(PDO $pdo, int $productId): void
    {
        if ($productId <= 0) return;
        $pdo->prepare("
            UPDATE products p
               SET p.stock_quantity = (SELECT COALESCE(SUM(ps.stock_quantity), 0) FROM product_stocks ps WHERE ps.product_id = p.product_id),
                   p.current_stock  = (SELECT COALESCE(SUM(ps.stock_quantity), 0) FROM product_stocks ps WHERE ps.product_id = p.product_id)
             WHERE p.product_id = ?
        ")->execute([$productId]);
    }
}

if (!function_exists('stockAdjustReconcileBatches')) {
    /**
     * Bring this shop's batches for the product to equal its shop stock (Smart POS only).
     *
     * @param float  $unitCost  buying price for an added correction batch (the adjustment's
     *                          price); <= 0 or above the selling price falls back to the shop's
     *                          latest batch price, else the product's buying price.
     * @param string $label     batch_number of an added correction batch (e.g. the ADJ reference)
     * @return array{action:string, quantity:float}  action = none | added | reduced | skipped
     */
    function stockAdjustReconcileBatches(PDO $pdo, int $productId, int $warehouseId, float $unitCost = 0.0, string $label = 'Stock correction'): array
    {
        $out = ['action' => 'skipped', 'quantity' => 0.0];
        if ($productId <= 0 || $warehouseId <= 0 || !stockAdjustSmartPos($pdo)) return $out;

        $p = $pdo->prepare("SELECT COALESCE(is_service,0) AS svc, COALESCE(is_combo,0) AS combo, COALESCE(cost_price,0) AS cost, COALESCE(selling_price,0) AS sell
                              FROM products WHERE product_id = ?");
        $p->execute([$productId]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);
        if (!$prod || (int)$prod['svc'] === 1 || (int)$prod['combo'] === 1) return $out;

        $s = $pdo->prepare("SELECT COALESCE(stock_quantity, 0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
        $s->execute([$productId, $warehouseId]);
        $stock = max(0.0, (float)$s->fetchColumn());

        $b = $pdo->prepare("SELECT batch_id, quantity_remaining, unit_cost FROM product_batches
                             WHERE product_id = ? AND warehouse_id = ?
                             ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, batch_id ASC
                             FOR UPDATE");
        $b->execute([$productId, $warehouseId]);
        $batches = $b->fetchAll(PDO::FETCH_ASSOC);
        $left = 0.0;
        foreach ($batches as $row) $left += max(0.0, (float)$row['quantity_remaining']);

        $diff = round($stock - $left, 3);
        if (abs($diff) < 0.0005) return ['action' => 'none', 'quantity' => 0.0];

        if ($diff > 0) {
            $sell = (float)$prod['sell'];
            $ok = fn(float $c) => $c > 0 && !($sell > 0 && $c > $sell);
            $cost = $unitCost;
            if (!$ok($cost)) {
                $cost = 0.0;
                for ($i = count($batches) - 1; $i >= 0; $i--) {          // latest batch first
                    if ($ok((float)$batches[$i]['unit_cost'])) { $cost = (float)$batches[$i]['unit_cost']; break; }
                }
                if ($cost <= 0 && $ok((float)$prod['cost'])) $cost = (float)$prod['cost'];
            }
            $pdo->prepare("
                INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$productId, $warehouseId, mb_substr($label, 0, 100), $diff, $diff, round($cost, 4), $sell > 0 ? $sell : null]);
            return ['action' => 'added', 'quantity' => $diff];
        }

        // Fewer in the shop than in the batches: take the difference off in sale (FEFO) order.
        $toTake = -$diff;
        $upd = $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining - ?, updated_at = NOW() WHERE batch_id = ?");
        foreach ($batches as $row) {
            if ($toTake <= 0.0005) break;
            $rem = (float)$row['quantity_remaining'];
            if ($rem <= 0) continue;
            $take = min($rem, $toTake);
            $upd->execute([round($take, 3), (int)$row['batch_id']]);
            $toTake = round($toTake - $take, 3);
        }
        return ['action' => 'reduced', 'quantity' => -$diff];
    }
}

if (!function_exists('stockAdjustAfterChange')) {
    /** Both steps, for an adjustment endpoint: the product's copy, then the shop's batches. */
    function stockAdjustAfterChange(PDO $pdo, int $productId, int $warehouseId, float $unitCost = 0.0, string $label = 'Stock correction'): array
    {
        stockAdjustSyncProductCopy($pdo, $productId);
        return stockAdjustReconcileBatches($pdo, $productId, $warehouseId, $unitCost, $label);
    }
}

if (!function_exists('stockAdjustRepairProductEdits')) {
    /**
     * One-off repair (migration 2026_11_14_product_edit_stock_sync): products whose shop stock
     * was changed through Edit product before this fix. Criteria-based — only shops that have
     * a "Stock adjustment via product edit" movement for the product; Smart POS only for the
     * batches. Re-running changes nothing (state-based). Returns counts.
     */
    function stockAdjustRepairProductEdits(PDO $pdo): array
    {
        $out = ['products' => 0, 'batches_added' => 0, 'batches_reduced' => 0];
        $rows = $pdo->query("
            SELECT DISTINCT sm.product_id, sm.warehouse_id
              FROM stock_movements sm
             WHERE sm.reason = 'Stock adjustment via product edit' AND sm.warehouse_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach ($rows as $r) {
            $pid = (int)$r['product_id']; $wid = (int)$r['warehouse_id'];
            if (!isset($seen[$pid])) { stockAdjustSyncProductCopy($pdo, $pid); $seen[$pid] = true; $out['products']++; }
            $c = $pdo->prepare("SELECT COALESCE(cost_price, 0) FROM products WHERE product_id = ?");
            $c->execute([$pid]);
            $res = stockAdjustReconcileBatches($pdo, $pid, $wid, (float)$c->fetchColumn(), 'Stock correction');
            if ($res['action'] === 'added')   $out['batches_added']++;
            if ($res['action'] === 'reduced') $out['batches_reduced']++;
        }
        return $out;
    }
}
