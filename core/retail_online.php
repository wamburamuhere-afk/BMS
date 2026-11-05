<?php
/**
 * core/retail_online.php — the retail online shop for Smart POS (retail_online_shop_plan.md).
 *
 * Everything the PUBLIC shop page needs, in one place, so its rules live in one file:
 *   - the gate (Smart POS on + schema + the shop switched on),
 *   - the shop link (create / rotate / resolve),
 *   - the public product list (safe columns only — Available / Sold out, never a number),
 *   - building and PRICING an order on the server from ids only, with a quiet stock check,
 *   - abuse limits and saving the order request.
 * It reuses the restaurant engine's form token, client hash, text cleaner and headers
 * (core/restaurant_online.php), and the same order table (order_type shop_pickup / shop_delivery).
 *
 * Public callers must never use $_SESSION for identity.
 */

require_once __DIR__ . '/restaurant_online.php';
require_once __DIR__ . '/pos_quantity.php';
require_once __DIR__ . '/pos_price_groups.php';

const RS_MAX_LINES       = 30;
const RS_MAX_QTY         = 999;
const RS_MAX_ADDRESS     = 200;
const RS_OPEN_PER_CLIENT = 3;    // open orders per device+IP
const RS_HOURLY_PER_CLIENT = 6;  // orders per device+IP per hour
const RS_OPEN_PER_SHOP   = 40;   // waiting orders on one shop
const RS_EXPIRE_HOURS    = 24;   // a waiting order nobody accepted expires
const RS_CODE_PREFIX     = 'S-';

if (!function_exists('rsSchemaReady')) {
    function rsSchemaReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $ready = roSchemaReady($pdo)
                && (bool)$pdo->query("SHOW COLUMNS FROM warehouses LIKE 'online_shop_enabled'")->fetch()
                && (bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'online_hidden'")->fetch()
                && (bool)$pdo->query("SHOW COLUMNS FROM restaurant_online_orders LIKE 'price_type'")->fetch()
                && (bool)$pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'online_order_id'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('rsModuleOnForPublic')) {
    /** Tenant half of the gate WITHOUT a logged-in user: POS entitled, Smart POS on, schema present. */
    function rsModuleOnForPublic(PDO $pdo): bool
    {
        return (!function_exists('tenantFeatureEnabled') || tenantFeatureEnabled('pos'))
            && posSimpleModeEnabled()
            && rsSchemaReady($pdo);
    }
}

if (!function_exists('rsShopUrl')) {
    function rsShopUrl(string $token): string
    {
        return buildUrl('s/' . $token);
    }
}

if (!function_exists('rsStatusUrl')) {
    /** Same-origin PATH (not absolute): never built from the request's Host header. */
    function rsStatusUrl(string $shopToken, string $publicRef): string
    {
        return getUrl('s/' . $shopToken . '/o/' . $publicRef);
    }
}

if (!function_exists('rsShopEligible')) {
    /** A shop that may have an online shop: active and sells retail goods (retail or hybrid). */
    function rsShopEligible(array $w): bool
    {
        return ($w['status'] ?? '') === 'active' && in_array($w['pos_mode'] ?? 'retail', ['retail', 'hybrid'], true);
    }
}

if (!function_exists('rsResolveToken')) {
    /**
     * Token → the shop it opens, or null — the same null for every failure (bad format, unknown,
     * rotated, shop inactive / restaurant-only / switched off, Smart POS off) so nothing here
     * tells anyone which tokens ever existed.
     * @return null|array{warehouse: array, token: string}
     */
    function rsResolveToken(PDO $pdo, string $token): ?array
    {
        if (!roTokenLooksValid($token) || !rsModuleOnForPublic($pdo)) return null;
        $st = $pdo->prepare("SELECT warehouse_id, warehouse_name, phone, status, pos_mode, online_shop_token, online_shop_enabled,
                                    online_shop_ordering, online_shop_pickup, online_shop_delivery, online_shop_wholesale, online_show_payment
                               FROM warehouses WHERE online_shop_token = ? LIMIT 1");
        $st->execute([$token]);
        $w = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$w || !hash_equals((string)$w['online_shop_token'], $token)) return null;
        if (!rsShopEligible($w) || (int)$w['online_shop_enabled'] !== 1) return null;
        return [
            'warehouse' => rsShopFlags($w),
            'token'     => $token,
        ];
    }
}

if (!function_exists('rsShopFlags')) {
    /** A warehouses row → the switches the public page and the order checks read. */
    function rsShopFlags(array $w): array
    {
        $pickup   = (int)($w['online_shop_pickup'] ?? 0) === 1;
        $delivery = (int)($w['online_shop_delivery'] ?? 0) === 1;
        return [
            'warehouse_id'   => (int)$w['warehouse_id'],
            'warehouse_name' => (string)$w['warehouse_name'],
            'phone'          => trim((string)($w['phone'] ?? '')),
            'ordering'       => (int)($w['online_shop_ordering'] ?? 0) === 1 && ($pickup || $delivery),
            'pickup'         => $pickup,
            'delivery'       => $delivery,
            'wholesale'      => (int)($w['online_shop_wholesale'] ?? 0) === 1,
            'show_payment'   => (int)($w['online_show_payment'] ?? 0) === 1,
        ];
    }
}

if (!function_exists('rsEnsureLink')) {
    /** Give a shop a link when it has none (unique index → retry on the 1-in-2^128 clash). */
    function rsEnsureLink(PDO $pdo, int $wid): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $pdo->prepare("UPDATE warehouses SET online_shop_token = ? WHERE warehouse_id = ? AND online_shop_token IS NULL")
                    ->execute([roNewToken(), $wid]);
                return;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        }
    }
}

if (!function_exists('rsShopSettings')) {
    /** A shop's switches + link — for STAFF screens only (the caller checks access). */
    function rsShopSettings(PDO $pdo, int $wid): array
    {
        $st = $pdo->prepare("SELECT warehouse_id, warehouse_name, phone, status, pos_mode, online_shop_token, online_shop_enabled, online_shop_ordering,
                                    online_shop_pickup, online_shop_delivery, online_shop_wholesale, online_show_payment
                               FROM warehouses WHERE warehouse_id = ?");
        $st->execute([$wid]);
        $w = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$w) return [];
        return [
            'warehouse_id' => (int)$w['warehouse_id'],
            'shop'         => (string)$w['warehouse_name'],
            'phone'        => trim((string)($w['phone'] ?? '')),
            'eligible'     => rsShopEligible($w),
            'enabled'      => (int)$w['online_shop_enabled'] === 1,
            'ordering'     => (int)$w['online_shop_ordering'] === 1,
            'pickup'       => (int)$w['online_shop_pickup'] === 1,
            'delivery'     => (int)$w['online_shop_delivery'] === 1,
            'wholesale'    => (int)$w['online_shop_wholesale'] === 1,
            'show_payment' => (int)$w['online_show_payment'] === 1,
            'link'         => !empty($w['online_shop_token']) ? rsShopUrl((string)$w['online_shop_token']) : null,
        ];
    }
}

if (!function_exists('rsAnyShopOnline')) {
    /** Has any shop switched its online shop on? (drives the staff "Online orders" link). Cached. */
    function rsAnyShopOnline(PDO $pdo): bool
    {
        static $on = null;
        if ($on !== null) return $on;
        try { $on = rsSchemaReady($pdo) && (bool)$pdo->query("SELECT 1 FROM warehouses WHERE online_shop_enabled = 1 AND status = 'active' LIMIT 1")->fetchColumn(); }
        catch (Throwable $e) { $on = false; }
        return $on;
    }
}

if (!function_exists('rsCatalogRows')) {
    /**
     * The ONE definition of "a product of this shop's online shop" — used to show AND to order.
     * Active, not a service, not hidden online, priced, stocked in (linked to) this shop.
     * @param int[] $onlyIds  limit to these product ids (ordering); [] = all
     */
    function rsCatalogRows(PDO $pdo, int $warehouseId, array $onlyIds = []): array
    {
        $args = [$warehouseId, wholesalePriceGroupId($pdo) ?? 0];   // same order as the ? below
        $idSql = '';
        if ($onlyIds) {
            $onlyIds = array_values(array_unique(array_map('intval', $onlyIds)));
            $idSql = ' AND p.product_id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            $args = array_merge($args, $onlyIds);
        }
        $st = $pdo->prepare("
            SELECT p.product_id, p.product_name, p.sku, p.unit, p.category_id, c.category_name, p.image_url,
                   p.selling_price, COALESCE(pgp.price, p.selling_price) AS wholesale_price,
                   p.track_inventory, COALESCE(ps.available_quantity, 0) AS available
              FROM products p
              JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id = ?
         LEFT JOIN categories c ON c.category_id = p.category_id
         LEFT JOIN product_price_group_prices pgp ON pgp.product_id = p.product_id AND pgp.price_group_id = ?
             WHERE p.status = 'active' AND p.is_service = 0 AND p.online_hidden = 0 AND p.selling_price > 0 $idSql
          ORDER BY c.category_name IS NULL, c.category_name, p.product_name
        ");
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('rsIsSoldOut')) {
    function rsIsSoldOut(array $r): bool
    {
        return (int)$r['track_inventory'] === 1 && (float)$r['available'] <= 0;
    }
}

if (!function_exists('rsPublicCatalog')) {
    /**
     * The public product list of one shop. Safe columns only: never cost, never a stock number.
     * @return array{items: array<int,array>, categories: array<int,string>, has_wholesale: bool}
     */
    function rsPublicCatalog(PDO $pdo, array $shop): array
    {
        $items = [];
        $categories = [];
        $hasWholesale = false;
        foreach (rsCatalogRows($pdo, (int)$shop['warehouse_id']) as $r) {
            $catId = $r['category_id'] !== null && $r['category_name'] !== null ? (int)$r['category_id'] : 0;
            if ($catId) $categories[$catId] = (string)$r['category_name'];
            $retail = round((float)$r['selling_price'], 2);
            $whole  = round((float)$r['wholesale_price'], 2);
            $item = [
                'id'          => (int)$r['product_id'],
                'name'        => (string)$r['product_name'],
                'category_id' => $catId,
                'price'       => $retail,
                'image'       => !empty($r['image_url']) ? getUrl((string)$r['image_url']) : '',
                'sold_out'    => rsIsSoldOut($r),            // yes/no only — never the number
                'decimal'     => posUnitAllowsDecimal((string)$r['unit']),
                'unit'        => (string)($r['unit'] ?? ''),
            ];
            if ($shop['wholesale'] && $whole > 0 && abs($whole - $retail) > 0.001) {
                $item['wholesale'] = $whole;
                $hasWholesale = true;
            }
            $items[] = $item;
        }
        return ['items' => $items, 'categories' => $categories, 'has_wholesale' => $hasWholesale];
    }
}

if (!function_exists('rsBuildOrder')) {
    /**
     * Turn the buyer's request into priced order lines — on the server, from ids only.
     * Any client price/name is never read. Stock is checked quietly (the number is never said).
     *
     * $lines: [ ['id'=>int, 'qty'=>number], ... ]   $priceType: 'retail' | 'wholesale'
     * @return array{items: array<int,array>, subtotal: float}  items use the till's cart shape
     * @throws RoUserError with a buyer-safe message
     */
    function rsBuildOrder(PDO $pdo, array $shop, $lines, string $priceType): array
    {
        if (!is_array($lines) || !$lines) throw new RoUserError(t('Your order is empty.'));
        if (count($lines) > RS_MAX_LINES) throw new RoUserError(sprintf(t('An order can have at most %d lines.'), RS_MAX_LINES));
        if ($priceType === 'wholesale' && !$shop['wholesale']) $priceType = 'retail';

        $req = [];
        foreach ($lines as $ln) {
            if (!is_array($ln)) throw new RoUserError(t('Your order could not be read. Please try again.'));
            $pid = filter_var($ln['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $raw = $ln['qty'] ?? null;
            if ($pid === false || !is_numeric($raw) || is_string($raw) && !preg_match('/^\d+(\.\d{1,3})?$/', $raw)) {
                throw new RoUserError(t('Your order could not be read. Please try again.'));
            }
            $qty = round((float)$raw, 3);
            if ($qty <= 0 || $qty > RS_MAX_QTY) throw new RoUserError(t('Your order could not be read. Please try again.'));
            $req[] = ['id' => $pid, 'qty' => $qty];
        }

        $prod = [];
        foreach (rsCatalogRows($pdo, (int)$shop['warehouse_id'], array_column($req, 'id')) as $p) $prod[(int)$p['product_id']] = $p;

        $items = [];
        $subtotal = 0.0;
        $qtyByProduct = [];
        foreach ($req as $r) {
            $p = $prod[$r['id']] ?? null;
            // Not in THIS shop's online shop (other shop, hidden, service, deleted…) → same message as "gone".
            if (!$p) throw new RoUserError(t('One of the products is no longer available. Please refresh the page.'));
            $name = (string)$p['product_name'];
            if (abs($r['qty'] - round($r['qty'])) > 0.0000001 && !posUnitAllowsDecimal((string)$p['unit'])) {
                throw new RoUserError(sprintf(t('%s is sold in whole numbers.'), $name));
            }
            if (rsIsSoldOut($p)) throw new RoUserError(sprintf(t('%s is sold out.'), $name));
            $qtyByProduct[$r['id']] = round(($qtyByProduct[$r['id']] ?? 0) + $r['qty'], 3);
            if ((int)$p['track_inventory'] === 1 && $qtyByProduct[$r['id']] > (float)$p['available'] + 0.0000001) {
                throw new RoUserError(sprintf(t('Only a few %s are left. Please reduce the quantity.'), $name));
            }

            $retail = round((float)$p['selling_price'], 2);
            $unit   = $priceType === 'wholesale' && (float)$p['wholesale_price'] > 0 ? round((float)$p['wholesale_price'], 2) : $retail;
            $items[] = [
                'product_id'        => (int)$p['product_id'],
                'product_name'      => $name,
                'sku'               => (string)($p['sku'] ?? ''),
                'unit'              => (string)($p['unit'] ?? ''),
                'price'             => $unit,
                'quantity'          => $r['qty'],
                'tax_rate'          => 0,
                'min_selling_price' => 0,
                'discount_type'     => 'percentage',
                'discount_value'    => 0,
                'discount_percent'  => 0,
                'discounted_price'  => $unit,
                'online_price_type' => $priceType,
            ];
            $subtotal += $unit * $r['qty'];
        }
        return ['items' => $items, 'subtotal' => round($subtotal, 2)];
    }
}

if (!function_exists('rsCheckLimits')) {
    /** @throws RoUserError when a limit is reached */
    function rsCheckLimits(PDO $pdo, int $warehouseId, string $clientHash): void
    {
        $q = function (string $sql, array $a) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); };
        if ($q("SELECT COUNT(*) FROM restaurant_online_orders WHERE client_hash = ? AND status IN ('pending','accepted','ready')", [$clientHash]) >= RS_OPEN_PER_CLIENT
            || $q("SELECT COUNT(*) FROM restaurant_online_orders WHERE client_hash = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)", [$clientHash]) >= RS_HOURLY_PER_CLIENT) {
            throw new RoUserError(t('You have sent several orders already. Please wait for the shop to reply, or call the shop.'));
        }
        if ($q("SELECT COUNT(*) FROM restaurant_online_orders WHERE warehouse_id = ? AND status = 'pending'", [$warehouseId]) >= RS_OPEN_PER_SHOP) {
            throw new RoUserError(t('The shop is very busy right now. Please try again in a few minutes.'));
        }
    }
}

if (!function_exists('rsPlaceOrder')) {
    /**
     * Save one buyer order request (status 'pending'). Nothing else happens until staff accept.
     * @return array{public_ref:string, order_code:string, order_id:int}
     */
    function rsPlaceOrder(PDO $pdo, array $ctx, array $built, string $name, string $phone, string $note,
                          string $fulfil, string $address, string $priceType, string $clientHash): array
    {
        $wid  = $ctx['warehouse']['warehouse_id'];
        $type = $fulfil === 'delivery' ? 'shop_delivery' : 'shop_pickup';
        $ref  = roNewToken();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO restaurant_online_orders
                    (warehouse_id, table_id, order_type, order_code, public_ref, customer_name, customer_phone, note, delivery_address,
                     price_type, items_json, subtotal, status, client_hash, created_at)
                VALUES (?, NULL, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())")
                ->execute([$wid, $type, $ref, $name, $phone, $note !== '' ? $note : null,
                           $type === 'shop_delivery' ? $address : null, $priceType,
                           json_encode($built['items'], JSON_UNESCAPED_UNICODE), $built['subtotal'], $clientHash]);
            $id = (int)$pdo->lastInsertId();
            $code = RS_CODE_PREFIX . str_pad((string)($id % 10000), 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE restaurant_online_orders SET order_code = ? WHERE order_id = ?")->execute([$code, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Retail notifications (bell + email per Settings → Notification rules). Never blocks the order.
        try {
            require_once __DIR__ . '/retail_online_notify.php';
            rsnNotifyNewOrder($pdo, $id);
        } catch (Throwable $e) { error_log('rsPlaceOrder notify: ' . $e->getMessage()); }
        return ['public_ref' => $ref, 'order_code' => $code, 'order_id' => $id];
    }
}

if (!function_exists('rsExpireWaiting')) {
    /** Waiting shop orders nobody accepted within RS_EXPIRE_HOURS expire (the buyer sees why). */
    function rsExpireWaiting(PDO $pdo, ?int $warehouseId = null): int
    {
        $sql = "UPDATE restaurant_online_orders SET status = 'cancelled', cancelled_by = 'system', reject_reason = ?
                 WHERE order_type IN ('shop_pickup','shop_delivery') AND status = 'pending'
                   AND created_at < NOW() - INTERVAL " . RS_EXPIRE_HOURS . " HOUR";
        $args = [t('The shop did not confirm this order in time. Please order again or call the shop.')];
        if ($warehouseId) { $sql .= ' AND warehouse_id = ?'; $args[] = $warehouseId; }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }
}

if (!function_exists('rsPublicOrder')) {
    /** One shop order for its own status page — looked up by its secret ref AND its shop. Safe fields only. */
    function rsPublicOrder(PDO $pdo, int $warehouseId, string $ref): ?array
    {
        if (!roTokenLooksValid($ref)) return null;
        $st = $pdo->prepare("SELECT order_code, order_type, status, reject_reason, cancelled_by, items_json, subtotal, created_at,
                                    public_ref, price_type, delivery_address
                               FROM restaurant_online_orders
                              WHERE public_ref = ? AND warehouse_id = ? AND order_type IN ('shop_pickup','shop_delivery') LIMIT 1");
        $st->execute([$ref, $warehouseId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o || !hash_equals((string)$o['public_ref'], $ref)) return null;
        $lines = [];
        foreach ((array)json_decode((string)$o['items_json'], true) as $it) {
            $lines[] = [
                'name'  => (string)($it['product_name'] ?? ''),
                'qty'   => (float)($it['quantity'] ?? 0),
                'price' => (float)($it['price'] ?? 0),
            ];
        }
        return [
            'code'         => (string)$o['order_code'],
            'type'         => $o['order_type'] === 'shop_delivery' ? 'delivery' : 'pickup',
            'status'       => (string)$o['status'],
            'reason'       => (string)($o['reject_reason'] ?? ''),
            'cancelled_by' => (string)($o['cancelled_by'] ?? ''),
            'price_type'   => (string)$o['price_type'],
            'subtotal'     => (float)$o['subtotal'],
            'lines'        => $lines,
            'created'      => (string)$o['created_at'],
        ];
    }
}

if (!function_exists('rsCustomerCancel')) {
    /** The buyer cancels their own order — only while it is still waiting. @return bool cancelled */
    function rsCustomerCancel(PDO $pdo, int $warehouseId, string $ref): bool
    {
        if (!roTokenLooksValid($ref)) return false;
        $st = $pdo->prepare("UPDATE restaurant_online_orders SET status = 'cancelled', cancelled_by = 'customer', reject_reason = ?
                              WHERE public_ref = ? AND warehouse_id = ? AND status = 'pending' AND order_type IN ('shop_pickup','shop_delivery')");
        $st->execute([t('You cancelled this order.'), $ref, $warehouseId]);
        return $st->rowCount() === 1;
    }
}

if (!function_exists('rsPaymentAccounts')) {
    /** The company's payment numbers from payment_settings.php, for the buyer (only when the shop switched it on). */
    function rsPaymentAccounts(PDO $pdo): array
    {
        require_once __DIR__ . '/payment_accounts.php';
        $p = paymentAccountsForPrint(getPaymentAccounts($pdo));
        $out = [];
        foreach ($p['mobile'] as $a) $out[] = ['kind' => 'mobile', 'label' => $a['label'], 'number' => $a['number'], 'name' => $a['holder'], 'ref' => $a['ref'] ?? ''];
        foreach ($p['bank'] as $a)   $out[] = ['kind' => 'bank',   'label' => $a['label'], 'number' => $a['number'], 'name' => $a['holder'], 'ref' => ''];
        return $out;
    }
}
