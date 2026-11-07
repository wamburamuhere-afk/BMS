<?php
/**
 * core/restaurant_order_flow.php — the restaurant order flow engine
 * (restaurant_order_flow_plan.md Phase 2): Waiter → Cashier → Kitchen → Waiter → Bill.
 *
 * Every rule lives here; the endpoints are thin. Nothing here posts a sale — the sale is still
 * booked only when the cashier closes the bill (api/pos/process_sale.php). The only posting is
 * food waste for stock items (fixed accounts: Cost of Sales / Inventory — no account is chosen).
 *
 * Ticket statuses:  pending (waiting for the cashier) → queued → preparing → ready → served
 *                   cancelled (returned by the cashier, or every line cancelled)
 * A ticket belongs to the table's CURRENT order while closed_at IS NULL and sale_id IS NULL.
 *
 * Callers own the transaction for every function that writes (except where noted). Functions
 * that refuse throw RofException (a user-facing message + an error_code the screens act on).
 */

require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/restaurant_order_flow_schema.php';

if (!class_exists('RofException')) {
    class RofException extends Exception
    {
        public string $errorCode;
        public array $extra;
        public function __construct(string $message, string $errorCode = 'refused', array $extra = [])
        {
            parent::__construct($message, 422);
            $this->errorCode = $errorCode;
            $this->extra = $extra;
        }
        public function toArray(): array
        {
            return ['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode] + $this->extra;
        }
    }
}

const ROF_UNSTARTED = ['pending', 'queued'];
const ROF_STARTED   = ['preparing', 'ready', 'served'];

// ─── Readiness, settings, rights ──────────────────────────────────────────────

if (!function_exists('rofReady')) {
    /** The Phase 1 schema is on this database (closed_at column + cancellations table). */
    function rofReady(PDO $pdo): bool
    {
        static $cache = [];
        $k = spl_object_id($pdo);
        if (isset($cache[$k])) return $cache[$k];
        try {
            return $cache[$k] = rofsHasTable($pdo, 'kitchen_tickets') && rofsHasColumn($pdo, 'kitchen_tickets', 'closed_at')
                && rofsHasTable($pdo, 'restaurant_order_cancellations');
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }
}

if (!function_exists('rofSetting')) {
    /** A setting read straight from the table (no per-request cache, so a change is seen at once). */
    function rofSetting(PDO $pdo, string $key, string $default = ''): string
    {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('rofKitchenMode')) {
    function rofKitchenMode(PDO $pdo): string
    {
        $m = rofSetting($pdo, 'restaurant_kitchen_mode', 'none');
        return in_array($m, ['none', 'screen', 'printer'], true) ? $m : 'none';
    }
}

if (!function_exists('rofConfirmRequired')) {
    /** "Cashier must confirm orders before the kitchen" — only meaningful when orders reach the kitchen on a screen/printer. */
    function rofConfirmRequired(PDO $pdo): bool
    {
        return rofReady($pdo) && rofKitchenMode($pdo) !== 'none' && rofSetting($pdo, 'restaurant_confirm_orders', '0') === '1';
    }
}

if (!function_exists('rofRightsReady')) {
    /** The two permission rows exist (migration ran). Until then every right falls back to today's rule. */
    function rofRightsReady(PDO $pdo): bool
    {
        static $cache = [];
        $k = spl_object_id($pdo);
        if (isset($cache[$k])) return $cache[$k];
        try {
            return $cache[$k] = rofsPermissionId($pdo, 'restaurant_orders') > 0 && rofsPermissionId($pdo, 'kitchen_display') > 0;
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }
}

if (!function_exists('rofCanTake')) {
    /** Take / add to / send an order; mark food received. */
    function rofCanTake(PDO $pdo): bool
    {
        return canCreate('pos') || (rofRightsReady($pdo) && canCreate('restaurant_orders'));
    }
}
if (!function_exists('rofCanConfirm')) {
    /** Confirm an order to the kitchen; approve cancelling food already cooking. */
    function rofCanConfirm(PDO $pdo): bool
    {
        return rofRightsReady($pdo) ? canApprove('restaurant_orders') : canCreate('pos');
    }
}
if (!function_exists('rofCanCancelUnstarted')) {
    /** Cancel a line the kitchen has not started yet. */
    function rofCanCancelUnstarted(PDO $pdo): bool
    {
        return rofRightsReady($pdo) ? (canDelete('restaurant_orders') || canApprove('restaurant_orders')) : canCreate('pos');
    }
}
if (!function_exists('rofCanKitchen')) {
    /** Work the kitchen screen (Preparing → Ready). */
    function rofCanKitchen(PDO $pdo): bool
    {
        return rofRightsReady($pdo) ? canEdit('kitchen_display') : canView('restaurant_pos');
    }
}
if (!function_exists('rofCanSeeKitchen')) {
    function rofCanSeeKitchen(PDO $pdo): bool
    {
        return rofRightsReady($pdo) ? (canView('kitchen_display') || canEdit('kitchen_display')) : canView('restaurant_pos');
    }
}
if (!function_exists('rofCanPay')) {
    function rofCanPay(): bool
    {
        return canCreate('pos');
    }
}

if (!function_exists('rofRights')) {
    /** What the current user may do — for the screens. */
    function rofRights(PDO $pdo): array
    {
        return [
            'confirm_required' => rofConfirmRequired($pdo),
            'kitchen_mode'     => rofKitchenMode($pdo),
            'take'             => rofCanTake($pdo),
            'confirm'          => rofCanConfirm($pdo),
            'cancel_unstarted' => rofCanCancelUnstarted($pdo),
            'approve_cancel'   => rofCanConfirm($pdo),
            'kitchen'          => rofCanKitchen($pdo),
            'pay'              => rofCanPay(),
        ];
    }
}

// ─── Reading an order ─────────────────────────────────────────────────────────

if (!function_exists('rofProducts')) {
    /** @return array<int,array> product_id => row (name, station, kinds, costs) */
    function rofProducts(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $menu = restaurantMenuSchemaReady($pdo) ? 'is_menu_item' : '0 AS is_menu_item';
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT product_id, product_name, kitchen_station_id, is_service, $menu, COALESCE(is_combo,0) AS is_combo,
                                    COALESCE(cost_price,0) AS cost_price, COALESCE(purchase_price,0) AS purchase_price, COALESCE(selling_price,0) AS selling_price
                               FROM products WHERE product_id IN ($ph)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $out[(int)$p['product_id']] = $p;
        return $out;
    }
}

if (!function_exists('rofRouted')) {
    /** Same rule as the kitchen always used: a kitchen area set, and not a plain service line (a Menu dish still goes). */
    function rofRouted(?array $p): bool
    {
        if (!$p || empty($p['kitchen_station_id'])) return false;
        return empty($p['is_service']) || !empty($p['is_menu_item']);
    }
}

if (!function_exists('rofLineMods')) {
    /** "Extra chilli, less salt" — the modifiers and the kitchen note of a cart line. */
    function rofLineMods(array $item): ?string
    {
        $parts = is_array($item['modifiers'] ?? null)
            ? array_values(array_filter(array_map(fn($m) => is_array($m) ? (string)($m['option_name'] ?? '') : (string)$m, $item['modifiers'])))
            : [];
        $note = trim((string)($item['note'] ?? ''));
        if ($note !== '') $parts[] = $note;
        return $parts ? mb_substr(implode(', ', $parts), 0, 500) : null;
    }
}

if (!function_exists('rofSnapshot')) {
    /**
     * The kitchen part of a cart: product_id => ['qty' => total, 'station' => id, 'lines' => [[mods, qty], ...]].
     * Lines that never go to the kitchen (soda, water, retail items) are left out.
     */
    function rofSnapshot(PDO $pdo, array $items): array
    {
        $prods = rofProducts($pdo, array_map(fn($i) => (int)($i['product_id'] ?? 0), array_filter($items, 'is_array')));
        $snap = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $pid = (int)($it['product_id'] ?? 0);
            $q = (float)($it['quantity'] ?? 0);
            if ($q <= 0 || !rofRouted($prods[$pid] ?? null)) continue;
            $snap[$pid] = $snap[$pid] ?? ['qty' => 0.0, 'station' => (int)$prods[$pid]['kitchen_station_id'], 'lines' => []];
            $snap[$pid]['qty'] += $q;
            $snap[$pid]['lines'][] = [rofLineMods($it), $q];
        }
        return $snap;
    }
}

if (!function_exists('rofCurrentTickets')) {
    /**
     * The tickets of an order that is still open: a table's (table_id) or a table-less held order's (hold_id).
     * Includes cancelled tickets (callers skip them). Each ticket carries its 'items'.
     */
    function rofCurrentTickets(PDO $pdo, int $warehouseId, ?int $tableId, ?int $holdId = null, bool $forUpdate = false): array
    {
        if (!rofReady($pdo)) return [];
        if ($tableId) {
            $where = 'kt.table_id = ?'; $arg = $tableId;
        } elseif ($holdId) {
            $where = 'kt.table_id IS NULL AND kt.hold_id = ?'; $arg = $holdId;
        } else {
            return [];
        }
        $st = $pdo->prepare("SELECT kt.* FROM kitchen_tickets kt
                              WHERE kt.warehouse_id = ? AND $where AND kt.closed_at IS NULL AND kt.sale_id IS NULL
                           ORDER BY kt.ticket_id" . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute([$warehouseId, $arg]);
        $tickets = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$tickets) return [];
        $ids = array_map(fn($t) => (int)$t['ticket_id'], $tickets);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $it = $pdo->prepare("SELECT kti.*, p.product_name FROM kitchen_ticket_items kti LEFT JOIN products p ON p.product_id = kti.product_id
                              WHERE kti.ticket_id IN ($ph) ORDER BY kti.id");
        $it->execute($ids);
        $by = [];
        foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $row) $by[(int)$row['ticket_id']][] = $row;
        foreach ($tickets as &$t) $t['items'] = $by[(int)$t['ticket_id']] ?? [];
        unset($t);
        return $tickets;
    }
}

if (!function_exists('rofSentByProduct')) {
    /** product_id => ['qty','unstarted','started','served'] over the open, not-cancelled tickets. */
    function rofSentByProduct(array $tickets): array
    {
        $out = [];
        foreach ($tickets as $t) {
            if ($t['status'] === 'cancelled') continue;
            foreach ($t['items'] as $i) {
                $pid = (int)$i['product_id']; $q = (float)$i['quantity'];
                if ($q <= 0) continue;
                $out[$pid] = $out[$pid] ?? ['qty' => 0.0, 'unstarted' => 0.0, 'started' => 0.0, 'served' => 0.0];
                $out[$pid]['qty'] += $q;
                if (in_array($t['status'], ROF_UNSTARTED, true)) $out[$pid]['unstarted'] += $q; else $out[$pid]['started'] += $q;
                if ($t['status'] === 'served') $out[$pid]['served'] += $q;
            }
        }
        return $out;
    }
}

if (!function_exists('rofBilledSince')) {
    /**
     * product_id => qty already paid on a SPLIT bill of this table while the order is open
     * (part payments keep the table; the kitchen lines stay on the order).
     */
    function rofBilledSince(PDO $pdo, int $tableId, array $tickets): array
    {
        $since = null;
        foreach ($tickets as $t) if ($since === null || $t['created_at'] < $since) $since = $t['created_at'];
        if ($since === null) return [];
        $st = $pdo->prepare("SELECT si.product_id, SUM(si.quantity) AS q
                               FROM pos_sale_items si JOIN pos_sales s ON s.sale_id = si.sale_id
                              WHERE s.table_id = ? AND s.sale_date >= ? AND COALESCE(s.is_return_sale,0) = 0
                                AND s.sale_status NOT IN ('voided','void','cancelled')
                           GROUP BY si.product_id");
        $st->execute([$tableId, $since]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['product_id']] = (float)$r['q'];
        return $out;
    }
}

if (!function_exists('rofHoldOrder')) {
    /** A table's open held order (newest), or a held order by id. */
    function rofHoldOrder(PDO $pdo, ?int $tableId, ?int $holdId = null): ?array
    {
        if ($tableId) {
            $st = $pdo->prepare("SELECT * FROM pos_held_sales WHERE table_id = ? AND status = 'held' ORDER BY held_at DESC, hold_id DESC LIMIT 1");
            $st->execute([$tableId]);
        } elseif ($holdId) {
            $st = $pdo->prepare("SELECT * FROM pos_held_sales WHERE hold_id = ? LIMIT 1");
            $st->execute([$holdId]);
        } else {
            return null;
        }
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('rofLabel')) {
    function rofLabel(PDO $pdo, ?int $tableId, ?string $holdRef): string
    {
        if ($tableId) {
            $st = $pdo->prepare("SELECT table_number FROM restaurant_tables WHERE table_id = ?");
            $st->execute([$tableId]);
            $n = $st->fetchColumn();
            if ($n !== false && $n !== null) return mb_substr(t('Table') . ' ' . $n, 0, 80);
        }
        return mb_substr((string)($holdRef ?: t('Order')), 0, 80);
    }
}

// ─── Small writers ────────────────────────────────────────────────────────────

if (!function_exists('rofSetTicketStatus')) {
    function rofSetTicketStatus(PDO $pdo, int $ticketId, string $status, int $userId, array $extra = []): void
    {
        $set = ['status = ?', 'updated_at = NOW()']; $args = [$status];
        if ($status === 'queued' && !empty($extra['confirm'])) { $set[] = 'confirmed_by = ?'; $set[] = 'confirmed_at = NOW()'; $args[] = $userId; }
        if ($status === 'ready')     { $set[] = 'ready_at = COALESCE(ready_at, NOW())'; }
        if ($status === 'served')    { $set[] = 'served_by = COALESCE(served_by, ?)'; $set[] = 'served_at = COALESCE(served_at, NOW())'; $args[] = $userId; }
        if ($status === 'cancelled') { $set[] = 'cancelled_by = ?'; $set[] = 'cancelled_at = NOW()'; $set[] = 'cancel_reason = ?'; $set[] = 'closed_at = COALESCE(closed_at, NOW())';
                                       $args[] = $userId; $args[] = mb_substr((string)($extra['reason'] ?? ''), 0, 255) ?: null; }
        $args[] = $ticketId;
        $pdo->prepare("UPDATE kitchen_tickets SET " . implode(', ', $set) . " WHERE ticket_id = ?")->execute($args);
        $pdo->prepare("UPDATE kitchen_ticket_items SET status = ? WHERE ticket_id = ?")->execute([$status, $ticketId]);
    }
}

if (!function_exists('rofRecordCancellation')) {
    /**
     * One cancelled kitchen line. After the kitchen started, the food is waste:
     *   recipe dish → its ingredients leave stock; stock item → itself leaves stock;
     *   both post Dr Cost of Sales / Cr Inventory at cost (fixed accounts, nothing to choose);
     *   a dish with no recipe → recorded only (its ingredients were costed when bought).
     * @return int cancellation id
     */
    function rofRecordCancellation(PDO $pdo, array $c): int
    {
        $pid = (int)$c['product_id']; $qty = round((float)$c['quantity'], 3);
        $wid = (int)$c['warehouse_id']; $uid = (int)$c['user_id'];
        $after = ($c['stage'] ?? '') === 'after_kitchen';
        $p = rofProducts($pdo, [$pid])[$pid] ?? null;
        $pdo->prepare("INSERT INTO restaurant_order_cancellations
                         (warehouse_id, table_id, ticket_id, sale_id, product_id, quantity, stage, source, reason, est_cost, posted_cost, cancelled_by, approved_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, NOW())")
            ->execute([$wid, $c['table_id'] ?? null, $c['ticket_id'] ?? null, $c['sale_id'] ?? null, $pid, $qty,
                       $after ? 'after_kitchen' : 'before_kitchen', mb_substr((string)($c['source'] ?? 'cancel'), 0, 30),
                       mb_substr(trim((string)($c['reason'] ?? '')), 0, 255) ?: null, $uid ?: null, ($c['approved_by'] ?? null) ?: null]);
        $id = (int)$pdo->lastInsertId();
        if (!$after || !$p || $qty <= 0) return $id;

        $est = 0.0; $posted = 0.0; $entry = null;
        $outs = [];   // [product_id, qty, unit_cost]
        if (!empty($p['is_service']) && !empty($p['is_combo'])) {
            require_once __DIR__ . '/pos_combo_products.php';
            foreach (getComboComponents($pdo, $pid) as $comp) {
                $cq = (float)$comp['qty_per_unit'] * $qty;
                if ($cq <= 0) continue;
                $cc = (float)($pdo->query("SELECT COALESCE(cost_price,0) FROM products WHERE product_id = " . (int)$comp['component_product_id'])->fetchColumn() ?: 0);
                $outs[] = [(int)$comp['component_product_id'], $cq, $cc];
            }
        } elseif (empty($p['is_service'])) {
            $outs[] = [$pid, $qty, (float)$p['cost_price']];
        } else {
            $est = round($qty * (float)$p['purchase_price'], 2);   // plate estimate only — nothing posted
        }
        if ($outs) {
            require_once __DIR__ . '/stock_ledger.php';
            $bumpG = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ?, current_stock = current_stock - ? WHERE product_id = ?");
            $bumpW = $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity,0) - ? WHERE product_id = ? AND warehouse_id = ?");
            foreach ($outs as [$opid, $oq, $oc]) {
                $bumpG->execute([$oq, $oq, $opid]);
                $bumpW->execute([$oq, $opid, $wid]);
                recordStockMovement($pdo, [
                    'product_id' => $opid, 'warehouse_id' => $wid, 'movement_type' => 'damaged', 'quantity' => $oq,
                    'unit_cost' => $oc, 'reference_id' => $id, 'reference_type' => 'manual', 'reference_number' => 'WASTE-' . $id,
                    'created_by' => $uid, 'notes' => 'Kitchen waste: ' . ($p['product_name'] ?? '') . ' — ' . mb_substr((string)($c['reason'] ?? ''), 0, 120),
                ]);
                $est += $oq * $oc;
            }
            $est = round($est, 2);
            if ($est > 0) {
                require_once __DIR__ . '/gl_accounts.php';
                require_once __DIR__ . '/ledger_post.php';
                $cogs = cogsAccountId($pdo); $inv = inventoryAccountId($pdo);
                if ($cogs && $inv) {
                    $desc = 'Kitchen waste ' . 'WASTE-' . $id . ' — ' . ($p['product_name'] ?? 'item') . ' × ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
                    $entry = postLedgerEntry($pdo, $desc, [
                        ['account_id' => (int)$cogs, 'type' => 'debit',  'amount' => $est, 'description' => 'Kitchen waste'],
                        ['account_id' => (int)$inv,  'type' => 'credit', 'amount' => $est, 'description' => 'Kitchen waste'],
                    ], null, $id, 'kitchen_waste', date('Y-m-d'), $uid, $wid);
                    $posted = $est;
                }
            }
        }
        $pdo->prepare("UPDATE restaurant_order_cancellations SET est_cost = ?, posted_cost = ?, journal_entry_id = ? WHERE id = ?")
            ->execute([$est, $posted, $entry ?: null, $id]);
        return $id;
    }
}

if (!function_exists('rofReduceItem')) {
    /** Take $q off one ticket line; an empty ticket becomes cancelled. */
    function rofReduceItem(PDO $pdo, array $ticket, array $item, float $q, int $userId, string $reason): void
    {
        $left = round((float)$item['quantity'] - $q, 3);
        if ($left > 0.0005) {
            $pdo->prepare("UPDATE kitchen_ticket_items SET quantity = ? WHERE id = ?")->execute([$left, (int)$item['id']]);
        } else {
            $pdo->prepare("DELETE FROM kitchen_ticket_items WHERE id = ?")->execute([(int)$item['id']]);
        }
        $rest = (int)$pdo->query("SELECT COUNT(*) FROM kitchen_ticket_items WHERE ticket_id = " . (int)$ticket['ticket_id'] . " AND quantity > 0")->fetchColumn();
        if ($rest === 0) rofSetTicketStatus($pdo, (int)$ticket['ticket_id'], 'cancelled', $userId, ['reason' => $reason]);
        else $pdo->prepare("UPDATE kitchen_tickets SET updated_at = NOW() WHERE ticket_id = ?")->execute([(int)$ticket['ticket_id']]);
    }
}

if (!function_exists('rofPlanCut')) {
    /**
     * Which kitchen lines to take $qty of $productId from: not started first (waiting for cashier, then
     * queued — newest first), then started (ready, preparing, then served). No writes.
     * @return array<int,array{ticket:array,item:array,q:float,stage:string}>
     */
    function rofPlanCut(array $tickets, int $productId, float $qty): array
    {
        $order = ['pending' => 0, 'queued' => 1, 'ready' => 2, 'preparing' => 3, 'served' => 4];
        $cands = [];
        foreach ($tickets as $t) {
            if (!isset($order[$t['status']])) continue;
            foreach ($t['items'] as $i) {
                if ((int)$i['product_id'] !== $productId || (float)$i['quantity'] <= 0) continue;
                $cands[] = ['ticket' => $t, 'item' => $i, 'rank' => $order[$t['status']], 'tid' => (int)$t['ticket_id']];
            }
        }
        usort($cands, fn($a, $b) => $a['rank'] <=> $b['rank'] ?: $b['tid'] <=> $a['tid']);
        $plan = [];
        foreach ($cands as $c) {
            if ($qty <= 0.0005) break;
            $q = min($qty, (float)$c['item']['quantity']);
            $plan[] = ['ticket' => $c['ticket'], 'item' => $c['item'], 'q' => $q,
                       'stage' => in_array($c['ticket']['status'], ROF_UNSTARTED, true) ? 'before_kitchen' : 'after_kitchen'];
            $qty -= $q;
        }
        return $plan;
    }
}

if (!function_exists('rofCheckCutRights')) {
    /**
     * May the current user cancel these lines? Throws RofException when not.
     *   Team flow ON : waiting-for-cashier lines → the sender or a canceller; queued → a canceller;
     *                  started → a confirmer (cashier/manager) WITH a reason.
     *   Team flow OFF: anyone who can take orders (no new block); started lines get a default reason.
     * @return string the reason to record
     */
    function rofCheckCutRights(PDO $pdo, array $plan, int $userId, string $reason): string
    {
        if (!$plan) return $reason;
        $on = rofConfirmRequired($pdo);
        $names = [];
        $started = false; $queued = false; $foreignPending = false;
        foreach ($plan as $p) {
            $names[] = rtrim(rtrim(number_format($p['q'], 3, '.', ''), '0'), '.') . ' × ' . ($p['item']['product_name'] ?? '');
            if ($p['stage'] === 'after_kitchen') $started = true;
            elseif ($p['ticket']['status'] === 'queued') $queued = true;
            elseif ((int)($p['ticket']['sent_by'] ?? 0) !== $userId) $foreignPending = true;
        }
        $list = implode(', ', array_unique($names));
        if (!$on) {
            if (!rofCanTake($pdo)) throw new RofException(t('Permission denied'), 'cancel_not_allowed');
            return $reason !== '' ? $reason : ($started ? t('Removed from the order') : $reason);
        }
        if ($started) {
            if (!rofCanConfirm($pdo)) {
                throw new RofException(sprintf(t('The kitchen has already started: %s. Only the cashier can cancel food that is being cooked.'), $list),
                    'cancel_needs_approval', ['lines' => $list]);
            }
            if ($reason === '') {
                throw new RofException(sprintf(t('The kitchen has already started: %s. Give a reason to cancel it — it will be recorded as waste.'), $list),
                    'cancel_needs_reason', ['lines' => $list, 'can_approve' => true]);
            }
        }
        if (($queued || $foreignPending) && !rofCanCancelUnstarted($pdo)) {
            throw new RofException(sprintf(t('Only the cashier can remove food the kitchen already has: %s.'), $list), 'cancel_not_allowed', ['lines' => $list]);
        }
        return $reason;
    }
}

if (!function_exists('rofApplyCut')) {
    /** Cancel the planned lines (rights already checked). Returns cancellation ids. */
    function rofApplyCut(PDO $pdo, array $plan, int $userId, string $reason, string $source, ?int $saleId = null): array
    {
        $ids = [];
        foreach ($plan as $p) {
            // Re-read the line: an earlier step of this plan may have changed it.
            $cur = $pdo->prepare("SELECT * FROM kitchen_ticket_items WHERE id = ?");
            $cur->execute([(int)$p['item']['id']]);
            $item = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$item) continue;
            $q = min((float)$p['q'], (float)$item['quantity']);
            if ($q <= 0) continue;
            $item['product_name'] = $p['item']['product_name'] ?? '';
            rofReduceItem($pdo, $p['ticket'], $item, $q, $userId, $reason);
            $ids[] = rofRecordCancellation($pdo, [
                'warehouse_id' => (int)$p['ticket']['warehouse_id'], 'table_id' => $p['ticket']['table_id'] !== null ? (int)$p['ticket']['table_id'] : null,
                'ticket_id' => (int)$p['ticket']['ticket_id'], 'sale_id' => $saleId, 'product_id' => (int)$item['product_id'], 'quantity' => $q,
                'stage' => $p['stage'], 'source' => $source, 'reason' => $reason, 'user_id' => $userId,
                'approved_by' => $p['stage'] === 'after_kitchen' && rofConfirmRequired($pdo) ? $userId : null,
            ]);
        }
        return $ids;
    }
}

if (!function_exists('rofReconcile')) {
    /**
     * Make the kitchen match a cart that has FEWER kitchen dishes than were sent (a dish removed on
     * the till, a smaller bill). Never adds — sending adds. Rights per rofCheckCutRights().
     * $ctx: warehouse_id, table_id|hold_id, items, user_id, reason, source, [sale_id]
     * @return array cancellation ids
     */
    function rofReconcile(PDO $pdo, array $ctx): array
    {
        if (!rofReady($pdo)) return [];
        $wid = (int)$ctx['warehouse_id']; $tid = !empty($ctx['table_id']) ? (int)$ctx['table_id'] : null;
        $hid = !empty($ctx['hold_id']) ? (int)$ctx['hold_id'] : null;
        $tickets = rofCurrentTickets($pdo, $wid, $tid, $tid ? null : $hid, true);
        if (!$tickets) return [];
        $sent = rofSentByProduct($tickets);
        $billed = $tid ? rofBilledSince($pdo, $tid, $tickets) : [];
        $snap = rofSnapshot($pdo, (array)($ctx['items'] ?? []));
        $plan = [];
        foreach ($sent as $pid => $s) {
            $extra = round($s['qty'] - ($billed[$pid] ?? 0) - ($snap[$pid]['qty'] ?? 0), 3);
            if ($extra > 0.0005) $plan = array_merge($plan, rofPlanCut($tickets, $pid, $extra));
        }
        if (!$plan) return [];
        $uid = (int)$ctx['user_id'];
        $reason = rofCheckCutRights($pdo, $plan, $uid, trim((string)($ctx['reason'] ?? '')));
        return rofApplyCut($pdo, $plan, $uid, $reason, (string)($ctx['source'] ?? 'removed'), $ctx['sale_id'] ?? null);
    }
}

// ─── Sending ──────────────────────────────────────────────────────────────────

if (!function_exists('rofSendHold')) {
    /**
     * Send a held order (a table's, or a table-less pickup) to the kitchen: only what the kitchen does
     * not have yet. Team flow ON + sender cannot confirm → new lines wait for the cashier (pending).
     * Lines removed on the till since the last send are taken off (rofReconcile rules).
     * @return array{ticket_ids:int[], status:string, added:float, label:string, nothing_new:bool}
     */
    function rofSendHold(PDO $pdo, int $holdId, int $warehouseId, int $userId, string $reason = ''): array
    {
        $hold = rofHoldOrder($pdo, null, $holdId);
        if (!$hold || $hold['status'] !== 'held' || (int)$hold['warehouse_id'] !== $warehouseId) {
            throw new RofException(t('Held order not found for this table.'), 'not_found');
        }
        $tableId = $hold['table_id'] !== null ? (int)$hold['table_id'] : null;
        $items = json_decode((string)($hold['items_data'] ?? '[]'), true) ?: [];
        $label = rofLabel($pdo, $tableId, (string)$hold['hold_reference']);
        $snap = rofSnapshot($pdo, $items);
        if (!$items) throw new RofException(t('There are no items to send to the kitchen.'), 'empty');
        if (!$snap && !rofCurrentTickets($pdo, $warehouseId, $tableId, $tableId ? null : $holdId)) {
            throw new RofException(t('None of the items on this order route to a kitchen station.'), 'no_kitchen_items');
        }

        // 1. Removed dishes first (may refuse).
        rofReconcile($pdo, ['warehouse_id' => $warehouseId, 'table_id' => $tableId, 'hold_id' => $holdId, 'items' => $items,
                            'user_id' => $userId, 'reason' => $reason, 'source' => 'removed']);

        // 2. What the kitchen does not have yet.
        $tickets = rofCurrentTickets($pdo, $warehouseId, $tableId, $tableId ? null : $holdId, true);
        $sent = rofSentByProduct($tickets);
        $billed = $tableId ? rofBilledSince($pdo, $tableId, $tickets) : [];
        $status = (rofConfirmRequired($pdo) && !rofCanConfirm($pdo)) ? 'pending' : 'queued';
        $byStation = [];
        $added = 0.0;
        foreach ($snap as $pid => $s) {
            $need = round($s['qty'] - (($sent[$pid]['qty'] ?? 0) - ($billed[$pid] ?? 0)), 3);
            if ($need <= 0.0005) continue;
            // Modifiers: the snapshot lines whose own quantity the kitchen does not have yet (newest last).
            $sentMods = [];
            foreach ($tickets as $t) {
                if ($t['status'] === 'cancelled') continue;
                foreach ($t['items'] as $i) if ((int)$i['product_id'] === $pid) $sentMods[(string)$i['modifiers_summary']] = ($sentMods[(string)$i['modifiers_summary']] ?? 0) + (float)$i['quantity'];
            }
            $left = $need;
            foreach (array_reverse($s['lines']) as [$mods, $lq]) {
                if ($left <= 0.0005) break;
                $have = $sentMods[(string)$mods] ?? 0;
                $take = min($left, max(0, $lq - $have));
                if ($have > 0) $sentMods[(string)$mods] = max(0, $have - $lq);
                if ($take > 0.0005) { $byStation[$s['station']][] = [$pid, $take, $mods]; $left -= $take; }
            }
            if ($left > 0.0005) $byStation[$s['station']][] = [$pid, $left, $s['lines'][count($s['lines']) - 1][0]];
            $added += $need;
        }

        $ticketIds = [];
        foreach ($byStation as $stationId => $lines) {
            // Same area, same stage, still open → add to it instead of a second ticket.
            $tid = null;
            foreach (array_reverse($tickets) as $t) {
                if ((int)$t['station_id'] === (int)$stationId && $t['status'] === $status) { $tid = (int)$t['ticket_id']; break; }
            }
            if (!$tid) {
                $pdo->prepare("INSERT INTO kitchen_tickets (hold_id, sale_id, order_label, warehouse_id, station_id, table_id, status, sent_by, created_at, updated_at)
                               VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$holdId, $label, $warehouseId, (int)$stationId, $tableId, $status, $userId]);
                $tid = (int)$pdo->lastInsertId();
                if ($status === 'queued' && rofConfirmRequired($pdo)) {
                    $pdo->prepare("UPDATE kitchen_tickets SET confirmed_by = ?, confirmed_at = NOW() WHERE ticket_id = ?")->execute([$userId, $tid]);
                }
            } else {
                $pdo->prepare("UPDATE kitchen_tickets SET updated_at = NOW(), sent_by = COALESCE(sent_by, ?) WHERE ticket_id = ?")->execute([$userId, $tid]);
            }
            // The same dish with the same note on a ticket the kitchen has not started → one line (2 + 1 = 3).
            $same = $pdo->prepare("SELECT id FROM kitchen_ticket_items WHERE ticket_id = ? AND product_id = ? AND modifiers_summary <=> ? ORDER BY id LIMIT 1");
            $bump = $pdo->prepare("UPDATE kitchen_ticket_items SET quantity = quantity + ? WHERE id = ?");
            $ins  = $pdo->prepare("INSERT INTO kitchen_ticket_items (ticket_id, product_id, quantity, modifiers_summary, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            foreach ($lines as [$pid, $q, $mods]) {
                $same->execute([$tid, $pid, $mods]);
                $sid = (int)$same->fetchColumn();
                if ($sid) $bump->execute([round($q, 3), $sid]);
                else $ins->execute([$tid, $pid, round($q, 3), $mods, $status]);
            }
            $ticketIds[] = $tid;
        }
        // Every open ticket of the order points at the newest saved order and carries its label.
        if ($tableId) {
            $pdo->prepare("UPDATE kitchen_tickets SET hold_id = ?, order_label = ? WHERE table_id = ? AND warehouse_id = ? AND closed_at IS NULL AND sale_id IS NULL")
                ->execute([$holdId, $label, $tableId, $warehouseId]);
        }
        $ticketIds = array_values(array_unique($ticketIds));
        if ($status === 'pending' && $ticketIds) rofNotifyToConfirm($pdo, $ticketIds[0], $warehouseId, $label);
        return ['ticket_ids' => $ticketIds, 'status' => $status, 'added' => $added, 'label' => $label, 'nothing_new' => !$ticketIds];
    }
}

if (!function_exists('rofSendSale')) {
    /**
     * A paid take-away / delivery sale goes to the kitchen (already confirmed: the cashier took the
     * money). Sent once; a repeat refreshes a ticket the kitchen has not started.
     * @return int[] ticket ids
     */
    function rofSendSale(PDO $pdo, int $saleId, int $warehouseId, int $userId): array
    {
        $st = $pdo->prepare("SELECT sale_id, receipt_number, sale_type FROM pos_sales WHERE sale_id = ? AND warehouse_id = ?");
        $st->execute([$saleId, $warehouseId]);
        $sale = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sale) throw new RofException(t('Sale not found.'), 'not_found');
        $lines = $pdo->prepare("SELECT sale_item_id, product_id, quantity, description FROM pos_sale_items WHERE sale_id = ?");
        $lines->execute([$saleId]);
        $mods = $pdo->prepare("SELECT option_name FROM pos_sale_item_modifiers WHERE sale_item_id = ?");
        $items = [];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            $mods->execute([(int)$ln['sale_item_id']]);
            $items[] = ['product_id' => (int)$ln['product_id'], 'quantity' => (float)$ln['quantity'],
                        'modifiers' => array_map(fn($n) => ['option_name' => $n], $mods->fetchAll(PDO::FETCH_COLUMN)),
                        'note' => (string)($ln['description'] ?? '')];
        }
        $snap = rofSnapshot($pdo, $items);
        if (!$items) throw new RofException(t('There are no items to send to the kitchen.'), 'empty');
        if (!$snap) throw new RofException(t('None of the items on this order route to a kitchen station.'), 'no_kitchen_items');
        $types = ['take_away' => t('Take away'), 'delivery' => t('Delivery'), 'dine_in' => t('Dine in')];
        $label = mb_substr(($types[(string)($sale['sale_type'] ?? '')] ?? t('Order')) . ' · ' . $sale['receipt_number'], 0, 80);
        $byStation = [];
        foreach ($snap as $pid => $s) foreach ($s['lines'] as [$m, $q]) $byStation[$s['station']][] = [$pid, $q, $m];
        $ids = [];
        foreach ($byStation as $stationId => $ls) {
            $ex = $pdo->prepare("SELECT ticket_id, status FROM kitchen_tickets WHERE station_id = ? AND sale_id = ? ORDER BY ticket_id DESC LIMIT 1");
            $ex->execute([(int)$stationId, $saleId]);
            $row = $ex->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $tid = (int)$row['ticket_id'];
                $ids[] = $tid;
                if (!in_array($row['status'], ROF_UNSTARTED, true)) continue;   // the kitchen started: leave it
                $pdo->prepare("DELETE FROM kitchen_ticket_items WHERE ticket_id = ?")->execute([$tid]);
                $pdo->prepare("UPDATE kitchen_tickets SET order_label = ?, updated_at = NOW() WHERE ticket_id = ?")->execute([$label, $tid]);
            } else {
                $pdo->prepare("INSERT INTO kitchen_tickets (hold_id, sale_id, order_label, warehouse_id, station_id, table_id, status, sent_by, created_at, updated_at)
                               VALUES (NULL, ?, ?, ?, ?, NULL, 'queued', ?, NOW(), NOW())")->execute([$saleId, $label, $warehouseId, (int)$stationId, $userId]);
                $tid = (int)$pdo->lastInsertId();
                $ids[] = $tid;
            }
            $ins = $pdo->prepare("INSERT INTO kitchen_ticket_items (ticket_id, product_id, quantity, modifiers_summary, status, created_at) VALUES (?, ?, ?, ?, 'queued', NOW())");
            foreach ($ls as [$pid, $q, $m]) $ins->execute([$tid, $pid, round($q, 3), $m]);
        }
        return $ids;
    }
}

// ─── Cashier: confirm / return ───────────────────────────────────────────────

if (!function_exists('rofPendingTickets')) {
    function rofPendingTickets(PDO $pdo, int $warehouseId, ?int $tableId, ?int $holdId): array
    {
        return array_values(array_filter(rofCurrentTickets($pdo, $warehouseId, $tableId, $tableId ? null : $holdId, true), fn($t) => $t['status'] === 'pending'));
    }
}

if (!function_exists('rofConfirm')) {
    /** The cashier confirms a waiting order → the kitchen sees it. @return int[] ticket ids */
    function rofConfirm(PDO $pdo, int $warehouseId, ?int $tableId, ?int $holdId, int $userId): array
    {
        if (!rofCanConfirm($pdo)) throw new RofException(t('Only the cashier can confirm orders.'), 'not_allowed');
        $pending = rofPendingTickets($pdo, $warehouseId, $tableId, $holdId);
        if (!$pending) throw new RofException(t('This order is no longer waiting — someone already confirmed or returned it.'), 'nothing_pending');
        $ids = [];
        foreach ($pending as $t) { rofSetTicketStatus($pdo, (int)$t['ticket_id'], 'queued', $userId, ['confirm' => true]); $ids[] = (int)$t['ticket_id']; }
        return $ids;
    }
}

if (!function_exists('rofReturn')) {
    /** The cashier does not confirm: the waiting lines are cancelled (with the reason) and the waiter is told. */
    function rofReturn(PDO $pdo, int $warehouseId, ?int $tableId, ?int $holdId, int $userId, string $reason): array
    {
        if (!rofCanConfirm($pdo)) throw new RofException(t('Only the cashier can return orders.'), 'not_allowed');
        $reason = trim($reason);
        if ($reason === '') throw new RofException(t('Tell the waiter why the order is returned.'), 'reason_required');
        $pending = rofPendingTickets($pdo, $warehouseId, $tableId, $holdId);
        if (!$pending) throw new RofException(t('This order is no longer waiting — someone already confirmed or returned it.'), 'nothing_pending');
        $ids = [];
        foreach ($pending as $t) {
            foreach ($t['items'] as $i) {
                rofRecordCancellation($pdo, ['warehouse_id' => $warehouseId, 'table_id' => $t['table_id'] !== null ? (int)$t['table_id'] : null,
                    'ticket_id' => (int)$t['ticket_id'], 'product_id' => (int)$i['product_id'], 'quantity' => (float)$i['quantity'],
                    'stage' => 'before_kitchen', 'source' => 'returned', 'reason' => $reason, 'user_id' => $userId]);
            }
            rofSetTicketStatus($pdo, (int)$t['ticket_id'], 'cancelled', $userId, ['reason' => $reason]);
            $ids[] = (int)$t['ticket_id'];
        }
        rofNotifyReturned($pdo, $pending[0], $reason);
        return $ids;
    }
}

// ─── Kitchen + waiter: advance / received ────────────────────────────────────

if (!function_exists('rofAdvance')) {
    /**
     * Move a ticket forward. queued → preparing → ready: the kitchen. ready → served ("Received"):
     * team flow ON → the waiter/cashier (whoever takes orders), not the cook; OFF → the kitchen too, as before.
     */
    function rofAdvance(PDO $pdo, int $ticketId, string $to, int $userId): array
    {
        $seq = ['queued' => 1, 'preparing' => 2, 'ready' => 3, 'served' => 4];
        if (!isset($seq[$to])) throw new RofException(t('Invalid request.'), 'invalid');
        $st = $pdo->prepare("SELECT * FROM kitchen_tickets WHERE ticket_id = ? FOR UPDATE");
        $st->execute([$ticketId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t || !userCan('warehouse', (int)$t['warehouse_id'])) throw new RofException(t('Ticket not found.'), 'not_found');
        if ($t['status'] === 'pending')   throw new RofException(t('This order is waiting for the cashier to confirm it.'), 'pending');
        if ($t['status'] === 'cancelled') throw new RofException(t('This order was cancelled.'), 'cancelled');
        if ($seq[$to] < $seq[$t['status']]) throw new RofException(t('A kitchen ticket cannot move backward — it can only advance.'), 'backward');
        if ($to === $t['status']) return $t;
        $on = rofConfirmRequired($pdo);
        if ($to === 'served') {
            $ok = $on ? rofCanTake($pdo) : (rofCanKitchen($pdo) || rofCanTake($pdo));
            if (!$ok) throw new RofException(t('The waiter marks the food received when the kitchen hands it over.'), 'not_allowed');
            if ($on && $t['status'] !== 'ready' && !rofCanKitchen($pdo)) {
                throw new RofException(t('The kitchen has not marked this order ready yet.'), 'not_ready');
            }
        } elseif (!rofCanKitchen($pdo)) {
            throw new RofException(t('Only the kitchen can change this.'), 'not_allowed');
        }
        rofSetTicketStatus($pdo, $ticketId, $to, $userId);
        $t['status'] = $to;
        return $t;
    }
}

// ─── Cancelling lines and orders ─────────────────────────────────────────────

if (!function_exists('rofRemoveFromHold')) {
    /** Take $qty of a product off a held order's lines (exact note first), totals recomputed like Join does. */
    function rofRemoveFromHold(PDO $pdo, array $hold, int $productId, float $qty, ?string $mods): void
    {
        $items = json_decode((string)$hold['items_data'], true) ?: [];
        $idx = array_keys(array_filter($items, fn($i) => is_array($i) && (int)($i['product_id'] ?? 0) === $productId));
        usort($idx, function ($a, $b) use ($items, $mods) {
            $ma = rofLineMods($items[$a]) === $mods ? 0 : 1; $mb = rofLineMods($items[$b]) === $mods ? 0 : 1;
            return $ma <=> $mb ?: $b <=> $a;
        });
        foreach ($idx as $i) {
            if ($qty <= 0.0005) break;
            $have = (float)($items[$i]['quantity'] ?? 0);
            $take = min($have, $qty);
            $items[$i]['quantity'] = round($have - $take, 3);
            $qty -= $take;
        }
        $items = array_values(array_filter($items, fn($i) => !is_array($i) || (float)($i['quantity'] ?? 0) > 0.0005));
        $sub = 0.0; $tax = 0.0;
        foreach ($items as $it) {
            $sub += (float)($it['price'] ?? 0) * (float)($it['quantity'] ?? 0);
            $tax += (float)($it['discounted_price'] ?? $it['price'] ?? 0) * (float)($it['quantity'] ?? 0) * ((float)($it['tax_rate'] ?? 0) / 100);
        }
        $pdo->prepare("UPDATE pos_held_sales SET items_data = ?, item_count = ?, subtotal = ?, tax_amount = ?, total_amount = ? WHERE hold_id = ?")
            ->execute([json_encode($items), count($items), $sub, $tax, $sub + $tax, (int)$hold['hold_id']]);
    }
}

if (!function_exists('rofCancelLine')) {
    /**
     * Cancel (part of) one kitchen line from the till's kitchen list. The dish also leaves the table's bill.
     * @return array{cancelled:float, waste:bool}
     */
    function rofCancelLine(PDO $pdo, int $itemId, float $qty, int $userId, string $reason): array
    {
        $st = $pdo->prepare("SELECT kti.*, p.product_name FROM kitchen_ticket_items kti LEFT JOIN products p ON p.product_id = kti.product_id WHERE kti.id = ?");
        $st->execute([$itemId]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new RofException(t('This line is no longer on the order.'), 'not_found');
        $tk = $pdo->prepare("SELECT * FROM kitchen_tickets WHERE ticket_id = ? FOR UPDATE");
        $tk->execute([(int)$item['ticket_id']]);
        $ticket = $tk->fetch(PDO::FETCH_ASSOC);
        if (!$ticket || !userCan('warehouse', (int)$ticket['warehouse_id'])) throw new RofException(t('Ticket not found.'), 'not_found');
        if ($ticket['status'] === 'cancelled' || $ticket['closed_at'] !== null || $ticket['sale_id'] !== null) {
            throw new RofException(t('This order is closed. A paid order is cancelled by voiding its receipt.'), 'closed');
        }
        $qty = $qty > 0 ? min($qty, (float)$item['quantity']) : (float)$item['quantity'];
        $ticket['items'] = [$item];
        $plan = [['ticket' => $ticket, 'item' => $item, 'q' => $qty,
                  'stage' => in_array($ticket['status'], ROF_UNSTARTED, true) ? 'before_kitchen' : 'after_kitchen']];
        $reason = rofCheckCutRights($pdo, $plan, $userId, trim($reason));
        rofApplyCut($pdo, $plan, $userId, $reason, 'cancel');
        $hold = rofHoldOrder($pdo, $ticket['table_id'] !== null ? (int)$ticket['table_id'] : null, $ticket['table_id'] === null ? (int)$ticket['hold_id'] : null);
        if ($hold && $hold['status'] === 'held') rofRemoveFromHold($pdo, $hold, (int)$item['product_id'], $qty, $item['modifiers_summary']);
        return ['cancelled' => $qty, 'waste' => $plan[0]['stage'] === 'after_kitchen'];
    }
}

if (!function_exists('rofCancelOrder')) {
    /**
     * The customer leaves before paying: every kitchen line of the table's order is cancelled
     * (rights per line stage), the held order is cancelled and the table is free again.
     */
    function rofCancelOrder(PDO $pdo, int $warehouseId, int $tableId, int $userId, string $reason): array
    {
        $tickets = rofCurrentTickets($pdo, $warehouseId, $tableId, null, true);
        $plan = [];
        foreach ($tickets as $t) {
            if ($t['status'] === 'cancelled') continue;
            foreach ($t['items'] as $i) {
                if ((float)$i['quantity'] <= 0) continue;
                $plan[] = ['ticket' => $t, 'item' => $i, 'q' => (float)$i['quantity'],
                           'stage' => in_array($t['status'], ROF_UNSTARTED, true) ? 'before_kitchen' : 'after_kitchen'];
            }
        }
        $reason = trim($reason);
        if (rofConfirmRequired($pdo) && !rofCanCancelUnstarted($pdo)) {
            throw new RofException(t('Only the cashier can cancel a whole order.'), 'cancel_not_allowed');
        }
        if (!rofConfirmRequired($pdo) && !rofCanTake($pdo)) throw new RofException(t('Permission denied'), 'cancel_not_allowed');
        $reason = rofCheckCutRights($pdo, $plan, $userId, $reason);
        if ($reason === '') $reason = t('Customer cancelled');
        $ids = rofApplyCut($pdo, $plan, $userId, $reason, 'order_cancel');
        $pdo->prepare("UPDATE kitchen_tickets SET closed_at = COALESCE(closed_at, NOW()) WHERE table_id = ? AND warehouse_id = ? AND closed_at IS NULL AND sale_id IS NULL")
            ->execute([$tableId, $warehouseId]);
        $pdo->prepare("UPDATE pos_held_sales SET status = 'cancelled' WHERE table_id = ? AND status = 'held'")->execute([$tableId]);
        $pdo->prepare("UPDATE restaurant_tables SET status = 'available', updated_at = NOW() WHERE table_id = ?")->execute([$tableId]);
        return $ids;
    }
}

// ─── The bill ─────────────────────────────────────────────────────────────────

if (!function_exists('rofGuardBill')) {
    /**
     * Before a dine-in table's bill is paid (inside process_sale's transaction):
     *  1. a full bill with fewer kitchen dishes than were sent → those lines are cancelled
     *     (rofReconcile rules: cooked food needs the cashier + a reason in the team flow);
     *  2. team flow ON → every kitchen dish being paid must have been served. A payer who also works
     *     the kitchen (owner / one person) may confirm "mark all served" ($servedOk) instead.
     * $ctx: warehouse_id, table_id, items, keep_table, user_id, served_ok, reason
     */
    function rofGuardBill(PDO $pdo, array $ctx): void
    {
        if (!rofReady($pdo) || empty($ctx['table_id'])) return;
        $wid = (int)$ctx['warehouse_id']; $tid = (int)$ctx['table_id']; $uid = (int)$ctx['user_id'];
        if (empty($ctx['keep_table'])) {
            rofReconcile($pdo, ['warehouse_id' => $wid, 'table_id' => $tid, 'items' => (array)$ctx['items'], 'user_id' => $uid,
                                'reason' => (string)($ctx['reason'] ?? ''), 'source' => 'bill']);
        }
        if (!rofConfirmRequired($pdo)) return;
        $tickets = rofCurrentTickets($pdo, $wid, $tid, null, true);
        $sent = rofSentByProduct($tickets);
        $billed = rofBilledSince($pdo, $tid, $tickets);
        $snap = rofSnapshot($pdo, (array)$ctx['items']);
        $short = [];
        foreach ($snap as $pid => $s) {
            $avail = ($sent[$pid]['served'] ?? 0) - ($billed[$pid] ?? 0);
            if ($s['qty'] - $avail > 0.0005) $short[$pid] = $s['qty'] - max(0, $avail);
        }
        if (!$short) return;
        $prods = rofProducts($pdo, array_keys($short));
        $names = [];
        foreach ($short as $pid => $q) $names[] = rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.') . ' × ' . ($prods[$pid]['product_name'] ?? '#' . $pid);
        $list = implode(', ', $names);
        $canMark = rofCanKitchen($pdo);
        if (empty($ctx['served_ok']) || !$canMark) {
            throw new RofException(sprintf(t('Not served yet: %s. The bill can be closed once the waiter has received the food from the kitchen.'), $list),
                'kitchen_not_served', ['lines' => $list, 'can_mark_served' => $canMark]);
        }
        foreach ($tickets as $t) {
            if (in_array($t['status'], ['pending', 'queued', 'preparing', 'ready'], true)) {
                if ($t['status'] === 'pending') $pdo->prepare("UPDATE kitchen_tickets SET confirmed_by = ?, confirmed_at = NOW() WHERE ticket_id = ?")->execute([$uid, (int)$t['ticket_id']]);
                rofSetTicketStatus($pdo, (int)$t['ticket_id'], 'served', $uid);
            }
        }
    }
}

if (!function_exists('rofCloseOnBill')) {
    /**
     * The table's bill is closed (not a split): its open tickets belong to that sale now and leave
     * the table's order. Ready food is served. Food still cooking stays on the kitchen screen
     * (customer paid first — the kitchen finishes it).
     */
    function rofCloseOnBill(PDO $pdo, int $warehouseId, int $tableId, int $saleId, int $userId): int
    {
        if (!rofReady($pdo)) return 0;
        $tickets = rofCurrentTickets($pdo, $warehouseId, $tableId, null, true);
        foreach ($tickets as $t) if ($t['status'] === 'ready') rofSetTicketStatus($pdo, (int)$t['ticket_id'], 'served', $userId);
        $st = $pdo->prepare("UPDATE kitchen_tickets SET sale_id = ?, closed_at = NOW() WHERE table_id = ? AND warehouse_id = ? AND closed_at IS NULL AND sale_id IS NULL");
        $st->execute([$saleId, $tableId, $warehouseId]);
        return $st->rowCount();
    }
}

if (!function_exists('rofCloseHoldOnSale')) {
    /** A table-less held order (online pickup) was paid: its tickets belong to the sale now. */
    function rofCloseHoldOnSale(PDO $pdo, int $holdId, int $saleId): int
    {
        if (!rofReady($pdo) || $holdId <= 0) return 0;
        $pdo->prepare("UPDATE kitchen_tickets SET status = 'served', served_at = COALESCE(served_at, NOW()) WHERE hold_id = ? AND table_id IS NULL AND closed_at IS NULL AND status = 'ready'")->execute([$holdId]);
        $st = $pdo->prepare("UPDATE kitchen_tickets SET sale_id = ?, closed_at = NOW() WHERE hold_id = ? AND table_id IS NULL AND closed_at IS NULL AND sale_id IS NULL");
        $st->execute([$saleId, $holdId]);
        return $st->rowCount();
    }
}

if (!function_exists('rofCancelTickets')) {
    /** Cancel every not-served line of these tickets: not started → cancelled; started → cancelled + waste. */
    function rofCancelTickets(PDO $pdo, array $tickets, int $userId, string $reason, string $source, ?int $saleId = null): array
    {
        $plan = [];
        foreach ($tickets as $t) {
            if (in_array($t['status'], ['served', 'cancelled'], true)) continue;
            foreach ($t['items'] as $i) {
                if ((float)$i['quantity'] <= 0) continue;
                $plan[] = ['ticket' => $t, 'item' => $i, 'q' => (float)$i['quantity'],
                           'stage' => in_array($t['status'], ROF_UNSTARTED, true) ? 'before_kitchen' : 'after_kitchen'];
            }
        }
        return $plan ? rofApplyCut($pdo, $plan, $userId, $reason, $source, $saleId) : [];
    }
}

if (!function_exists('rofTicketsWhere')) {
    function rofTicketsWhere(PDO $pdo, string $where, array $args): array
    {
        $st = $pdo->prepare("SELECT * FROM kitchen_tickets WHERE $where FOR UPDATE");
        $st->execute($args);
        $tickets = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$tickets) return [];
        $it = $pdo->prepare("SELECT kti.*, p.product_name FROM kitchen_ticket_items kti LEFT JOIN products p ON p.product_id = kti.product_id WHERE kti.ticket_id = ?");
        foreach ($tickets as &$t) { $it->execute([(int)$t['ticket_id']]); $t['items'] = $it->fetchAll(PDO::FETCH_ASSOC); }
        unset($t);
        return $tickets;
    }
}

if (!function_exists('rofOnVoid')) {
    /** A paid sale is voided (inside the void's transaction): its food still in the kitchen is cancelled. */
    function rofOnVoid(PDO $pdo, int $saleId, int $userId): array
    {
        if (!rofReady($pdo)) return [];
        // A table-less held order (online pickup) re-opens into Held orders on void to be paid again
        // (oorOnVoid) — its food is still wanted, so only table and take-away tickets are cancelled.
        return rofCancelTickets($pdo, rofTicketsWhere($pdo, 'sale_id = ? AND NOT (table_id IS NULL AND hold_id IS NOT NULL)', [$saleId]),
            $userId, t('Sale voided'), 'void', $saleId);
    }
}

if (!function_exists('rofOnPickupCancelled')) {
    /** An accepted pickup order was never collected: its kitchen lines are cancelled. */
    function rofOnPickupCancelled(PDO $pdo, int $holdId, int $userId, string $reason): array
    {
        if (!rofReady($pdo) || $holdId <= 0) return [];
        $tickets = rofTicketsWhere($pdo, 'hold_id = ? AND table_id IS NULL AND closed_at IS NULL AND sale_id IS NULL', [$holdId]);
        $ids = rofCancelTickets($pdo, $tickets, $userId, $reason !== '' ? $reason : t('Not collected'), 'pickup');
        $pdo->prepare("UPDATE kitchen_tickets SET closed_at = COALESCE(closed_at, NOW()) WHERE hold_id = ? AND table_id IS NULL AND closed_at IS NULL")->execute([$holdId]);
        return $ids;
    }
}

if (!function_exists('rofMoveOrder')) {
    /** Move / Join: every open ticket of the order follows it to the other table. */
    function rofMoveOrder(PDO $pdo, int $fromTable, int $toTable): void
    {
        if (rofReady($pdo)) {
            $pdo->prepare("UPDATE kitchen_tickets SET table_id = ?, updated_at = NOW() WHERE table_id = ? AND closed_at IS NULL AND sale_id IS NULL")->execute([$toTable, $fromTable]);
        } else {
            $pdo->prepare("UPDATE kitchen_tickets SET table_id = ?, updated_at = NOW() WHERE table_id = ? AND status IN ('queued','preparing','ready')")->execute([$toTable, $fromTable]);
        }
    }
}

// ─── Screens ──────────────────────────────────────────────────────────────────

if (!function_exists('rofOrderView')) {
    /** The kitchen lines of a table's (or held order's) open order, for the till's kitchen list. */
    function rofOrderView(PDO $pdo, int $warehouseId, ?int $tableId, ?int $holdId): array
    {
        $tickets = rofCurrentTickets($pdo, $warehouseId, $tableId, $tableId ? null : $holdId);
        $lines = []; $counts = ['pending' => 0, 'cooking' => 0, 'ready' => 0, 'served' => 0];
        foreach ($tickets as $t) {
            if ($t['status'] === 'cancelled') continue;
            foreach ($t['items'] as $i) {
                if ((float)$i['quantity'] <= 0) continue;
                $lines[] = ['item_id' => (int)$i['id'], 'ticket_id' => (int)$t['ticket_id'], 'product_id' => (int)$i['product_id'],
                            'name' => (string)($i['product_name'] ?? ''), 'qty' => (float)$i['quantity'], 'note' => (string)($i['modifiers_summary'] ?? ''),
                            'status' => $t['status'], 'mine' => (int)($t['sent_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0)];
                $k = $t['status'] === 'pending' ? 'pending' : (in_array($t['status'], ['queued', 'preparing'], true) ? 'cooking' : $t['status']);
                $counts[$k] += (float)$i['quantity'];
            }
        }
        return ['lines' => $lines, 'counts' => $counts, 'rights' => rofRights($pdo)];
    }
}

if (!function_exists('rofPendingOrders')) {
    /** Orders waiting for the cashier in one shop, oldest first. */
    function rofPendingOrders(PDO $pdo, int $warehouseId): array
    {
        if (!rofReady($pdo)) return [];
        $st = $pdo->prepare("SELECT kt.ticket_id, kt.table_id, kt.hold_id, kt.order_label, kt.created_at, kt.sent_by,
                                    TIMESTAMPDIFF(MINUTE, kt.created_at, NOW()) AS minutes,
                                    TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS waiter, u.username
                               FROM kitchen_tickets kt LEFT JOIN users u ON u.user_id = kt.sent_by
                              WHERE kt.warehouse_id = ? AND kt.status = 'pending' AND kt.closed_at IS NULL
                           ORDER BY kt.created_at, kt.ticket_id");
        $st->execute([$warehouseId]);
        $orders = [];
        $it = $pdo->prepare("SELECT kti.quantity, kti.modifiers_summary, p.product_name, COALESCE(p.selling_price,0) AS price
                               FROM kitchen_ticket_items kti LEFT JOIN products p ON p.product_id = kti.product_id WHERE kti.ticket_id = ? ORDER BY kti.id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $key = $t['table_id'] !== null ? 't' . (int)$t['table_id'] : 'h' . (int)$t['hold_id'];
            if (!isset($orders[$key])) {
                $orders[$key] = ['key' => $key, 'table_id' => $t['table_id'] !== null ? (int)$t['table_id'] : null, 'hold_id' => (int)$t['hold_id'],
                                 'label' => (string)$t['order_label'], 'waiter' => trim((string)$t['waiter']) ?: (string)$t['username'],
                                 'minutes' => (int)$t['minutes'], 'items' => [], 'value' => 0.0];
            }
            $it->execute([(int)$t['ticket_id']]);
            foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $orders[$key]['items'][] = ['name' => (string)$i['product_name'], 'qty' => (float)$i['quantity'], 'note' => (string)($i['modifiers_summary'] ?? '')];
                $orders[$key]['value'] += (float)$i['quantity'] * (float)$i['price'];
            }
        }
        return array_values($orders);
    }
}

if (!function_exists('rofPendingCount')) {
    function rofPendingCount(PDO $pdo, array $warehouseIds): int
    {
        $warehouseIds = array_values(array_filter(array_map('intval', $warehouseIds)));
        if (!rofReady($pdo) || !$warehouseIds) return 0;
        $ph = implode(',', array_fill(0, count($warehouseIds), '?'));
        $st = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(CONCAT('t', table_id), CONCAT('h', hold_id))) FROM kitchen_tickets
                              WHERE status = 'pending' AND closed_at IS NULL AND warehouse_id IN ($ph)");
        $st->execute($warehouseIds);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('rofTableKitchenSummary')) {
    /** table_id => ['pending' => n, 'cooking' => n, 'ready' => n] for one shop (Tables board badges). */
    function rofTableKitchenSummary(PDO $pdo, int $warehouseId): array
    {
        if (!rofReady($pdo)) return [];
        $st = $pdo->prepare("SELECT kt.table_id, kt.status, SUM(kti.quantity) AS q
                               FROM kitchen_tickets kt JOIN kitchen_ticket_items kti ON kti.ticket_id = kt.ticket_id
                              WHERE kt.warehouse_id = ? AND kt.table_id IS NOT NULL AND kt.closed_at IS NULL AND kt.sale_id IS NULL
                                AND kt.status IN ('pending','queued','preparing','ready')
                           GROUP BY kt.table_id, kt.status");
        $st->execute([$warehouseId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['status'] === 'pending' ? 'pending' : ($r['status'] === 'ready' ? 'ready' : 'cooking');
            $out[(int)$r['table_id']][$k] = ($out[(int)$r['table_id']][$k] ?? 0) + (float)$r['q'];
        }
        return $out;
    }
}

if (!function_exists('rofExpireOld')) {
    /**
     * One shop's kitchen screen never fills with forgotten tickets: anything older than 12 hours is
     * closed (still waiting for the cashier → cancelled; otherwise → served). Cheap, scoped, idempotent.
     */
    function rofExpireOld(PDO $pdo, int $warehouseId): int
    {
        if (!rofReady($pdo) || $warehouseId <= 0) return 0;
        $n = $pdo->prepare("UPDATE kitchen_tickets SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = 'Not confirmed within 12 hours', closed_at = COALESCE(closed_at, NOW())
                             WHERE warehouse_id = ? AND status = 'pending' AND created_at < NOW() - INTERVAL 12 HOUR");
        $n->execute([$warehouseId]);
        $m = $pdo->prepare("UPDATE kitchen_tickets SET status = 'served', served_at = COALESCE(served_at, NOW())
                             WHERE warehouse_id = ? AND status IN ('queued','preparing','ready') AND created_at < NOW() - INTERVAL 12 HOUR");
        $m->execute([$warehouseId]);
        $total = $n->rowCount() + $m->rowCount();
        if ($total) {
            $pdo->prepare("UPDATE kitchen_ticket_items kti JOIN kitchen_tickets kt ON kt.ticket_id = kti.ticket_id
                              SET kti.status = kt.status WHERE kt.warehouse_id = ? AND kt.status IN ('served','cancelled') AND kti.status <> kt.status")->execute([$warehouseId]);
        }
        return $total;
    }
}

if (!function_exists('rofEnsureRolesForCompany')) {
    /** Called when the owner first turns the team flow on: the four ready-made roles exist. */
    function rofEnsureRolesForCompany(PDO $pdo): array
    {
        if (!rofRightsReady($pdo)) return [];
        return rofsEnsureReadyRoles($pdo, function ($m) {});
    }
}

if (!function_exists('rofPromotePending')) {
    /** The team flow is switched off: anything still waiting for the cashier goes to the kitchen. */
    function rofPromotePending(PDO $pdo, int $userId): int
    {
        if (!rofReady($pdo)) return 0;
        $st = $pdo->prepare("UPDATE kitchen_tickets SET status = 'queued', confirmed_by = ?, confirmed_at = NOW(), updated_at = NOW() WHERE status = 'pending' AND closed_at IS NULL");
        $st->execute([$userId]);
        $pdo->exec("UPDATE kitchen_ticket_items kti JOIN kitchen_tickets kt ON kt.ticket_id = kti.ticket_id SET kti.status = 'queued' WHERE kti.status = 'pending' AND kt.status = 'queued'");
        return $st->rowCount();
    }
}

// ─── Notifications (fail-silent) ─────────────────────────────────────────────

if (!function_exists('rofNotifyToConfirm')) {
    function rofNotifyToConfirm(PDO $pdo, int $ticketId, int $warehouseId, string $label): void
    {
        try {
            require_once __DIR__ . '/restaurant_notify.php';
            if (!rnModuleOn($pdo)) return;
            rnDispatch($pdo, 'restaurant.order_to_confirm', [
                'entity_type' => 'kitchen_ticket', 'entity_id' => $ticketId, 'warehouse_id' => $warehouseId,
                'title' => "Confirm order: $label", 'message' => "$label is waiting for the cashier to confirm it before the kitchen starts.",
                'action_url' => 'pos?confirm_orders=1', 'severity' => 'high', 'dedupe_suffix' => 'once',
            ]);
        } catch (Throwable $e) {
            error_log('rofNotifyToConfirm: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rofNotifyReturned')) {
    function rofNotifyReturned(PDO $pdo, array $ticket, string $reason): void
    {
        try {
            require_once __DIR__ . '/restaurant_notify.php';
            if (!rnModuleOn($pdo)) return;
            $label = (string)($ticket['order_label'] ?: ('Order #' . $ticket['ticket_id']));
            rnDispatch($pdo, 'restaurant.order_returned', [
                'entity_type' => 'kitchen_ticket', 'entity_id' => (int)$ticket['ticket_id'], 'warehouse_id' => (int)$ticket['warehouse_id'],
                'owner_user_ids' => !empty($ticket['sent_by']) ? [(int)$ticket['sent_by']] : [],
                'title' => "Order returned: $label", 'message' => "The cashier returned $label: " . mb_substr($reason, 0, 160),
                'action_url' => $ticket['table_id'] ? 'tables-board?table=' . (int)$ticket['table_id'] : 'pos',
                'severity' => 'medium', 'dedupe_suffix' => 'once',
            ]);
        } catch (Throwable $e) {
            error_log('rofNotifyReturned: ' . $e->getMessage());
        }
    }
}
