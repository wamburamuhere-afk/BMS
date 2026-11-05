<?php
/**
 * core/online_order_record.php — the record of an online order from Accept to receipt, shared by
 * the restaurant online menu and the retail online shop (retail_online_shop_plan.md, "Online order
 * → receipt"). One set of rules for both:
 *
 *   Accept   → customer matched/created (both name AND phone must match to reuse) and a held
 *              order "ONLINE <code> · Name" linked to that customer and to the online order;
 *   Sale     → completing it on the till links the receipt (sale_id) and closes the order itself;
 *   Void     → the order goes back to Accepted and back into Held orders on the till;
 *   Not collected → staff cancel it with a reason; its held order is cancelled too.
 *
 * Callers own the transaction and the permission / scope checks.
 */

require_once __DIR__ . '/online_customer.php';

if (!function_exists('oorIsTableOrder')) {
    function oorIsTableOrder(array $o): bool
    {
        return ($o['order_type'] ?? '') === 'table';
    }
}

if (!function_exists('oorHoldReference')) {
    function oorHoldReference(array $o): string
    {
        $name = trim((string)($o['customer_name'] ?? ''));
        return mb_substr('ONLINE ' . $o['order_code'] . ($name !== '' ? ' · ' . $name : ''), 0, 100);
    }
}

if (!function_exists('oorOrderItems')) {
    /** The order's lines in the till's cart shape, each marked with its online order. */
    function oorOrderItems(array $o): array
    {
        $items = (array)json_decode((string)$o['items_json'], true);
        foreach ($items as &$it) { $it['online_order_id'] = (int)$o['order_id']; }
        unset($it);
        return $items;
    }
}

if (!function_exists('oorShiftForHold')) {
    /**
     * Shift for a new held order: the user's active shift, else NULL when the column allows it.
     * @return array{ok:bool, shift_id:?int}
     */
    function oorShiftForHold(PDO $pdo, int $uid): array
    {
        $st = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
        $st->execute([$uid]);
        $shift = $st->fetchColumn();
        if ($shift) return ['ok' => true, 'shift_id' => (int)$shift];
        $col = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'shift_id'")->fetch(PDO::FETCH_ASSOC);
        return ['ok' => $col && strtoupper((string)$col['Null']) === 'YES', 'shift_id' => null];
    }
}

if (!function_exists('oorInsertHold')) {
    /** A held order for a pickup / delivery / shop order. @return int|null hold_id (null when no shift and the column needs one) */
    function oorInsertHold(PDO $pdo, array $o, int $uid, ?int $customerId): ?int
    {
        $shift = oorShiftForHold($pdo, $uid);
        if (!$shift['ok']) return null;
        $items = oorOrderItems($o);
        $sub = round((float)$o['subtotal'], 2);
        $pdo->prepare("INSERT INTO pos_held_sales (user_id, shift_id, customer_id, warehouse_id, table_id, hold_reference, items_data, item_count,
                                                   subtotal, tax_amount, total_amount, online_order_id, held_at)
                       VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, 0, ?, ?, NOW())")
            ->execute([$uid, $shift['shift_id'], $customerId, (int)$o['warehouse_id'], oorHoldReference($o),
                       json_encode($items, JSON_UNESCAPED_UNICODE), count($items), $sub, $sub, (int)$o['order_id']]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('oorAcceptToHeld')) {
    /**
     * Accept a pickup / delivery / shop order (status pending, row locked by the caller):
     * customer matched or created, a held order made, the order moved to accepted.
     * @return array{hold_id:int, customer_id:?int, customer_created:bool, customer_name:string}
     * @throws RuntimeException with a staff-safe message
     */
    function oorAcceptToHeld(PDO $pdo, array $o, int $uid, int $linkCustomerId = 0): array
    {
        if (oorIsTableOrder($o)) throw new RuntimeException('A table order joins its table, not a held order.');
        $name  = trim((string)($o['customer_name'] ?? ''));
        $phone = trim((string)($o['customer_phone'] ?? ''));
        $cust  = ['customer_id' => null, 'created' => false, 'customer_name' => $name];
        if ($name !== '' && $phone !== '') {
            try {
                $cust = ocFindOrCreate($pdo, $name, $phone, $uid, $linkCustomerId);
            } catch (RuntimeException $e) {
                throw new RuntimeException(t('That customer does not use this phone number. Please choose again.'));
            }
        }
        $holdId = oorInsertHold($pdo, $o, $uid, $cust['customer_id']);
        if ($holdId === null) throw new RuntimeException(t('Please start a cash register shift before accepting this order.'));
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'accepted', hold_id = ?, customer_id = ?, handled_by = ?, accepted_at = NOW() WHERE order_id = ?")
            ->execute([$holdId, $cust['customer_id'], $uid, (int)$o['order_id']]);
        return ['hold_id' => $holdId, 'customer_id' => $cust['customer_id'], 'customer_created' => (bool)$cust['created'], 'customer_name' => (string)$cust['customer_name']];
    }
}

if (!function_exists('oorAttachSale')) {
    /**
     * Inside the sale's transaction: link every online order whose lines are on this sale to the
     * receipt and close it (not when part of a table bill stays open). Refuses a line that belongs
     * to another shop (it would take the wrong shop's stock) or an order already paid on another
     * receipt (two cashiers loading the same order).
     * @return string[] the online order codes on this sale
     * @throws Exception (code 422) with a cashier-safe message
     */
    function oorAttachSale(PDO $pdo, array $items, int $saleId, int $warehouseId, bool $keepTable): array
    {
        $ids = [];
        foreach ($items as $it) if (is_array($it) && !empty($it['online_order_id'])) $ids[(int)$it['online_order_id']] = true;
        $ids = array_keys(array_filter($ids, fn($v, $k) => $k > 0, ARRAY_FILTER_USE_BOTH));
        if (!$ids) return [];
        try { if (!$pdo->query("SHOW COLUMNS FROM restaurant_online_orders LIKE 'sale_id'")->fetch()) return []; }
        catch (Throwable $e) { return []; }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT o.order_id, o.order_code, o.warehouse_id, o.status, o.sale_id, o.order_type, s.receipt_number, s.sale_status
                               FROM restaurant_online_orders o LEFT JOIN pos_sales s ON s.sale_id = o.sale_id
                              WHERE o.order_id IN ($ph) FOR UPDATE");
        $st->execute($ids);
        $codes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if ((int)$o['warehouse_id'] !== $warehouseId) {
                throw new Exception(sprintf(t('Online order %s belongs to another shop. Open that shop on the till to complete it.'), $o['order_code']), 422);
            }
            if ($o['status'] === 'completed' && $o['sale_id'] && (int)$o['sale_id'] !== $saleId && $o['sale_status'] !== 'voided' && !oorIsTableOrder($o)) {
                throw new Exception(sprintf(t('Online order %1$s was already paid on receipt %2$s.'), $o['order_code'], $o['receipt_number']), 422);
            }
            $codes[] = (string)$o['order_code'];
            $close = !$keepTable;
            $pdo->prepare("UPDATE restaurant_online_orders
                              SET sale_id = CASE WHEN sale_id IS NULL OR sale_id = 0 OR ? = 1 THEN ? ELSE sale_id END" .
                              ($close ? ", status = 'completed', completed_at = NOW()" : '') . "
                            WHERE order_id = ?")
                ->execute([$o['sale_status'] === 'voided' ? 1 : 0, $saleId, (int)$o['order_id']]);
        }
        return $codes;
    }
}

if (!function_exists('oorOnVoid')) {
    /**
     * Inside the void's transaction: a voided receipt's pickup / delivery / shop orders go back to
     * Accepted and back into Held orders, so the cashier can take the payment again. Table orders
     * stay with their table's history (the bill was the table's).
     * @return string[] codes re-opened
     */
    function oorOnVoid(PDO $pdo, int $saleId, int $uid): array
    {
        try { if (!$pdo->query("SHOW COLUMNS FROM restaurant_online_orders LIKE 'sale_id'")->fetch()) return []; }
        catch (Throwable $e) { return []; }
        $st = $pdo->prepare("SELECT * FROM restaurant_online_orders WHERE sale_id = ? AND status = 'completed' FOR UPDATE");
        $st->execute([$saleId]);
        $codes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (oorIsTableOrder($o)) continue;
            $holdId = oorInsertHold($pdo, $o, $uid, $o['customer_id'] !== null ? (int)$o['customer_id'] : null);
            $pdo->prepare("UPDATE restaurant_online_orders SET status = 'accepted', sale_id = NULL, completed_at = NULL, hold_id = COALESCE(?, hold_id) WHERE order_id = ?")
                ->execute([$holdId, (int)$o['order_id']]);
            $codes[] = (string)$o['order_code'];
        }
        return $codes;
    }
}

if (!function_exists('oorCancelNotCollected')) {
    /**
     * Staff cancel an accepted order the customer never paid for / collected (row locked by the caller).
     * Its held order is cancelled too, so it leaves the till.
     * @throws RuntimeException with a staff-safe message
     */
    function oorCancelNotCollected(PDO $pdo, array $o, string $reason, int $uid): void
    {
        if (oorIsTableOrder($o)) throw new RuntimeException(t('A table order is closed with its table bill.'));
        if (!in_array($o['status'], ['accepted', 'ready'], true)) throw new RuntimeException(t('Only an accepted order can be cancelled as not collected.'));
        $pdo->prepare("UPDATE pos_held_sales SET status = 'cancelled' WHERE status = 'held' AND (online_order_id = ? OR hold_id = ?)")
            ->execute([(int)$o['order_id'], (int)($o['hold_id'] ?? 0)]);
        $pdo->prepare("UPDATE restaurant_online_orders SET status = 'cancelled', cancelled_by = 'staff', reject_reason = ?, handled_by = COALESCE(handled_by, ?) WHERE order_id = ?")
            ->execute([$reason, $uid, (int)$o['order_id']]);
    }
}

if (!function_exists('oorCodesForSale')) {
    /** Online order codes paid on one receipt (for the receipt and Sales history). */
    function oorCodesForSale(PDO $pdo, int $saleId): array
    {
        try {
            $st = $pdo->prepare("SELECT order_code FROM restaurant_online_orders WHERE sale_id = ? ORDER BY order_id");
            $st->execute([$saleId]);
            return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }
}
