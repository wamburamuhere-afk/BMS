<?php
/**
 * core/product_delete.php
 * -----------------------
 * One rule for deleting a product, shared by the web (api/delete_product.php)
 * and mobile (api/mobile/products/delete.php) endpoints:
 *
 *   1. A product that still holds stock in any shop/warehouse cannot be
 *      deleted. The user first brings each shop's quantity to zero (a stock
 *      adjustment — which posts to the ledger), then deletes.
 *   2. Deleting a product removes the product, never its history. Past POS
 *      sales, stock movements, batches and every ledger entry stay exactly as
 *      they are — the money from those sales is real and the reports keep it.
 *
 * Callers keep their own document checks (sales orders, invoices, …) and scope
 * gates; these helpers only answer "is there stock?" and do the delete.
 */

if (!function_exists('productStockBlocker')) {
    /**
     * Null when the product holds no stock anywhere; otherwise the message to
     * show, naming each shop/warehouse and its quantity. Any non-zero quantity
     * blocks — negative (oversold) stock is an open position too.
     */
    function productStockBlocker(PDO $pdo, int $productId, string $productName): ?string
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(w.warehouse_name, CONCAT('#', ps.warehouse_id)) AS wh, SUM(ps.stock_quantity) AS qty
              FROM product_stocks ps
         LEFT JOIN warehouses w ON w.warehouse_id = ps.warehouse_id
             WHERE ps.product_id = ?
          GROUP BY ps.warehouse_id, w.warehouse_name
            HAVING ABS(SUM(ps.stock_quantity)) > 0.0001
        ");
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $isShop = function_exists('isShopLabel') && isShopLabel();
        $where  = $isShop ? 'shop' : 'warehouse';

        if (!$rows) {
            // Products stocked before per-warehouse rows existed keep their
            // quantity on products.current_stock only.
            $legacy = $pdo->prepare("SELECT COALESCE(current_stock, 0) FROM products WHERE product_id = ?");
            $legacy->execute([$productId]);
            $qty = (float)$legacy->fetchColumn();
            if (abs($qty) <= 0.0001) return null;
            return "Cannot delete '$productName': it still has " . _pd_qty($qty) . " in stock. "
                 . "Bring the stock to zero first (stock adjustment), then delete. Past sales stay in the reports.";
        }

        $parts = [];
        foreach ($rows as $r) $parts[] = $r['wh'] . ': ' . _pd_qty((float)$r['qty']);
        return "Cannot delete '$productName': it still has stock — " . implode(', ', $parts) . ". "
             . "Bring each $where's stock to zero first (stock adjustment), then delete. Past sales stay in the reports.";
    }
}

if (!function_exists('_pd_qty')) {
    function _pd_qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, '.', ','), '0'), '.');
    }
}

if (!function_exists('deleteProductKeepHistory')) {
    /**
     * Deletes the product row and its (now zero) per-warehouse stock rows.
     * Leaves stock_movements, product_batches, pos_sales/pos_sale_items and
     * journal_entries untouched. Joins the caller's transaction.
     */
    function deleteProductKeepHistory(PDO $pdo, int $productId): void
    {
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$productId]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$productId]);
    }
}
