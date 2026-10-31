<?php
/**
 * core/restaurant_scope.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — shared helper for every
 * app/bms/restaurant/*.php admin page: the list of warehouses the current
 * user may administer Restaurant data for. Reuses the exact same
 * project+warehouse scoping `app/bms/pos/pos.php` already applies to its own
 * warehouse dropdown (Phase 6), narrowed further to warehouses whose
 * `pos_mode` is not the plain-retail default — administering floors/tables/
 * kitchen stations for a warehouse that will never run restaurant mode isn't
 * a real scenario a page needs to offer.
 */

require_once __DIR__ . '/warehouse_scope.php';

if (!function_exists('restaurantSchemaReady')) {
    /**
     * True once this tenant's database actually has the Phase 30 schema
     * (pos_upgrade_plan.md §9) applied. `restaurant_pos` is a tenant
     * FEATURE-FLAG grant (control-DB, independent of the tenant's own
     * schema) — a tenant can be entitled to the feature before its
     * database migration has run (found live, 2026-09-11: tenant bms_t9005
     * was granted restaurant_pos but its DB never had
     * migrations/tenant/2026_09_11_pos_restaurant_module.php applied,
     * causing every Restaurant page/endpoint to hard-crash with "Unknown
     * column 'pos_mode'" / "Table 'modifier_groups' doesn't exist").
     * Every restaurant/* page and api/restaurant/*.php endpoint must check
     * this BEFORE running any query against the new schema, and degrade to
     * a clear "not set up yet" state instead of a raw exception.
     * Cached per-request (static) — this is checked at the top of every
     * request into this module, so it must not add a repeated SHOW
     * TABLES/SHOW COLUMNS round trip per call within the same request.
     */
    function restaurantSchemaReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $hasPosMode = (bool)$pdo->query("SHOW COLUMNS FROM warehouses LIKE 'pos_mode'")->fetch();
            $hasTables  = (bool)$pdo->query("SHOW TABLES LIKE 'modifier_groups'")->fetch();
            $ready = $hasPosMode && $hasTables;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('restaurantWarehousesForSelect')) {
    /**
     * @return array<int, array{warehouse_id:int, warehouse_name:string, project_id:?int, pos_mode:string}>
     */
    function restaurantWarehousesForSelect(PDO $pdo): array
    {
        if (!restaurantSchemaReady($pdo)) {
            return [];
        }
        $scoped = array_values(array_filter(
            warehousesForSelect($pdo),
            fn($w) => userCan('warehouse', (int)$w['warehouse_id'])
        ));
        if (empty($scoped)) {
            return [];
        }

        $ids = array_map(fn($w) => (int)$w['warehouse_id'], $scoped);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT warehouse_id, pos_mode FROM warehouses WHERE warehouse_id IN ($ph)");
        $stmt->execute($ids);
        $modeById = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $modeById[(int)$row['warehouse_id']] = $row['pos_mode'];
        }

        $restaurantScoped = [];
        foreach ($scoped as $w) {
            $mode = $modeById[(int)$w['warehouse_id']] ?? 'retail';
            if ($mode !== 'retail') {
                $w['pos_mode'] = $mode;
                $restaurantScoped[] = $w;
            }
        }
        return $restaurantScoped;
    }
}

if (!function_exists('restaurantModuleOn')) {
    /**
     * restaurant_plan.md §0 — the tenant-level half of the gate: Restaurant POS
     * entitled (canView checks the tenant entitlement first, even for admins)
     * AND Smart POS (Simple Mode) on AND the schema present. False = every shop
     * behaves as Retail, exactly as before the restaurant work.
     */
    function restaurantModuleOn(PDO $pdo): bool
    {
        require_once __DIR__ . '/pos_nav.php';
        return canView('restaurant_pos') && posSimpleModeEnabled() && restaurantSchemaReady($pdo) && restaurantMenuSchemaReady($pdo);
    }
}

if (!function_exists('restaurantShopActive')) {
    /**
     * restaurant_plan.md §0 — the single gate for every restaurant-only screen
     * change: module on (above) AND this shop's POS Mode is Restaurant/Hybrid.
     */
    function restaurantShopActive(PDO $pdo, int $warehouseId): bool
    {
        if ($warehouseId <= 0 || !restaurantModuleOn($pdo)) return false;
        $st = $pdo->prepare("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?");
        $st->execute([$warehouseId]);
        return in_array($st->fetchColumn(), ['restaurant', 'hybrid'], true);
    }
}

if (!function_exists('restaurantOnlyCompany')) {
    /**
     * restaurant_plan.md "Dashboard, Settings, Reports" — true only when the
     * module is on AND every ACTIVE shop is in Restaurant mode (Hybrid counts
     * as retail+restaurant, so it is NOT restaurant-only). Drives the full
     * restaurant dashboard/settings/reports; anything else keeps today's view.
     * Cached per request.
     */
    function restaurantOnlyCompany(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        if (!restaurantModuleOn($pdo)) return $cache = false;
        $row = $pdo->query("SELECT COUNT(*) AS total, SUM(pos_mode = 'restaurant') AS rest
                              FROM warehouses WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);
        return $cache = ((int)$row['total'] > 0 && (int)$row['rest'] === (int)$row['total']);
    }
}

if (!function_exists('restaurantDefaultFloorId')) {
    /**
     * restaurant_plan.md Phase 0.2 — tables don't need a floor first. Returns
     * the shop's first active floor, creating one default area ("Main Area")
     * when the shop has none, so a table can always be saved. No schema
     * change: restaurant_tables.floor_id stays NOT NULL.
     */
    function restaurantDefaultFloorId(PDO $pdo, int $warehouseId): int
    {
        $st = $pdo->prepare("SELECT floor_id FROM restaurant_floors WHERE warehouse_id = ? AND status = 'active'
                              ORDER BY sort_order, floor_id LIMIT 1");
        $st->execute([$warehouseId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) return $id;
        $pdo->prepare("INSERT INTO restaurant_floors (warehouse_id, name, sort_order, status, created_at, updated_at)
                       VALUES (?, ?, 0, 'active', NOW(), NOW())")
            ->execute([$warehouseId, 'Main Area']);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('restaurantMenuSchemaReady')) {
    /**
     * restaurant_plan.md Phase 1 — products.is_menu_item / is_available exist
     * (migrations/tenant/2026_10_31_restaurant_menu_columns.php). Until the
     * migration has run the restaurant gate stays false, so the tenant keeps
     * today's retail behaviour instead of hitting "Unknown column". Cached.
     */
    function restaurantMenuSchemaReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $ready = (bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'is_available'")->fetch()
                  && (bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'is_menu_item'")->fetch()
                  // Phase 3 — kitchen_tickets.order_label (2026_10_31_restaurant_kitchen_ticket_label)
                  && (bool)$pdo->query("SHOW COLUMNS FROM kitchen_tickets LIKE 'order_label'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('restaurantPrepAreaId')) {
    /**
     * restaurant_plan.md Phase 1 — "Prepared where?" on a menu item:
     * 'kitchen' / 'bar' map to the shop's kitchen_stations rows named
     * 'Kitchen' / 'Bar' (created on first use); 'none' = NULL (soda, water —
     * never sent to the kitchen). A numeric value is an existing station of
     * this shop. Returns the station_id or null.
     */
    function restaurantPrepAreaId(PDO $pdo, int $warehouseId, string $area): ?int
    {
        if ($area === '' || $area === 'none') return null;
        if (ctype_digit($area)) {
            $st = $pdo->prepare("SELECT station_id FROM kitchen_stations WHERE station_id = ? AND warehouse_id = ?");
            $st->execute([(int)$area, $warehouseId]);
            $id = (int)$st->fetchColumn();
            return $id > 0 ? $id : null;
        }
        $name = $area === 'bar' ? 'Bar' : ($area === 'kitchen' ? 'Kitchen' : null);
        if ($name === null) return null;
        $st = $pdo->prepare("SELECT station_id FROM kitchen_stations WHERE warehouse_id = ? AND name = ? AND status = 'active' LIMIT 1");
        $st->execute([$warehouseId, $name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) return $id;
        $pdo->prepare("INSERT INTO kitchen_stations (warehouse_id, name, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())")
            ->execute([$warehouseId, $name]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('restaurantCreateMenuItem')) {
    /**
     * restaurant_plan.md Phase 1 — create ONE new menu item for a restaurant
     * shop. Shared by api/restaurant/save_menu_item.php and the quick start
     * (api/restaurant/quick_setup.php). Caller owns the transaction and the
     * permission/scope checks.
     *
     *  - dish   → no-stock product (is_service = 1) on this shop: always sells.
     *  - bought → stock product; opening stock through receiveProductBatch() +
     *             postStockAdjustmentGl(), the same path as Add Product.
     *
     * $d keys: type (dish|bought), name, price, cost, opening, prep_area,
     *          category_id (?int), is_available (0/1)
     * @return int new product_id
     * @throws Exception with a user-facing message on bad input / duplicate name
     */
    function restaurantCreateMenuItem(PDO $pdo, int $warehouseId, array $d, int $userId): int
    {
        require_once __DIR__ . '/stock_intake.php';
        require_once __DIR__ . '/stock_posting.php';
        $isDish  = ($d['type'] ?? 'dish') === 'dish';
        $name    = trim((string)($d['name'] ?? ''));
        $price   = (float)($d['price'] ?? 0);
        $cost    = $isDish ? 0.0 : max(0, (float)($d['cost'] ?? 0));
        $opening = $isDish ? 0.0 : max(0, (float)($d['opening'] ?? 0));
        if ($name === '') throw new Exception(t('Name is required.'));
        if ($price <= 0) throw new Exception(t('Enter a selling price.'));
        $dup = $pdo->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))");
        $dup->execute([$name]);
        if ((int)$dup->fetchColumn() > 0) throw new Exception(sprintf(t('An item named "%s" already exists.'), $name));

        $area = (string)($d['prep_area'] ?? '');
        $data = [
            'product_name'       => $name,
            'category_id'        => !empty($d['category_id']) ? (int)$d['category_id'] : null,
            'selling_price'      => $price,
            'min_selling_price'  => 0,
            'cost_price'         => $cost,
            'purchase_price'     => $cost,
            'unit'               => 'pcs',
            'unit_of_measure'    => 'pcs',
            'is_service'         => $isDish ? 1 : 0,
            'track_inventory'    => $isDish ? 0 : 1,
            'warehouse_id'       => $warehouseId,
            'kitchen_station_id' => restaurantPrepAreaId($pdo, $warehouseId, $area !== '' ? $area : ($isDish ? 'kitchen' : 'none')),
            'is_menu_item'       => 1,
            'is_available'       => isset($d['is_available']) ? ((int)$d['is_available'] ? 1 : 0) : 1,
            'status'             => 'active',
            'created_by'         => $userId,
        ];
        $cols = implode(', ', array_keys($data));
        $ph   = ':' . implode(', :', array_keys($data));
        $pdo->prepare("INSERT INTO products ($cols) VALUES ($ph)")->execute($data);
        $newId = (int)$pdo->lastInsertId();

        if (!$isDish && $opening > 0) {
            $intake = receiveProductBatch($pdo, [
                'product_id'       => $newId,
                'warehouse_id'     => $warehouseId,
                'quantity'         => $opening,
                'unit_cost'        => $cost,
                'write_batch'      => true,
                'selling_price'    => $price,
                'movement_type'    => 'adjustment_in',
                'reference_type'   => 'manual',
                'reference_id'     => $newId,
                'reference_number' => null,
                'movement_date'    => date('Y-m-d'),
                'created_by'       => $userId,
                'notes'            => 'Initial product stock',
            ]);
            postStockAdjustmentGl($pdo, $intake['movement_id'], $opening, 'adjustment_in', $cost, null, $userId, date('Y-m-d'), 'PROD-' . $newId);
        }
        return $newId;
    }
}

if (!function_exists('restaurantGettingStarted')) {
    /**
     * restaurant_plan.md Phase 1c — the "Getting started" checklist for one
     * restaurant shop. Each step ticks itself from real data.
     * @return array<int, array{key:string, label:string, done:bool, url:string}>
     */
    function restaurantGettingStarted(PDO $pdo, int $warehouseId): array
    {
        $count = function (string $sql) use ($pdo, $warehouseId): int {
            try { $st = $pdo->prepare($sql); $st->execute([$warehouseId]); return (int)$st->fetchColumn(); }
            catch (Throwable $e) { return 0; }
        };
        $tables = $count("SELECT COUNT(*) FROM restaurant_tables WHERE warehouse_id = ?");
        $items  = $count("SELECT COUNT(*) FROM products WHERE is_menu_item = 1 AND status = 'active' AND warehouse_id = ?");
        $sales  = $count("SELECT COUNT(*) FROM pos_sales WHERE warehouse_id = ?");
        return [
            ['key' => 'shop',   'label' => t('Your restaurant shop is ready'), 'done' => true,        'url' => 'warehouses'],
            ['key' => 'tables', 'label' => t('Add your tables'),               'done' => $tables > 0, 'url' => 'restaurant/floors'],
            ['key' => 'menu',   'label' => t('Add dishes and drinks'),         'done' => $items > 0,  'url' => 'restaurant/menu'],
            ['key' => 'order',  'label' => t('Take your first order'),         'done' => $sales > 0,  'url' => 'pos'],
        ];
    }
}

if (!function_exists('restaurantKitchenMode')) {
    /**
     * restaurant_plan.md Phase 3 — "How do orders reach the kitchen?" (Selling Settings):
     *   none    = by mouth (default — most small restaurants): no kitchen step on the till
     *   screen  = a phone/tablet in the kitchen shows orders (Kitchen screen)
     *   printer = a kitchen slip prints on Send to Kitchen (the screen still lists it)
     */
    function restaurantKitchenMode(): string
    {
        $m = (string)get_setting('restaurant_kitchen_mode', 'none');
        return in_array($m, ['none', 'screen', 'printer'], true) ? $m : 'none';
    }
}

if (!function_exists('restaurantOrderTypes')) {
    /**
     * restaurant_plan.md Phase 3 — order types offered on the till. Dine in is always on;
     * Take away and Delivery default on and can be switched off in Selling Settings.
     * @return string[]
     */
    function restaurantOrderTypes(): array
    {
        $types = ['dine_in'];
        if (get_setting('restaurant_order_take_away', '1') === '1') $types[] = 'take_away';
        if (get_setting('restaurant_order_delivery', '1') === '1') $types[] = 'delivery';
        return $types;
    }
}
