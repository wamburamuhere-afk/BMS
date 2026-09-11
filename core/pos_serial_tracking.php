<?php
/**
 * core/pos_serial_tracking.php
 *
 * Phase 26 (pos_upgrade_plan.md §9) — serial/IMEI-level stock tracking at
 * POS sale time, and its exact reversal on void/return. Modeled directly on
 * core/pos_batch_consumption.php's shape, generalized to single units
 * (a serial is qty-always-1, not a decrementing pool).
 *
 * products.track_serials defaults to 0 — these functions are only ever
 * called for a product explicitly flagged track_serials=1, so a plain
 * product's sale/void/return path is completely untouched by this phase.
 */

/**
 * Consume one specific serial number for this product/warehouse — row-locked
 * (FOR UPDATE — caller must be inside a transaction) so two concurrent sales
 * can't both sell the same physical unit. Flips the serial to 'sold' and
 * links it to the sale line.
 *
 * @return int|null the consumed serial_id, or null if that serial number
 *                   isn't currently in_stock for this product/warehouse
 *                   (already sold, unknown, or belongs to another warehouse).
 */
function consumeSerial(PDO $pdo, int $productId, int $warehouseId, string $serialNumber, int $saleItemId): ?int
{
    $serialNumber = trim($serialNumber);
    if ($serialNumber === '' || $productId <= 0 || $warehouseId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT serial_id FROM product_serials
        WHERE product_id = ? AND warehouse_id = ? AND serial_number = ? AND status = 'in_stock'
        FOR UPDATE
    ");
    $stmt->execute([$productId, $warehouseId, $serialNumber]);
    $serialId = $stmt->fetchColumn();
    if (!$serialId) {
        return null;
    }
    $serialId = (int)$serialId;

    $pdo->prepare("UPDATE product_serials SET status = 'sold', sale_item_id = ?, updated_at = NOW() WHERE serial_id = ?")
        ->execute([$saleItemId, $serialId]);
    $pdo->prepare("INSERT INTO pos_sale_item_serials (sale_item_id, serial_id) VALUES (?, ?)")
        ->execute([$saleItemId, $serialId]);

    return $serialId;
}

/**
 * Consume a list of specific serial numbers for one sale line (a
 * serial-tracked line's quantity is exactly count($serialNumbers)).
 *
 * @return array the subset of $serialNumbers that were actually consumed —
 *               the caller must compare count() against the input and treat
 *               a shortfall as a hard failure (unlike FEFO batches, a
 *               specific serial that isn't available is a real double-sell
 *               risk, not just bookkeeping drift, so callers must not
 *               silently under-sell here).
 */
function consumeSerials(PDO $pdo, int $productId, int $warehouseId, array $serialNumbers, int $saleItemId): array
{
    $consumed = [];
    foreach ($serialNumbers as $sn) {
        $sn = trim((string)$sn);
        if ($sn === '') continue;
        if (consumeSerial($pdo, $productId, $warehouseId, $sn, $saleItemId) !== null) {
            $consumed[] = $sn;
        }
    }
    return $consumed;
}

/**
 * Reverse a sale line's serial consumption (void or return) — restores every
 * linked serial to 'in_stock' and removes the link row, mirroring
 * reverseFefoBatchConsumption()'s idempotency guarantee (a repeat void/return
 * of the same sale_item_id is then a no-op).
 *
 * @param int|null $limitCount  null = reverse every serial linked to this
 *                              line (void); a number = reverse only up to
 *                              this many (a partial return of fewer serials
 *                              than were originally sold on that line).
 *                              Known limitation: a partial return restores
 *                              the earliest-linked serials first rather than
 *                              caller-chosen ones — the create_return.php UI
 *                              does not yet let a cashier pick which specific
 *                              unit physically came back. Documented rather
 *                              than guessed at, matching this plan's existing
 *                              convention for bounded edge cases (see Phase
 *                              11's loyalty-reversal note).
 * @return int number of serials actually restored
 */
function reverseSerialsForSaleItem(PDO $pdo, int $saleItemId, ?int $limitCount = null): int
{
    $stmt = $pdo->prepare("SELECT id, serial_id FROM pos_sale_item_serials WHERE sale_item_id = ? ORDER BY id ASC");
    $stmt->execute([$saleItemId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($links)) {
        return 0;
    }
    if ($limitCount !== null) {
        $links = array_slice($links, 0, max(0, $limitCount));
    }

    $restoreStmt = $pdo->prepare("UPDATE product_serials SET status = 'in_stock', sale_item_id = NULL, updated_at = NOW() WHERE serial_id = ?");
    $deleteStmt  = $pdo->prepare("DELETE FROM pos_sale_item_serials WHERE id = ?");

    $restored = 0;
    foreach ($links as $link) {
        $restoreStmt->execute([$link['serial_id']]);
        $deleteStmt->execute([$link['id']]);
        $restored++;
    }
    return $restored;
}
