<?php
/**
 * core/stock_ledger.php
 *
 * Single source of truth for writing `stock_movements` rows.
 *
 * Every module that moves stock should call recordStockMovement() instead of
 * hand-writing its own INSERT, so that movement_type, reference_number,
 * total_cost and the running balance (stock_before / stock_after) are ALWAYS
 * populated and consistent. This is what prevents "OTHER" rows, blank
 * references and 0.00 values from ever being created again.
 *
 * The running balance is computed from the ledger itself (sum of prior signed
 * movements for the product / warehouse), so it is correct regardless of
 * whether the caller updates physical stock before or after logging.
 */

if (!function_exists('recordStockMovement')) {

    /** Canonical IN-direction movement types (everything else is treated as OUT). */
    function stockMovementInTypes(): array {
        return [
            'purchase_in', 'adjustment_in', 'transfer_in', 'return_in',
            'production_in', 'found', 'correction',
        ];
    }

    /** True when the movement type adds stock. */
    function stockMovementIsInbound(string $type): bool {
        return in_array($type, stockMovementInTypes(), true);
    }

    /**
     * Insert one fully-populated stock_movements row and return its id.
     *
     * Required keys:  product_id, movement_type, quantity, created_by
     * Optional keys:  warehouse_id, project_id, unit, unit_cost, total_cost,
     *                 reference_id, reference_type, reference_number,
     *                 movement_date, reason, notes, stock_before, stock_after
     *
     * - unit_cost   defaults to products.cost_price
     * - total_cost  defaults to quantity * unit_cost
     * - stock_before defaults to the product's current ledger balance
     *   (per warehouse when warehouse_id is given, else global)
     * - stock_after  defaults to stock_before +/- quantity by direction
     */
    function recordStockMovement(PDO $pdo, array $m): int {
        $product_id = (int)($m['product_id'] ?? 0);
        $type       = trim((string)($m['movement_type'] ?? ''));
        $qty        = (float)($m['quantity'] ?? 0);
        $created_by = (int)($m['created_by'] ?? ($_SESSION['user_id'] ?? 0));

        if ($product_id <= 0 || $type === '') {
            throw new InvalidArgumentException('recordStockMovement: product_id and movement_type are required');
        }

        $warehouse_id = (isset($m['warehouse_id']) && $m['warehouse_id'] !== '' && $m['warehouse_id'] !== null) ? (int)$m['warehouse_id'] : null;
        $project_id   = (isset($m['project_id'])   && $m['project_id']   !== '' && $m['project_id']   !== null) ? (int)$m['project_id']   : null;
        $reference_id = (isset($m['reference_id']) && $m['reference_id'] !== '' && $m['reference_id'] !== null) ? (int)$m['reference_id'] : null;
        $unit             = $m['unit'] ?? 'pcs';
        $reference_type   = $m['reference_type']   ?? null;
        $reference_number = $m['reference_number'] ?? null;
        $movement_date    = $m['movement_date']    ?? date('Y-m-d');
        $reason           = $m['reason'] ?? null;
        $notes            = $m['notes']  ?? null;

        // Unit cost → product cost_price fallback.
        if (isset($m['unit_cost']) && $m['unit_cost'] !== '' && $m['unit_cost'] !== null) {
            $unit_cost = (float)$m['unit_cost'];
        } else {
            $cs = $pdo->prepare("SELECT cost_price FROM products WHERE product_id = ?");
            $cs->execute([$product_id]);
            $unit_cost = (float)($cs->fetchColumn() ?: 0);
        }

        // Total cost → quantity * unit_cost fallback.
        $total_cost = (isset($m['total_cost']) && $m['total_cost'] !== '' && $m['total_cost'] !== null)
            ? (float)$m['total_cost']
            : ($qty * $unit_cost);

        // Running balance from the ledger (order-independent, self-consistent).
        $signed = stockMovementIsInbound($type) ? $qty : -$qty;

        if (isset($m['stock_before']) && $m['stock_before'] !== '' && $m['stock_before'] !== null) {
            $stock_before = (float)$m['stock_before'];
        } else {
            $in_list = "'" . implode("','", stockMovementInTypes()) . "'";
            $sql = "SELECT COALESCE(SUM(CASE WHEN movement_type IN ($in_list) THEN quantity ELSE -quantity END), 0)
                      FROM stock_movements WHERE product_id = ?";
            $par = [$product_id];
            if ($warehouse_id !== null) { $sql .= " AND warehouse_id = ?"; $par[] = $warehouse_id; }
            $bs = $pdo->prepare($sql);
            $bs->execute($par);
            $stock_before = (float)$bs->fetchColumn();
        }

        $stock_after = (isset($m['stock_after']) && $m['stock_after'] !== '' && $m['stock_after'] !== null)
            ? (float)$m['stock_after']
            : ($stock_before + $signed);

        $stmt = $pdo->prepare("
            INSERT INTO stock_movements
                (product_id, movement_type, quantity, unit, unit_cost, total_cost,
                 reference_id, reference_type, reference_number, movement_date,
                 warehouse_id, project_id, stock_before, stock_after, reason, notes,
                 created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $product_id, $type, $qty, $unit, $unit_cost, $total_cost,
            $reference_id, $reference_type, $reference_number, $movement_date,
            $warehouse_id, $project_id, $stock_before, $stock_after, $reason, $notes,
            $created_by,
        ]);

        return (int)$pdo->lastInsertId();
    }
}
