/**
 * BMS — Project → Warehouse cascade (single source of truth)
 *
 * THE RULE (server half lives in core/warehouse_scope.php):
 *   - Project selected → the Warehouse dropdown shows ONLY that project's warehouses.
 *   - No project       → it shows ONLY warehouses not linked to any project.
 *   Never "all warehouses" as a fallback.
 *
 * Two ways to consume it — both share warehouseMatchesProject(), so the rule
 * itself is written exactly once:
 *
 * 1. Native <select> whose options carry data-project-id
 *    (rendered by renderWarehouseOptions() in core/warehouse_scope.php):
 *
 *      bindWarehouseToProject({
 *          project:    '#project_id',      // default
 *          warehouse:  '#warehouse_id',    // default
 *          onFiltered: function (cleared) { ... }  // optional; runs after every
 *                                                  // project change (not the initial
 *                                                  // page-load pass); cleared=true when
 *                                                  // the selected warehouse was reset
 *      });
 *
 * 2. Select2 / custom-rebuild pages that hold a JS array of warehouses
 *    (each item carrying .project_id):
 *
 *      filterWarehousesForProject(ALL_WAREHOUSES, projectId)  // → filtered array
 *
 * Do not copy this logic into a page — the regression guard
 * tests/test_warehouse_project_filter_cli.php forbids local re-implementations.
 */

function warehouseMatchesProject(projectId, warehouseProjectId) {
    const pid  = parseInt(projectId, 10)          || 0;
    const wpid = parseInt(warehouseProjectId, 10) || 0;
    return pid === 0 ? wpid === 0 : wpid === pid;
}

function filterWarehousesForProject(warehouses, projectId) {
    return (warehouses || []).filter(function (w) {
        return warehouseMatchesProject(projectId, w.project_id);
    });
}

function bindWarehouseToProject(opts) {
    opts = opts || {};
    const projectSel   = opts.project   || '#project_id';
    const warehouseSel = opts.warehouse || '#warehouse_id';
    const onFiltered   = opts.onFiltered || null;

    function apply(isInitial) {
        const projectId = $(projectSel).val();
        const $wh = $(warehouseSel);

        $wh.find('option').each(function () {
            if ($(this).val() === '') { $(this).show(); return; }
            warehouseMatchesProject(projectId, $(this).data('project-id'))
                ? $(this).show() : $(this).hide();
        });

        // The initial pass must not clear a saved value (edit pages restore
        // the stored warehouse before the user has touched anything).
        if (!isInitial) {
            let cleared = false;
            const sel = $wh.find('option:selected');
            if (sel.length && sel.css('display') === 'none') {
                $wh.val('');
                cleared = true;
            }
            if (onFiltered) onFiltered(cleared);
        }
    }

    $(projectSel).on('change', function () { apply(false); });
    apply(true);

    return { refresh: function () { apply(false); } };
}
