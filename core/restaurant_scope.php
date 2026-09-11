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

if (!function_exists('restaurantWarehousesForSelect')) {
    /**
     * @return array<int, array{warehouse_id:int, warehouse_name:string, project_id:?int, pos_mode:string}>
     */
    function restaurantWarehousesForSelect(PDO $pdo): array
    {
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
