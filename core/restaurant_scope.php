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
