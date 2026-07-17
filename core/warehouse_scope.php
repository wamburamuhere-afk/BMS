<?php
/**
 * BMS — Warehouse ↔ Project selection (single source of truth)
 *
 * THE RULE (client half lives in assets/js/warehouse-project-filter.js):
 *   - Project selected → the Warehouse dropdown shows ONLY that project's warehouses.
 *   - No project       → it shows ONLY warehouses not linked to any project.
 *   Never "all warehouses" as a fallback.
 *
 * Every page that renders a Project + Warehouse dropdown pair must build its
 * warehouse list with warehousesForSelect() and emit the options with
 * renderWarehouseOptions() (or, for JS-array pages, json-encode the helper's
 * rows). Do not hand-roll the query or the <option> loop — the regression
 * guard tests/test_warehouse_project_filter_cli.php enforces this.
 */

require_once __DIR__ . '/project_scope.php';

if (!function_exists('warehousesForSelect')) {
    /**
     * Active warehouses for a Project+Warehouse dropdown pair, scoped to the
     * current user's assigned projects (admin → all; scoped user → unassigned
     * + own projects' warehouses; user with no assignments → unassigned only).
     * project_id comes back as 0 for warehouses not linked to any project.
     */
    function warehousesForSelect(PDO $pdo): array
    {
        $scope = scopeFilterSqlNullable('project', 'w');
        try {
            return $pdo->query(
                "SELECT w.warehouse_id, w.warehouse_name, w.location,
                        IFNULL(w.project_id, 0) AS project_id
                   FROM warehouses w
                  WHERE w.status = 'active' $scope
               ORDER BY w.warehouse_name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('warehousesForSelect: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('hasAllWarehouseAccess')) {
    /**
     * True for admins and for users explicitly granted "all warehouses"
     * (a user_scope_overrides row with resource_id = NULL — see
     * loadUserScope() in project_scope.php, which turns that into the ['*']
     * sentinel). Use this where there's no single warehouse_id to check via
     * userCan('warehouse', $id) — e.g. deciding whether an unscoped,
     * company-wide view is allowed at all.
     */
    function hasAllWarehouseAccess(): bool
    {
        if (!isset($_SESSION['scope'])) {
            if (isset($_SESSION['user_id']) && function_exists('loadUserScope')) {
                loadUserScope((int)$_SESSION['user_id']);
            } else {
                return false;
            }
        }
        if (!empty($_SESSION['scope']['is_admin'])) return true;
        return in_array('*', $_SESSION['scope']['warehouses'] ?? [], true);
    }
}

if (!function_exists('renderWarehouseOptions')) {
    /**
     * Emit the <option> list for a warehouse <select>. Every option carries
     * data-project-id — the attribute the shared JS filter keys on. The
     * page keeps its own placeholder option ("Select Warehouse").
     */
    function renderWarehouseOptions(array $warehouses, $selectedId = null): string
    {
        $html = '';
        foreach ($warehouses as $w) {
            $sel = ((int)$selectedId > 0 && (int)$selectedId === (int)$w['warehouse_id']) ? ' selected' : '';
            $html .= '<option value="' . (int)$w['warehouse_id'] . '"'
                   . ' data-project-id="' . (int)$w['project_id'] . '"' . $sel . '>'
                   . htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES)
                   . "</option>\n";
        }
        return $html;
    }
}
