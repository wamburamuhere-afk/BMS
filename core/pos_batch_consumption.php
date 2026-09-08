<?php
/**
 * core/pos_batch_consumption.php
 *
 * Phase 17b (pos_upgrade_plan.md §8) — FEFO (First-Expired-First-Out)
 * consumption of product_batches at POS sale time, and its exact reversal on
 * void/return. Extracted for independent testability, same reasoning as
 * core/pos_override_guard.php and core/pos_price_groups.php.
 *
 * A product with zero rows in product_batches for the sale's warehouse is
 * NOT batch-tracked — these functions are no-ops for it, so a plain product
 * sells exactly as it did before this phase (fully backward compatible).
 * product_stocks stays the authoritative "how much is in this warehouse"
 * total either way; product_batches is an additional bookkeeping layer for
 * FEFO/expiry, not a replacement.
 */

/**
 * Consume up to $qty units from the product's open batches in this
 * warehouse, oldest-expiry-first, row-locked (FOR UPDATE — caller must be
 * inside a transaction) so two concurrent sales can't oversell the same
 * batch. Records each consumed slice in pos_sale_item_batches.
 *
 * If the batches on hand total LESS than $qty (e.g. product_stocks and
 * product_batches have drifted from an untracked adjustment), consumes
 * whatever is available and stops — never blocks or errors the sale; the
 * real stock-availability check already happened against product_stocks
 * before this is called.
 *
 * @return array{consumed: float, lines: array} lines = [[batch_id, quantity, unit_cost], ...]
 */
function consumeFefoBatches(PDO $pdo, int $productId, int $warehouseId, float $qty, int $saleItemId): array
{
    $result = ['consumed' => 0.0, 'lines' => []];
    if ($qty <= 0 || $productId <= 0 || $warehouseId <= 0) {
        return $result;
    }

    $stmt = $pdo->prepare("
        SELECT batch_id, quantity_remaining, unit_cost
        FROM product_batches
        WHERE product_id = ? AND warehouse_id = ? AND quantity_remaining > 0
        ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, batch_id ASC
        FOR UPDATE
    ");
    $stmt->execute([$productId, $warehouseId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($batches)) {
        return $result; // not a batch-tracked product in this warehouse
    }

    $decrementStmt = $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining - ?, updated_at = NOW() WHERE batch_id = ?");
    $linkStmt = $pdo->prepare("INSERT INTO pos_sale_item_batches (sale_item_id, batch_id, quantity) VALUES (?, ?, ?)");

    $remaining = $qty;
    foreach ($batches as $b) {
        if ($remaining <= 0.0001) break;
        $take = min($remaining, (float)$b['quantity_remaining']);
        if ($take <= 0) continue;

        $decrementStmt->execute([$take, $b['batch_id']]);
        $linkStmt->execute([$saleItemId, $b['batch_id'], $take]);

        $result['lines'][] = ['batch_id' => (int)$b['batch_id'], 'quantity' => $take, 'unit_cost' => (float)$b['unit_cost']];
        $result['consumed'] += $take;
        $remaining -= $take;
    }

    return $result;
}

/**
 * Reverse a sale line's batch consumption (void or return) — restores
 * quantity_remaining to the exact batch(es) it was drawn from, via the
 * pos_sale_item_batches link, then removes the link rows (a repeat
 * void/return of the same sale_item_id is then a no-op, same idempotency
 * guarantee the rest of §3 Phase 1/7 already gives void/return).
 *
 * @param int $saleItemId
 * @param float|null $limitQty  null = reverse the full original consumption
 *                              (void); a number = reverse only up to this
 *                              much (a partial return of fewer units than
 *                              were originally sold on that line).
 * @return float total quantity actually restored
 */
function reverseFefoBatchConsumption(PDO $pdo, int $saleItemId, ?float $limitQty = null): float
{
    $stmt = $pdo->prepare("SELECT id, batch_id, quantity FROM pos_sale_item_batches WHERE sale_item_id = ? ORDER BY id ASC");
    $stmt->execute([$saleItemId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($links)) return 0.0;

    $restoreStmt = $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining + ?, updated_at = NOW() WHERE batch_id = ?");
    $deleteStmt  = $pdo->prepare("DELETE FROM pos_sale_item_batches WHERE id = ?");
    $shrinkStmt  = $pdo->prepare("UPDATE pos_sale_item_batches SET quantity = quantity - ? WHERE id = ?");

    $remainingToRestore = $limitQty;
    $totalRestored = 0.0;

    foreach ($links as $link) {
        $qtyHere = (float)$link['quantity'];
        $restoreQty = ($remainingToRestore === null) ? $qtyHere : min($qtyHere, max(0, $remainingToRestore));
        if ($restoreQty <= 0) continue;

        $restoreStmt->execute([$restoreQty, $link['batch_id']]);
        $totalRestored += $restoreQty;

        if (abs($restoreQty - $qtyHere) < 0.0001) {
            $deleteStmt->execute([$link['id']]);
        } else {
            $shrinkStmt->execute([$restoreQty, $link['id']]);
        }

        if ($remainingToRestore !== null) {
            $remainingToRestore -= $restoreQty;
            if ($remainingToRestore <= 0.0001) break;
        }
    }

    return $totalRestored;
}
