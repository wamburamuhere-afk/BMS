<?php
/**
 * core/pos_line_cost.php
 * ----------------------
 * 2026-11-14 — the buying cost of a POS sale line, kept ON the line
 * (pos_sale_items.unit_cost) at the moment it is sold.
 *
 * Why: the cost used to be re-read live from pos_sale_item_batches ×
 * product_batches.unit_cost every time a report ran. A return deletes or
 * shrinks those batch links (to put the stock back), so after a return:
 *   - a fully returned line had no batch link left and fell back to
 *     products.cost_price — often a crate price, which the "cost > selling
 *     price" guard then counted as 0;
 *   - the return line itself never had a batch link, so its cost was 0 too.
 * The dashboard then took the return off Sales but not off Cost, showing a
 * loss that did not exist (Piga Kazi Shop, 8–14 Nov 2026).
 *
 * Rule now (same as the ledger already does when it posts a sale):
 *   - selling price comes from the current product price (unchanged);
 *   - buying cost is fixed on the line the day it is sold — the cost of the
 *     batch(es) it drew from; editing that batch later does not rewrite past
 *     sales, but the next sale from it uses the new cost;
 *   - a return line carries the cost of the line it returns.
 *
 * unit_cost stays NULL where the old rule must keep applying unchanged:
 * services / recipe dishes, and a non-batch line whose products.cost_price is
 * higher than its selling price (the existing data-error guard — it heals by
 * itself once the product's cost is corrected). Readers fall back to the old
 * formula for NULL, so a database without the column behaves exactly as before.
 *
 * No other includes — safe to load from tenant migrations.
 */

if (!function_exists('posLineCostReady')) {
    /** pos_sale_items.unit_cost exists (migration 2026_11_14_pos_line_unit_cost). */
    function posLineCostReady(PDO $pdo): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, $cache)) {
            try {
                $cache[$key] = (bool)$pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'unit_cost'")->fetch();
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }
}

if (!function_exists('posStampSaleLineCosts')) {
    /**
     * Fix the buying cost on every line of one sale that has none yet. Call it
     * after the sale's batches were consumed (FEFO), before the sale posts.
     */
    function posStampSaleLineCosts(PDO $pdo, int $saleId): void
    {
        if ($saleId <= 0 || !posLineCostReady($pdo)) return;
        $st = $pdo->prepare("
            UPDATE pos_sale_items si
              JOIN products p ON p.product_id = si.product_id
              LEFT JOIN (
                    SELECT psib.sale_item_id, SUM(psib.quantity) AS q, SUM(psib.quantity * pb.unit_cost) AS c
                      FROM pos_sale_item_batches psib
                      JOIN product_batches pb ON pb.batch_id = psib.batch_id
                      JOIN pos_sale_items s2 ON s2.sale_item_id = psib.sale_item_id AND s2.sale_id = ?
                  GROUP BY psib.sale_item_id
              ) bc ON bc.sale_item_id = si.sale_item_id
               SET si.unit_cost = CASE
                       WHEN p.is_service = 1 THEN NULL
                       WHEN bc.q > 0 THEN ROUND(bc.c / bc.q, 4)
                       WHEN COALESCE(p.cost_price, 0) > p.selling_price AND p.selling_price > 0 THEN NULL
                       ELSE COALESCE(p.cost_price, 0)
                   END
             WHERE si.sale_id = ? AND si.unit_cost IS NULL
        ");
        $st->execute([$saleId, $saleId]);
    }
}

if (!function_exists('posLineUnitCostOrNull')) {
    /**
     * The buying cost of one sale line: the cost fixed on it, else its batch
     * cost, else products.cost_price. NULL where the old rule counts nothing
     * (service, or cost_price higher than selling price).
     */
    function posLineUnitCostOrNull(PDO $pdo, int $saleItemId): ?float
    {
        if (posLineCostReady($pdo)) {
            $s = $pdo->prepare("SELECT unit_cost FROM pos_sale_items WHERE sale_item_id = ?");
            $s->execute([$saleItemId]);
            $v = $s->fetchColumn();
            if ($v !== false && $v !== null) return (float)$v;
        }
        try {
            $b = $pdo->prepare("SELECT COALESCE(SUM(psib.quantity),0) AS q, COALESCE(SUM(psib.quantity * pb.unit_cost),0) AS c
                                  FROM pos_sale_item_batches psib
                                  JOIN product_batches pb ON pb.batch_id = psib.batch_id
                                 WHERE psib.sale_item_id = ?");
            $b->execute([$saleItemId]);
            $row = $b->fetch(PDO::FETCH_ASSOC);
            if ($row && (float)$row['q'] > 0) return round((float)$row['c'] / (float)$row['q'], 4);
        } catch (Throwable $e) { /* no batch tables on this database — product cost below */ }
        $p = $pdo->prepare("SELECT COALESCE(p.cost_price,0) AS cost, COALESCE(p.selling_price,0) AS sell, COALESCE(p.is_service,0) AS svc
                              FROM pos_sale_items si JOIN products p ON p.product_id = si.product_id
                             WHERE si.sale_item_id = ?");
        $p->execute([$saleItemId]);
        $r = $p->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['svc'] === 1) return null;
        if ((float)$r['cost'] > (float)$r['sell'] && (float)$r['sell'] > 0) return null;
        return (float)$r['cost'];
    }
}

if (!function_exists('posLineCostBackfill')) {
    /**
     * Fill unit_cost on sales recorded before the column existed. Criteria-based,
     * only touches NULL rows, so a re-run changes nothing. Returns row counts.
     *
     *   A. lines that still have batch links → their batch cost;
     *   B. returned lines whose batch links the return removed → the cost of
     *      that product's batches in the same shop received on/before the sale
     *      (the batches it could have been drawn from);
     *   C. return lines → the cost of the line they returned.
     * Everything else stays NULL and keeps the old formula.
     */
    function posLineCostBackfill(PDO $pdo): array
    {
        $out = ['batch' => 0, 'returned' => 0, 'return_lines' => 0];
        // Checked directly, not via the cached posLineCostReady(): a migration adds the column first.
        if (!$pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'unit_cost'")->fetch()) return $out;

        $out['batch'] = $pdo->exec("
            UPDATE pos_sale_items si
              JOIN products p ON p.product_id = si.product_id AND p.is_service = 0
              JOIN (
                    SELECT psib.sale_item_id, SUM(psib.quantity) AS q, SUM(psib.quantity * pb.unit_cost) AS c
                      FROM pos_sale_item_batches psib
                      JOIN product_batches pb ON pb.batch_id = psib.batch_id
                  GROUP BY psib.sale_item_id
              ) bc ON bc.sale_item_id = si.sale_item_id AND bc.q > 0
               SET si.unit_cost = ROUND(bc.c / bc.q, 4)
             WHERE si.unit_cost IS NULL
        ");

        $out['returned'] = $pdo->exec("
            UPDATE pos_sale_items si
              JOIN pos_sales ps ON ps.sale_id = si.sale_id AND ps.is_return_sale = 0 AND ps.warehouse_id IS NOT NULL
              JOIN products p ON p.product_id = si.product_id AND p.is_service = 0
              JOIN (
                    SELECT s.sale_item_id,
                           SUM(pb.quantity_received * pb.unit_cost) / SUM(pb.quantity_received) AS uc
                      FROM pos_sale_items s
                      JOIN pos_sales x ON x.sale_id = s.sale_id
                      JOIN product_batches pb ON pb.product_id = s.product_id AND pb.warehouse_id = x.warehouse_id
                                             AND pb.created_at <= x.sale_date AND pb.quantity_received > 0
                     WHERE s.returned_quantity > 0 AND s.unit_cost IS NULL
                  GROUP BY s.sale_item_id
              ) h ON h.sale_item_id = si.sale_item_id
               SET si.unit_cost = ROUND(h.uc, 4)
             WHERE si.unit_cost IS NULL AND si.returned_quantity > 0
               AND NOT EXISTS (SELECT 1 FROM pos_sale_item_batches k WHERE k.sale_item_id = si.sale_item_id)
        ");

        $out['return_lines'] = $pdo->exec("
            UPDATE pos_sale_items si
              JOIN pos_sales r ON r.sale_id = si.sale_id AND r.is_return_sale = 1 AND r.original_sale_id IS NOT NULL
              JOIN (
                    SELECT o.sale_id, o.product_id, AVG(o.unit_cost) AS uc
                      FROM pos_sale_items o
                     WHERE o.unit_cost IS NOT NULL
                  GROUP BY o.sale_id, o.product_id
              ) oc ON oc.sale_id = r.original_sale_id AND oc.product_id = si.product_id
               SET si.unit_cost = ROUND(oc.uc, 4)
             WHERE si.unit_cost IS NULL
        ");

        return $out;
    }
}
