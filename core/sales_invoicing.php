<?php
/**
 * core/sales_invoicing.php
 *
 * Single source of truth for "how much of a sales order is still billable".
 * Invoiced quantity is derived from invoice_items on invoices that are not
 * cancelled, so cancelling an invoice frees its quantity automatically;
 * sales_order_items.quantity_invoiced is only a cached copy for list pages.
 */

if (!defined('SO_INVOICE_QTY_EPSILON')) {
    define('SO_INVOICE_QTY_EPSILON', 0.0005);
}

if (!function_exists('soLineInvoicedMap')) {
    /** @return array<int,float> order_item_id => quantity already invoiced */
    function soLineInvoicedMap(PDO $pdo, int $soId, int $excludeInvoiceId = 0): array
    {
        $st = $pdo->prepare("
            SELECT ii.order_item_id, SUM(ii.quantity) AS qty
              FROM invoice_items ii
              JOIN invoices i            ON i.invoice_id = ii.invoice_id AND i.status <> 'cancelled'
              JOIN sales_order_items soi ON soi.order_item_id = ii.order_item_id
             WHERE soi.order_id = ? AND ii.invoice_id <> ?
          GROUP BY ii.order_item_id
        ");
        $st->execute([$soId, $excludeInvoiceId]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['order_item_id']] = (float)$r['qty'];
        }
        return $map;
    }
}

if (!function_exists('soInvoiceableLines')) {
    /**
     * Lines of a sales order that still have quantity left to invoice, carrying
     * the agreed price, VAT rate, discount and description from the order line.
     */
    function soInvoiceableLines(PDO $pdo, int $soId, int $excludeInvoiceId = 0): array
    {
        require_once __DIR__ . '/sales_line_discount.php';
        $discCols = salesDiscountColumnsReady($pdo, 'sales_order_items') ? ', soi.discount_type, soi.discount_amount' : '';
        $st = $pdo->prepare("
            SELECT soi.order_item_id, soi.order_id, soi.product_id,
                   COALESCE(NULLIF(soi.product_name, ''), p.product_name) AS product_name,
                   COALESCE(NULLIF(soi.description, ''), p.description)   AS description,
                   COALESCE(NULLIF(soi.sku, ''), p.sku)                   AS sku,
                   COALESCE(NULLIF(soi.unit, ''), p.unit, 'pcs')          AS unit,
                   COALESCE(p.is_service, 0)                              AS is_service,
                   soi.quantity, soi.unit_price, soi.tax_rate, soi.discount_percent" . $discCols . "
              FROM sales_order_items soi
              LEFT JOIN products p ON p.product_id = soi.product_id
             WHERE soi.order_id = ?
          ORDER BY soi.order_item_id
        ");
        $st->execute([$soId]);
        $invoiced = soLineInvoicedMap($pdo, $soId, $excludeInvoiceId);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $done      = $invoiced[(int)$r['order_item_id']] ?? 0.0;
            $remaining = round((float)$r['quantity'] - $done, 3);
            if ($remaining <= SO_INVOICE_QTY_EPSILON) continue;
            $r['ordered_quantity']  = (float)$r['quantity'];
            $r['invoiced_quantity'] = $done;
            $r['remaining_quantity'] = $remaining;
            $r['quantity']          = $remaining;
            // A fixed-amount discount follows the quantity billed: what is still open on the line
            // (the same figure save_invoice.php charges — soLineDiscountFor()).
            $r['discount_type'] = ($r['discount_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
            $r['discount_value'] = $r['discount_type'] === 'amount'
                ? soLineDiscountFor($pdo, (int)$r['order_item_id'], $remaining, $excludeInvoiceId)['discount_value']
                : (float)$r['discount_percent'];
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('soAssertInvoiceable')) {
    /**
     * Rejects invoice lines that bill more of a sales-order line than is left,
     * or that point at a line of a different order. Locks the order's lines
     * (FOR UPDATE) so two people invoicing the same order at once cannot both
     * pass. Must run inside the caller's transaction.
     *
     * @param array $items invoice lines as posted (order_item_id, quantity, product_name)
     * @return int[] ids of the sales orders touched, for soRefreshInvoicedQuantities()
     */
    function soAssertInvoiceable(PDO $pdo, array $items, ?int $orderId, int $excludeInvoiceId = 0): array
    {
        $want = [];
        foreach ($items as $it) {
            $oid = (int)($it['order_item_id'] ?? 0);
            if ($oid <= 0) continue;
            $want[$oid] = ($want[$oid] ?? 0) + (float)($it['quantity'] ?? 0);
        }
        if (!$want) return [];

        $ph = implode(',', array_fill(0, count($want), '?'));
        $st = $pdo->prepare("SELECT order_item_id, order_id, quantity,
                                    COALESCE(NULLIF(product_name, ''), CONCAT('Line #', order_item_id)) AS product_name
                               FROM sales_order_items WHERE order_item_id IN ($ph) FOR UPDATE");
        $st->execute(array_keys($want));
        $lines = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $lines[(int)$r['order_item_id']] = $r;

        $soIds = [];
        $problems = [];
        foreach ($want as $oid => $qty) {
            if (!isset($lines[$oid])) {
                $problems[] = "a sales order line (#$oid) no longer exists";
                continue;
            }
            $soId = (int)$lines[$oid]['order_id'];
            if ($orderId && $soId !== $orderId) {
                $problems[] = $lines[$oid]['product_name'] . ' belongs to a different sales order';
                continue;
            }
            $soIds[$soId] = true;
        }
        foreach (array_keys($soIds) as $soId) {
            $done = soLineInvoicedMap($pdo, $soId, $excludeInvoiceId);
            foreach ($want as $oid => $qty) {
                if (!isset($lines[$oid]) || (int)$lines[$oid]['order_id'] !== $soId) continue;
                $remaining = round((float)$lines[$oid]['quantity'] - ($done[$oid] ?? 0), 3);
                if ($qty > $remaining + SO_INVOICE_QTY_EPSILON) {
                    $problems[] = sprintf('%s: %s left to invoice, %s requested',
                        $lines[$oid]['product_name'], rtrim(rtrim(number_format(max($remaining, 0), 3, '.', ''), '0'), '.') ?: '0',
                        rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'));
                }
            }
        }
        if ($problems) {
            throw new Exception("This sales order cannot be invoiced as entered:\n- " . implode("\n- ", $problems));
        }
        return array_keys($soIds);
    }
}

if (!function_exists('soRefreshInvoicedQuantities')) {
    /** Re-derives sales_order_items.quantity_invoiced from non-cancelled invoices. */
    function soRefreshInvoicedQuantities(PDO $pdo, array $soIds): void
    {
        $upd = $pdo->prepare("
            UPDATE sales_order_items soi
               SET soi.quantity_invoiced = (
                   SELECT COALESCE(SUM(ii.quantity), 0)
                     FROM invoice_items ii
                     JOIN invoices i ON i.invoice_id = ii.invoice_id AND i.status <> 'cancelled'
                    WHERE ii.order_item_id = soi.order_item_id)
             WHERE soi.order_id = ?
        ");
        foreach (array_unique(array_map('intval', $soIds)) as $soId) {
            if ($soId > 0) $upd->execute([$soId]);
        }
    }
}

if (!function_exists('soIdsForInvoice')) {
    /** Sales orders an invoice bills against (header order_id plus any linked lines). */
    function soIdsForInvoice(PDO $pdo, int $invoiceId): array
    {
        $st = $pdo->prepare("
            SELECT DISTINCT soi.order_id
              FROM invoice_items ii
              JOIN sales_order_items soi ON soi.order_item_id = ii.order_item_id
             WHERE ii.invoice_id = ?
            UNION
            SELECT order_id FROM invoices WHERE invoice_id = ? AND order_id IS NOT NULL AND order_id > 0
        ");
        $st->execute([$invoiceId, $invoiceId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('soLineDiscountFor')) {
    /**
     * The discount an invoice line raised from a sales-order line carries: the
     * order line's own (agreed) discount, never the browser's. A percentage is
     * the same on any quantity; a fixed amount is what is still open on the line
     * shared by quantity — round(left × qty ÷ open qty) — and the invoice that
     * bills the last of the line takes exactly what is left, so the invoices of
     * one order add up to exactly the order's discount. The invoice form shows
     * the same figure (invoice_create.php soDiscountShare()).
     *
     * @return array{discount_type:string, discount_value:float, open_qty:?float}|null null = not a sales-order line
     */
    function soLineDiscountFor(PDO $pdo, int $orderItemId, float $qty, int $excludeInvoiceId = 0): ?array
    {
        if ($orderItemId <= 0) return null;
        require_once __DIR__ . '/sales_line_discount.php';
        $typed = salesDiscountColumnsReady($pdo, 'sales_order_items');
        $st = $pdo->prepare("SELECT quantity, discount_percent" . ($typed ? ", discount_type, discount_amount" : "") . "
                               FROM sales_order_items WHERE order_item_id = ?");
        $st->execute([$orderItemId]);
        $so = $st->fetch(PDO::FETCH_ASSOC);
        if (!$so) return null;
        if (($so['discount_type'] ?? 'percent') !== 'amount') {
            return ['discount_type' => 'percent', 'discount_value' => (float)$so['discount_percent'], 'open_qty' => null];
        }
        $ordered = (float)$so['quantity'];
        $total   = round((float)$so['discount_amount'], 2);
        $prev = $pdo->prepare("SELECT COALESCE(SUM(ii.quantity), 0) AS qty, COALESCE(SUM(ii.discount_amount), 0) AS disc
                                 FROM invoice_items ii
                                 JOIN invoices i ON i.invoice_id = ii.invoice_id AND i.status <> 'cancelled'
                                WHERE ii.order_item_id = ? AND ii.invoice_id <> ?");
        $prev->execute([$orderItemId, $excludeInvoiceId]);
        $p = $prev->fetch(PDO::FETCH_ASSOC);
        $left = round($total - (float)$p['disc'], 2);
        $remainingQty = $ordered - (float)$p['qty'];
        if ($qty >= $remainingQty - SO_INVOICE_QTY_EPSILON) {
            $value = $left;                                            // the last of the line: exactly what is left
        } else {
            $value = $remainingQty > 0 ? round($left * $qty / $remainingQty, 2) : 0.0;
        }
        return ['discount_type' => 'amount', 'discount_value' => max(0.0, min($value, max($left, 0.0))), 'open_qty' => $remainingQty];
    }
}

if (!function_exists('salesMoneyMax')) {
    /** Largest value a DECIMAL(15,2) money column holds. */
    function salesMoneyMax(): float
    {
        return 9999999999999.99;
    }
}

if (!function_exists('assertSalesAmountsFit')) {
    /**
     * MySQL here runs without strict mode, so an oversized amount is silently
     * clipped to the column maximum instead of failing. Reject it up front.
     */
    function assertSalesAmountsFit(array $amounts): void
    {
        $max = salesMoneyMax();
        foreach ($amounts as $label => $value) {
            if (abs((float)$value) > $max) {
                throw new Exception(sprintf('%s (%s) is larger than the system can store (maximum %s).',
                    $label, number_format((float)$value, 2), number_format($max, 2)));
            }
        }
    }
}
