<?php
/**
 * core/restaurant_online.php — restaurant online menu & ordering
 * (restaurant_online_menu_plan.md).
 *
 * Everything the PUBLIC menu needs, in one place, so the security rules live
 * in exactly one file:
 *   - the gate (tenant entitlement + Smart POS + schema + shop switched on),
 *   - link tokens (create / rotate / resolve),
 *   - the public menu read (safe columns only — never cost or stock numbers),
 *   - building and PRICING an order on the server from ids only,
 *   - abuse limits, the signed form token, and the response headers.
 *
 * Public callers must never use $_SESSION for identity: a staff member who
 * opens their own restaurant's link gets exactly the same public view.
 */

require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/pos_nav.php';

/** A message that is safe to show the customer (validation failures). */
if (!class_exists('RoUserError')) {
    class RoUserError extends RuntimeException {}
}

const RO_MAX_LINES          = 30;
const RO_MAX_QTY            = 20;
const RO_MAX_NOTE           = 200;
const RO_MAX_NAME           = 60;
const RO_OPEN_PER_CLIENT    = 3;    // open (pending/accepted/ready) orders per device+IP
const RO_HOURLY_PER_CLIENT  = 6;    // orders per device+IP per hour
const RO_OPEN_PER_TABLE     = 3;    // pending orders waiting on one table
const RO_OPEN_PER_SHOP      = 40;   // pending orders waiting on one shop
const RO_FORM_MIN_AGE       = 2;    // seconds — faster than a human can choose food = a script
const RO_FORM_MAX_AGE       = 43200;// 12 h

if (!function_exists('roSchemaReady')) {
    function roSchemaReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $ready = (bool)$pdo->query("SHOW COLUMNS FROM warehouses LIKE 'online_menu_enabled'")->fetch()
                  && (bool)$pdo->query("SHOW COLUMNS FROM restaurant_tables LIKE 'qr_token'")->fetch()
                  && (bool)$pdo->query("SHOW TABLES LIKE 'restaurant_online_orders'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('roModuleOnForPublic')) {
    /**
     * The tenant-level half of the gate WITHOUT a logged-in user (the public has
     * none): tenant entitled to Restaurant POS, Smart POS on, all schema present.
     * Mirrors restaurantModuleOn(), whose canView() needs a session.
     */
    function roModuleOnForPublic(PDO $pdo): bool
    {
        return tenantFeatureEnabled('restaurant_pos')
            && tenantFeatureEnabled('pos')
            && posSimpleModeEnabled()
            && restaurantSchemaReady($pdo)
            && restaurantMenuSchemaReady($pdo)
            && roSchemaReady($pdo);
    }
}

if (!function_exists('roNewToken')) {
    /** 128-bit random token, 32 lowercase hex characters. */
    function roNewToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('roTokenLooksValid')) {
    function roTokenLooksValid(string $t): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $t) === 1;
    }
}

if (!function_exists('roMenuUrl')) {
    function roMenuUrl(string $token): string
    {
        return buildUrl('m/' . $token);
    }
}

if (!function_exists('roStatusUrl')) {
    /** Same-origin PATH (not absolute): never built from the request's Host header. */
    function roStatusUrl(string $menuToken, string $publicRef): string
    {
        return getUrl('m/' . $menuToken . '/o/' . $publicRef);
    }
}

if (!function_exists('roResolveToken')) {
    /**
     * Token → the shop (and table, for a table QR) it opens, or null.
     * null for every failure reason alike (bad format, unknown, rotated, shop
     * inactive / not a restaurant / menu off, module off) so the page cannot be
     * used to learn which tokens ever existed.
     *
     * @return null|array{warehouse: array, table: ?array, token: string}
     */
    function roResolveToken(PDO $pdo, string $token): ?array
    {
        if (!roTokenLooksValid($token) || !roModuleOnForPublic($pdo)) return null;

        $cols = "w.warehouse_id, w.warehouse_name, w.pos_mode, w.status, w.online_menu_token,
                 w.online_menu_enabled, w.online_order_table, w.online_order_pickup";
        $st = $pdo->prepare("SELECT $cols FROM warehouses w WHERE w.online_menu_token = ? LIMIT 1");
        $st->execute([$token]);
        $wh = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $table = null;
        if (!$wh) {
            $st = $pdo->prepare("SELECT $cols, t.table_id, t.table_number, t.qr_token
                                   FROM restaurant_tables t JOIN warehouses w ON w.warehouse_id = t.warehouse_id
                                  WHERE t.qr_token = ? LIMIT 1");
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) return null;
            $table = ['table_id' => (int)$row['table_id'], 'table_number' => (string)$row['table_number']];
            $wh = $row;
        }
        // Constant-time re-check of the matched value (defence in depth).
        $stored = $table ? (string)$wh['qr_token'] : (string)$wh['online_menu_token'];
        if (!hash_equals($stored, $token)) return null;

        if ($wh['status'] !== 'active'
            || !in_array($wh['pos_mode'], ['restaurant', 'hybrid'], true)
            || (int)$wh['online_menu_enabled'] !== 1
            // A table QR needs the shop's own link to exist: "Make new links"
            // clears it, which must kill every table QR printed before.
            || empty($wh['online_menu_token'])) {
            return null;
        }
        return [
            'warehouse' => [
                'warehouse_id'   => (int)$wh['warehouse_id'],
                'warehouse_name' => (string)$wh['warehouse_name'],
                'order_table'    => (int)$wh['online_order_table'] === 1,
                'order_pickup'   => (int)$wh['online_order_pickup'] === 1,
                'menu_token'     => (string)$wh['online_menu_token'],
            ],
            'table' => $table,
            'token' => $token,
        ];
    }
}

if (!function_exists('roEnsureLinks')) {
    /** Give a shop and each of its tables a link when they have none (unique index → retry on the 1-in-2^128 clash). */
    function roEnsureLinks(PDO $pdo, int $wid): void
    {
        $set = function (string $sql, array $args) use ($pdo): void {
            for ($i = 0; $i < 3; $i++) {
                try { $pdo->prepare($sql)->execute(array_merge([roNewToken()], $args)); return; }
                catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; }
            }
        };
        $set("UPDATE warehouses SET online_menu_token = ? WHERE warehouse_id = ? AND online_menu_token IS NULL", [$wid]);
        $st = $pdo->prepare("SELECT table_id FROM restaurant_tables WHERE warehouse_id = ? AND qr_token IS NULL");
        $st->execute([$wid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $set("UPDATE restaurant_tables SET qr_token = ? WHERE table_id = ? AND qr_token IS NULL", [(int)$tid]);
        }
    }
}

if (!function_exists('roShopLinks')) {
    /** A shop's switches, its menu link and one link per table — for STAFF screens only (caller checks access). */
    function roShopLinks(PDO $pdo, int $wid): array
    {
        $st = $pdo->prepare("SELECT warehouse_name, online_menu_token, online_menu_enabled, online_order_table, online_order_pickup FROM warehouses WHERE warehouse_id = ?");
        $st->execute([$wid]);
        $w = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $tables = [];
        $ts = $pdo->prepare("SELECT t.table_id, t.table_number, t.qr_token, f.name AS floor_name
                               FROM restaurant_tables t LEFT JOIN restaurant_floors f ON f.floor_id = t.floor_id
                              WHERE t.warehouse_id = ? ORDER BY f.sort_order, t.table_number + 0, t.table_number");
        $ts->execute([$wid]);
        foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tables[] = ['table_id' => (int)$t['table_id'], 'table_number' => (string)$t['table_number'], 'floor' => (string)($t['floor_name'] ?? ''),
                         'link' => $t['qr_token'] ? roMenuUrl((string)$t['qr_token']) : null];
        }
        return [
            'shop'         => (string)($w['warehouse_name'] ?? ''),
            'enabled'      => (int)($w['online_menu_enabled'] ?? 0) === 1,
            'order_table'  => (int)($w['online_order_table'] ?? 0) === 1,
            'order_pickup' => (int)($w['online_order_pickup'] ?? 0) === 1,
            'link'         => !empty($w['online_menu_token']) ? roMenuUrl((string)$w['online_menu_token']) : null,
            'tables'       => $tables,
        ];
    }
}

if (!function_exists('roAnyShopOnline')) {
    /** Has any shop switched its online menu on? (drives the staff "Online orders" link + alert). Cached. */
    function roAnyShopOnline(PDO $pdo): bool
    {
        static $on = null;
        if ($on !== null) return $on;
        try { $on = roSchemaReady($pdo) && (bool)$pdo->query("SELECT 1 FROM warehouses WHERE online_menu_enabled = 1 AND status = 'active' LIMIT 1")->fetchColumn(); }
        catch (Throwable $e) { $on = false; }
        return $on;
    }
}

if (!function_exists('roCanOrder')) {
    /** May this link place an order right now? (table QR → table ordering; shop link → pickup) */
    function roCanOrder(array $ctx): bool
    {
        return $ctx['table'] ? $ctx['warehouse']['order_table'] : $ctx['warehouse']['order_pickup'];
    }
}

if (!function_exists('roMenuItemSql')) {
    /** The ONE definition of "an item of this shop's menu" — used to show AND to order. */
    function roMenuItemSql(): string
    {
        return "p.status = 'active' AND p.is_menu_item = 1
                AND (p.warehouse_id = :w_own
                     OR EXISTS (SELECT 1 FROM product_stocks ps2 WHERE ps2.product_id = p.product_id AND ps2.warehouse_id = :w_stock))";
    }
}

if (!function_exists('roPublicMenu')) {
    /**
     * The public menu of one shop. Safe columns only.
     * @return array{items: array<int,array>, categories: array<int,string>}
     */
    function roPublicMenu(PDO $pdo, int $warehouseId): array
    {
        $st = $pdo->prepare("
            SELECT p.product_id, p.product_name, p.category_id, c.category_name, c.sort_order AS cat_sort,
                   p.selling_price, p.image_url, p.is_service, p.track_inventory, p.is_available,
                   COALESCE((SELECT SUM(ps.stock_quantity) FROM product_stocks ps
                              WHERE ps.product_id = p.product_id AND ps.warehouse_id = :w_qty), 0) AS stock,
                   (SELECT COUNT(*) FROM product_modifier_groups pmg JOIN modifier_groups mg ON mg.group_id = pmg.group_id
                     WHERE pmg.product_id = p.product_id AND mg.status = 'active') AS option_groups
              FROM products p
         LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE " . roMenuItemSql() . "
               AND p.is_available = 1 AND p.selling_price > 0
          ORDER BY COALESCE(c.sort_order, 999), c.category_name, p.product_name
        ");
        $st->execute([':w_qty' => $warehouseId, ':w_own' => $warehouseId, ':w_stock' => $warehouseId]);
        $items = [];
        $categories = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tracksStock = (int)$r['is_service'] === 0 && (int)$r['track_inventory'] === 1;
            $catId = $r['category_id'] !== null && $r['category_name'] !== null ? (int)$r['category_id'] : 0;
            if ($catId) $categories[$catId] = (string)$r['category_name'];
            $items[] = [
                'id'          => (int)$r['product_id'],
                'name'        => (string)$r['product_name'],
                'category_id' => $catId,
                'price'       => round((float)$r['selling_price'], 2),
                'image'       => !empty($r['image_url']) ? getUrl((string)$r['image_url']) : '',
                'sold_out'    => $tracksStock && (float)$r['stock'] <= 0,   // a yes/no only — never the number
                'has_options' => (int)$r['option_groups'] > 0,
            ];
        }
        return ['items' => $items, 'categories' => $categories];
    }
}

if (!function_exists('roModifiersFor')) {
    /**
     * Active option groups + options for some products of the menu.
     * @return array<int, array<int, array>> product_id => groups
     */
    function roModifiersFor(PDO $pdo, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) return [];
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $st = $pdo->prepare("
            SELECT pmg.product_id, mg.group_id, mg.name AS group_name, mg.selection_type, mg.min_select, mg.max_select, mg.is_required,
                   mo.option_id, mo.name AS option_name, mo.price_adjustment
              FROM product_modifier_groups pmg
              JOIN modifier_groups mg ON mg.group_id = pmg.group_id AND mg.status = 'active'
              JOIN modifier_options mo ON mo.group_id = mg.group_id AND mo.status = 'active'
             WHERE pmg.product_id IN ($ph)
          ORDER BY pmg.product_id, mg.group_id, mo.option_id
        ");
        $st->execute($productIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int)$r['product_id']; $gid = (int)$r['group_id'];
            if (!isset($out[$pid][$gid])) {
                $single = $r['selection_type'] === 'single';
                $min = max((int)$r['min_select'], (int)$r['is_required'] === 1 ? 1 : 0);
                $max = $single ? 1 : ((int)$r['max_select'] > 0 ? (int)$r['max_select'] : 99);
                $out[$pid][$gid] = ['id' => $gid, 'name' => (string)$r['group_name'], 'single' => $single,
                                    'min' => min($min, $max), 'max' => $max, 'options' => []];
            }
            $out[$pid][$gid]['options'][(int)$r['option_id']] = [
                'id' => (int)$r['option_id'], 'name' => (string)$r['option_name'], 'price' => round((float)$r['price_adjustment'], 2),
            ];
        }
        return $out;
    }
}

if (!function_exists('roCleanText')) {
    /** One line of plain text: control characters removed, spaces collapsed, length capped. */
    function roCleanText($v, int $max): string
    {
        $s = is_string($v) ? $v : '';
        if (!mb_check_encoding($s, 'UTF-8')) return '';
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max);
    }
}

if (!function_exists('roBuildOrder')) {
    /**
     * Turn the customer's request into priced order lines — on the server, from
     * ids only. Any client price/name is ignored because it is never read.
     *
     * $lines: [ ['id'=>int, 'qty'=>int, 'options'=>int[], 'note'=>string], ... ]
     * @return array{items: array<int,array>, subtotal: float}  items use the till's cart shape
     * @throws RoUserError with a customer-safe message
     */
    function roBuildOrder(PDO $pdo, int $warehouseId, $lines): array
    {
        if (!is_array($lines) || !$lines) throw new RoUserError(t('Your order is empty.'));
        if (count($lines) > RO_MAX_LINES) throw new RoUserError(sprintf(t('An order can have at most %d lines.'), RO_MAX_LINES));

        $req = [];
        foreach ($lines as $ln) {
            if (!is_array($ln)) throw new RoUserError(t('Your order could not be read. Please try again.'));
            $pid = filter_var($ln['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $qty = filter_var($ln['qty'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => RO_MAX_QTY]]);
            if ($pid === false || $qty === false) throw new RoUserError(t('Your order could not be read. Please try again.'));
            $opts = $ln['options'] ?? [];
            if (!is_array($opts) || count($opts) > 30) throw new RoUserError(t('Your order could not be read. Please try again.'));
            $optIds = [];
            foreach ($opts as $o) {
                $oid = filter_var($o, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($oid === false) throw new RoUserError(t('Your order could not be read. Please try again.'));
                $optIds[$oid] = true;
            }
            $req[] = ['id' => $pid, 'qty' => $qty, 'options' => array_keys($optIds), 'note' => roCleanText($ln['note'] ?? '', 120)];
        }

        $ids = array_values(array_unique(array_column($req, 'id')));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("
            SELECT p.product_id, p.product_name, p.sku, p.selling_price, p.is_service, p.track_inventory, p.is_available,
                   COALESCE((SELECT SUM(ps.stock_quantity) FROM product_stocks ps
                              WHERE ps.product_id = p.product_id AND ps.warehouse_id = ?), 0) AS stock
              FROM products p
             WHERE p.product_id IN ($ph) AND p.status = 'active' AND p.is_menu_item = 1
               AND (p.warehouse_id = ? OR EXISTS (SELECT 1 FROM product_stocks ps2 WHERE ps2.product_id = p.product_id AND ps2.warehouse_id = ?))
        ");
        $st->execute(array_merge([$warehouseId], $ids, [$warehouseId, $warehouseId]));
        $prod = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $prod[(int)$p['product_id']] = $p;
        $mods = roModifiersFor($pdo, $ids);

        $items = [];
        $subtotal = 0.0;
        $qtyByProduct = [];
        foreach ($req as $r) {
            $p = $prod[$r['id']] ?? null;
            // Not on THIS shop's menu (another shop's item, a retail product, deleted…) → same message as "gone".
            if (!$p) throw new RoUserError(t('One of the items is no longer on the menu. Please refresh the menu.'));
            $name = (string)$p['product_name'];
            if ((int)$p['is_available'] !== 1 || (float)$p['selling_price'] <= 0) {
                throw new RoUserError(sprintf(t('%s is not available right now.'), $name));
            }
            $qtyByProduct[$r['id']] = ($qtyByProduct[$r['id']] ?? 0) + $r['qty'];
            if ((int)$p['is_service'] === 0 && (int)$p['track_inventory'] === 1 && $qtyByProduct[$r['id']] > (float)$p['stock']) {
                throw new RoUserError(sprintf(t('Not enough %s available right now.'), $name));
            }

            // Options: each must belong to a group linked to THIS dish; each group's rules must hold.
            $groups = $mods[$r['id']] ?? [];
            $chosen = [];
            $perGroup = [];
            foreach ($r['options'] as $oid) {
                $found = null;
                foreach ($groups as $g) {
                    if (isset($g['options'][$oid])) { $found = [$g, $g['options'][$oid]]; break; }
                }
                if (!$found) throw new RoUserError(sprintf(t('A choice for %s is no longer available. Please choose again.'), $name));
                [$g, $o] = $found;
                $perGroup[$g['id']] = ($perGroup[$g['id']] ?? 0) + 1;
                $chosen[] = ['group_id' => $g['id'], 'option_id' => $o['id'], 'option_name' => $o['name'], 'price_adjustment' => $o['price']];
            }
            foreach ($groups as $g) {
                $n = $perGroup[$g['id']] ?? 0;
                if ($n < $g['min']) throw new RoUserError(sprintf(t('Please choose %1$s for %2$s.'), $g['name'], $name));
                if ($n > $g['max']) throw new RoUserError(sprintf(t('Too many choices for %1$s on %2$s.'), $g['name'], $name));
            }

            $unit = round((float)$p['selling_price'] + array_sum(array_column($chosen, 'price_adjustment')), 2);
            if ($unit < 0) $unit = 0.0;
            $line = [
                'product_id'        => (int)$p['product_id'],
                'product_name'      => $name,
                'sku'               => (string)($p['sku'] ?? ''),
                'price'             => $unit,
                'quantity'          => $r['qty'],
                'tax_rate'          => 0,
                'min_selling_price' => 0,
                'discount_type'     => 'percentage',
                'discount_value'    => 0,
                'discount_percent'  => 0,
                'discounted_price'  => $unit,
            ];
            if ($chosen) $line['modifiers'] = $chosen;
            if ($r['note'] !== '') $line['note'] = $r['note'];
            $items[] = $line;
            $subtotal += $unit * $r['qty'];
        }
        return ['items' => $items, 'subtotal' => round($subtotal, 2)];
    }
}

if (!function_exists('roSecret')) {
    /** Per-tenant secret for signing form tokens and hashing IPs. Created on first use. */
    function roSecret(PDO $pdo): string
    {
        static $secret = null;
        if ($secret !== null) return $secret;
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_online_secret' LIMIT 1");
        $st->execute();
        $v = (string)$st->fetchColumn();
        if (!preg_match('/^[a-f0-9]{64}$/', $v)) {
            $v = bin2hex(random_bytes(32));
            // INSERT IGNORE: two first requests at once must agree on ONE secret.
            $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, updated_at) VALUES ('restaurant_online_secret', ?, NOW())")->execute([$v]);
            $st->execute();
            $v = (string)$st->fetchColumn();
        }
        return $secret = $v;
    }
}

if (!function_exists('roClientHash')) {
    /** Who is ordering, for limits only: salted hash of IP + browser. The raw IP is never stored. */
    function roClientHash(PDO $pdo): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
        return hash_hmac('sha256', $ip . '|' . $ua, roSecret($pdo));
    }
}

if (!function_exists('roFormToken')) {
    /** Signed "this page was loaded from our menu at time T" token. */
    function roFormToken(PDO $pdo, string $linkToken, ?int $now = null): string
    {
        $ts = $now ?? time();
        return $ts . '.' . hash_hmac('sha256', 'ro-form|' . $linkToken . '|' . $ts, roSecret($pdo));
    }
}

if (!function_exists('roCheckFormToken')) {
    /** @return string '' when fine, else a reason code ('bad', 'fast', 'old') */
    function roCheckFormToken(PDO $pdo, string $linkToken, $formToken, ?int $now = null): string
    {
        if (!is_string($formToken) || !preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $formToken, $m)) return 'bad';
        $expect = hash_hmac('sha256', 'ro-form|' . $linkToken . '|' . $m[1], roSecret($pdo));
        if (!hash_equals($expect, $m[2])) return 'bad';
        $age = ($now ?? time()) - (int)$m[1];
        if ($age < RO_FORM_MIN_AGE) return 'fast';
        if ($age > RO_FORM_MAX_AGE) return 'old';
        return '';
    }
}

if (!function_exists('roCheckLimits')) {
    /** @throws RoUserError when a limit is reached */
    function roCheckLimits(PDO $pdo, int $warehouseId, ?int $tableId, string $clientHash): void
    {
        $q = fn(string $sql, array $a) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); })();

        if ($q("SELECT COUNT(*) FROM restaurant_online_orders WHERE client_hash = ? AND status IN ('pending','accepted','ready')", [$clientHash]) >= RO_OPEN_PER_CLIENT
            || $q("SELECT COUNT(*) FROM restaurant_online_orders WHERE client_hash = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)", [$clientHash]) >= RO_HOURLY_PER_CLIENT) {
            throw new RoUserError(t('You have sent several orders already. Please wait for staff, or ask a waiter.'));
        }
        if ($tableId && $q("SELECT COUNT(*) FROM restaurant_online_orders WHERE table_id = ? AND status = 'pending'", [$tableId]) >= RO_OPEN_PER_TABLE) {
            throw new RoUserError(t('This table already has orders waiting for staff. Please wait a moment.'));
        }
        if ($q("SELECT COUNT(*) FROM restaurant_online_orders WHERE warehouse_id = ? AND status = 'pending'", [$warehouseId]) >= RO_OPEN_PER_SHOP) {
            throw new RoUserError(t('The restaurant is very busy right now. Please try again in a few minutes.'));
        }
    }
}

if (!function_exists('roPlaceOrder')) {
    /**
     * Save one customer order request (status 'pending').
     * @return array{public_ref:string, order_code:string}
     */
    function roPlaceOrder(PDO $pdo, array $ctx, array $built, string $name, string $phone, string $note, string $clientHash): array
    {
        $wid = $ctx['warehouse']['warehouse_id'];
        $tableId = $ctx['table']['table_id'] ?? null;
        $ref = roNewToken();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO restaurant_online_orders
                    (warehouse_id, table_id, order_type, order_code, public_ref, customer_name, customer_phone, note,
                     items_json, subtotal, status, client_hash, created_at)
                VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())")
                ->execute([$wid, $tableId, $tableId ? 'table' : 'pickup', $ref,
                           $name !== '' ? $name : null, $phone !== '' ? $phone : null, $note !== '' ? $note : null,
                           json_encode($built['items'], JSON_UNESCAPED_UNICODE), $built['subtotal'], $clientHash]);
            $id = (int)$pdo->lastInsertId();
            $code = 'W-' . str_pad((string)($id % 10000), 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE restaurant_online_orders SET order_code = ? WHERE order_id = ?")->execute([$code, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['public_ref' => $ref, 'order_code' => $code];
    }
}

if (!function_exists('roPublicOrder')) {
    /** One order for its own status page — looked up by its secret ref AND its shop. Safe fields only. */
    function roPublicOrder(PDO $pdo, int $warehouseId, string $ref): ?array
    {
        if (!roTokenLooksValid($ref)) return null;
        $st = $pdo->prepare("SELECT o.order_code, o.order_type, o.status, o.reject_reason, o.items_json, o.subtotal, o.created_at,
                                    o.public_ref, t.table_number
                               FROM restaurant_online_orders o LEFT JOIN restaurant_tables t ON t.table_id = o.table_id
                              WHERE o.public_ref = ? AND o.warehouse_id = ? LIMIT 1");
        $st->execute([$ref, $warehouseId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o || !hash_equals((string)$o['public_ref'], $ref)) return null;
        $lines = [];
        foreach ((array)json_decode((string)$o['items_json'], true) as $it) {
            $lines[] = [
                'name'    => (string)($it['product_name'] ?? ''),
                'qty'     => (int)($it['quantity'] ?? 0),
                'price'   => (float)($it['price'] ?? 0),
                'options' => array_map(fn($m) => (string)($m['option_name'] ?? ''), (array)($it['modifiers'] ?? [])),
                'note'    => (string)($it['note'] ?? ''),
            ];
        }
        return [
            'code'     => (string)$o['order_code'],
            'type'     => (string)$o['order_type'],
            'status'   => (string)$o['status'],
            'reason'   => (string)($o['reject_reason'] ?? ''),
            'table'    => $o['table_number'] !== null ? (string)$o['table_number'] : null,
            'subtotal' => (float)$o['subtotal'],
            'lines'    => $lines,
            'created'  => (string)$o['created_at'],
        ];
    }
}

if (!function_exists('roSendSecurityHeaders')) {
    /** Response headers for every public menu page / API response. */
    function roSendSecurityHeaders(bool $isJson = false): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');          // the link token must never travel in a Referer
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store, max-age=0');
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        } else {
            header('Content-Type: text/html; charset=utf-8');
        }
    }
}
