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
        return canView('restaurant_pos') && posSimpleModeEnabled() && restaurantSchemaReady($pdo);
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
