<?php
/**
 * core/stock_intake.php
 *
 * Shared "stock genuinely entered a warehouse" logic — extracted from
 * api/approve_grn.php (Phase 17/26) so GRN approval and the POS Restock
 * Product shortcut (pos_upgrade_plan.md-style quick intake, not a GRN) create
 * batches identically, from one source of truth. Neither caller's own
 * behavior changes: this is the same code that used to live inline in
 * approve_grn.php, just callable from a second place now.
 *
 * Does NOT open/commit a transaction — the caller must already be inside one
 * (matches approve_grn.php's existing convention).
 */

require_once __DIR__ . '/stock_ledger.php';

if (!function_exists('receiveProductBatch')) {
    /**
     * Record one line of stock arriving in a warehouse: bumps products/
     * product_stocks, appends a stock_movements row, and (when the caller asks
     * for one via write_batch) a product_batches row.
     *
     * $line keys:
     *   product_id       int      required
     *   warehouse_id     int      required
     *   quantity         float    required, > 0
     *   unit_cost        float    default 0 — batch's buying price
     *   write_batch      bool     default false — GRN stays sparse (only when
     *                             batch_number/expiry_date given); the Restock
     *                             modal always passes true, since every batch
     *                             it creates needs its own price history.
     *   batch_number     ?string
     *   expiry_date      ?string  Y-m-d
     *   wholesale_price  ?float   this batch's wholesale price at intake
     *   selling_price    ?float   this batch's retail price at intake
     *   receipt_id       ?int     GRN link; null for a non-GRN intake
     *   reserve_quantity float    default 0 (project-bound GRNs reserve stock)
     *   project_id       ?int
     *   movement_type    string   default 'purchase_in' (stock_movements ENUM)
     *   reference_type   string   required (stock_movements ENUM)
     *   reference_id     int      required
     *   reference_number ?string
     *   movement_date    string   default today (Y-m-d)
     *   created_by       int      required
     *   notes            ?string
     *   serials          string[] physical unit serials (GRN's track_serials path)
     *
     * @return array{batch_id: ?int, movement_id: int}
     */
    function receiveProductBatch(PDO $pdo, array $line): array
    {
        $productId   = (int)($line['product_id'] ?? 0);
        $warehouseId = (int)($line['warehouse_id'] ?? 0);
        $qty         = (float)($line['quantity'] ?? 0);
        if ($productId <= 0 || $warehouseId <= 0 || $qty <= 0) {
            throw new InvalidArgumentException('receiveProductBatch: product_id, warehouse_id and quantity are required');
        }

        $unitCost   = (float)($line['unit_cost'] ?? 0);
        $reserveQty = (float)($line['reserve_quantity'] ?? 0);

        $bumpProduct = $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, stock_quantity = stock_quantity + ? WHERE product_id = ?");
        $bumpProduct->execute([$qty, $qty, $productId]);

        $checkStock = $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
        $checkStock->execute([$productId, $warehouseId]);
        $stockId = $checkStock->fetchColumn();
        if ($stockId) {
            $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity, 0) + ?, reserved_quantity = IFNULL(reserved_quantity, 0) + ?, last_updated = NOW() WHERE stock_id = ?")
                ->execute([$qty, $reserveQty, $stockId]);
        } else {
            $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$productId, $warehouseId, $qty, $reserveQty]);
        }

        $movementId = recordStockMovement($pdo, [
            'product_id'       => $productId,
            'warehouse_id'     => $warehouseId,
            'project_id'       => $line['project_id'] ?? null,
            'movement_type'    => $line['movement_type'] ?? 'purchase_in',
            'quantity'         => $qty,
            'unit_cost'        => $unitCost,
            'reference_id'     => (int)($line['reference_id'] ?? 0),
            'reference_type'   => $line['reference_type'] ?? 'manual',
            'reference_number' => $line['reference_number'] ?? null,
            'movement_date'    => $line['movement_date'] ?? date('Y-m-d'),
            'created_by'       => (int)($line['created_by'] ?? ($_SESSION['user_id'] ?? 0)),
            'notes'            => $line['notes'] ?? null,
        ]);

        $batchId = null;
        if (!empty($line['write_batch'])) {
            $batchNumber = trim((string)($line['batch_number'] ?? ''));
            $expiryDate  = $line['expiry_date'] ?? null;
            $insertBatch = $pdo->prepare("
                INSERT INTO product_batches
                    (product_id, warehouse_id, batch_number, expiry_date, quantity_received, quantity_remaining, unit_cost, wholesale_price, selling_price, receipt_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insertBatch->execute([
                $productId, $warehouseId,
                $batchNumber !== '' ? $batchNumber : null,
                !empty($expiryDate) ? $expiryDate : null,
                $qty, $qty,
                $unitCost,
                isset($line['wholesale_price']) ? (float)$line['wholesale_price'] : null,
                isset($line['selling_price']) ? (float)$line['selling_price'] : null,
                $line['receipt_id'] ?? null,
            ]);
            $batchId = (int)$pdo->lastInsertId();
        }

        if (!empty($line['serials'])) {
            $insertSerial = $pdo->prepare("
                INSERT IGNORE INTO product_serials (product_id, warehouse_id, serial_number, status, receipt_id, created_at)
                VALUES (?, ?, ?, 'in_stock', ?, NOW())
            ");
            foreach ($line['serials'] as $sn) {
                $insertSerial->execute([$productId, $warehouseId, $sn, $line['receipt_id'] ?? null]);
            }
        }

        return ['batch_id' => $batchId, 'movement_id' => $movementId];
    }
}
