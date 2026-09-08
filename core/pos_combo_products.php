<?php
/**
 * core/pos_combo_products.php
 *
 * Phase 23 (pos_upgrade_plan.md §8) — combo/bundle products. Reuses the
 * existing product_assembly_components table (see the migration's own
 * comment for why that's safe — is_combo is the new gate, no existing
 * service/NIP data is touched). A combo product itself carries no stock of
 * its own; selling it decrements each COMPONENT's stock instead, atomically
 * — either every component has enough stock, or the sale is blocked before
 * anything is touched, never a partial decrement.
 */

/**
 * @return array [{component_product_id, product_name, unit, qty_per_unit}, ...]
 */
function getComboComponents(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT ac.component_product_id, p.product_name, ac.unit, ac.qty_per_unit
        FROM product_assembly_components ac
        JOIN products p ON p.product_id = ac.component_product_id
        WHERE ac.parent_product_id = ?
        ORDER BY p.product_name ASC
    ");
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['component_product_id'] = (int)$r['component_product_id'];
        $r['qty_per_unit'] = (float)$r['qty_per_unit'];
    }
    return $rows;
}

/**
 * Verify every component has enough available stock for $qtySold units of
 * the combo, in the given warehouse. Returns an empty array when fully
 * available, or a list of {component_product_id, product_name, needed,
 * available} for whichever components fall short — the caller should block
 * the WHOLE sale (not just this line) when this is non-empty, matching how
 * a normal line's own insufficient-stock check already works.
 */
function checkComboAvailability(PDO $pdo, int $productId, float $qtySold, int $warehouseId): array
{
    $shortfalls = [];
    foreach (getComboComponents($pdo, $productId) as $c) {
        $needed = $c['qty_per_unit'] * $qtySold;
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(stock_quantity - IFNULL(reserved_quantity,0)),0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
        $stmt->execute([$c['component_product_id'], $warehouseId]);
        $available = (float)$stmt->fetchColumn();
        if ($available < $needed - 0.0001) {
            $shortfalls[] = [
                'component_product_id' => $c['component_product_id'],
                'product_name' => $c['product_name'],
                'needed' => $needed,
                'available' => $available,
            ];
        }
    }
    return $shortfalls;
}

/**
 * Decrement every component's stock for $qtySold units of the combo.
 * Caller must have already confirmed availability via checkComboAvailability()
 * — this function does not re-check (it runs inside the same DB transaction
 * as the rest of the sale, so a failure here rolls back the whole sale
 * anyway; the pre-check exists purely to give a clear error BEFORE any
 * writes, not as the only safety net).
 */
function consumeComboComponents(PDO $pdo, int $productId, float $qtySold, int $warehouseId, ?int $projectId, int $saleId, string $receiptNumber, int $userId): void
{
    require_once __DIR__ . '/stock_ledger.php';

    $bumpGlobal = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ?, current_stock = current_stock - ? WHERE product_id = ?");
    $bumpWh = $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity,0) - ? WHERE product_id = ? AND warehouse_id = ?");

    foreach (getComboComponents($pdo, $productId) as $c) {
        $qty = $c['qty_per_unit'] * $qtySold;
        if ($qty <= 0) continue;
        $bumpGlobal->execute([$qty, $qty, $c['component_product_id']]);
        $bumpWh->execute([$qty, $c['component_product_id'], $warehouseId]);
        recordStockMovement($pdo, [
            'product_id'       => $c['component_product_id'],
            'warehouse_id'     => $warehouseId,
            'project_id'       => $projectId,
            'movement_type'    => 'sale_out',
            'quantity'         => $qty,
            'reference_id'     => $saleId,
            'reference_type'   => 'pos_sale',
            'reference_number' => $receiptNumber,
            'created_by'       => $userId,
            'notes'            => "Combo component of POS Sale #$receiptNumber",
        ]);
    }
}

/**
 * Reverse consumeComboComponents() — void (full) or return (partial, capped
 * at $qtyToRestore) of a combo line. Mirrors reverseFefoBatchConsumption()'s
 * "restore into the exact same components" principle, applied to combos
 * instead of batches — a combo void/return is one atomic unit, matching how
 * a regular sale line already reverses atomically.
 */
function reverseComboComponents(PDO $pdo, int $productId, float $qtyToRestore, int $warehouseId, ?int $projectId, int $referenceId, string $referenceNumber, int $userId, string $referenceType = 'return'): void
{
    // NOTE (Phase 23, pos_upgrade_plan.md §8): stock_movements.reference_type
    // is a strict ENUM — 'return' is the only valid value for a reversal
    // (confirmed by reading the live schema). void_sale.php/create_return.php
    // pass 'pos_void'/'pos_return' to their OWN recordStockMovement() calls
    // for the regular (non-combo) line reversal, which are NOT in this enum
    // — a pre-existing bug found while building this phase, out of scope to
    // fix here (not touched by Phase 23's changes), flagged separately.
    require_once __DIR__ . '/stock_ledger.php';

    $bumpGlobal = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, current_stock = current_stock + ? WHERE product_id = ?");
    $bumpWh = $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity,0) + ? WHERE product_id = ? AND warehouse_id = ?");

    foreach (getComboComponents($pdo, $productId) as $c) {
        $qty = $c['qty_per_unit'] * $qtyToRestore;
        if ($qty <= 0) continue;
        $bumpGlobal->execute([$qty, $qty, $c['component_product_id']]);
        $bumpWh->execute([$qty, $c['component_product_id'], $warehouseId]);
        recordStockMovement($pdo, [
            'product_id'       => $c['component_product_id'],
            'warehouse_id'     => $warehouseId,
            'project_id'       => $projectId,
            'movement_type'    => 'return_in',
            'quantity'         => $qty,
            'reference_id'     => $referenceId,
            'reference_type'   => $referenceType,
            'reference_number' => $referenceNumber,
            'created_by'       => $userId,
            'notes'            => "Combo component reversal for $referenceNumber",
        ]);
    }
}
