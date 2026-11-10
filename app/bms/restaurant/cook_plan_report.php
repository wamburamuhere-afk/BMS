<?php
/**
 * app/bms/restaurant/cook_plan_report.php — Planned vs actual (restaurant costing plan, Phase 4).
 *
 * For each dish and day cooked on the Cooking plan: plates planned, sold (from the till), left (counted at the end of
 * the day) and not accounted for (planned − sold − left: eaten by staff, given away, spilled). With the cost right:
 * the sales expected if every plate sold against the real sales, the gross profit expected against the real one, and
 * the sales lost to plates that were not sold. A day whose cooking is still open shows no "left" yet.
 *
 * Logic: core/restaurant_cook_plan.php::rcpReport(). Restaurant shops only, shop scope.
 * Gate: Restaurant POS + (edit, cost right, reports access or admin); money columns only for restaurant_costing.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_cook_plan.php';

$page_title = 'Planned vs actual';
require_once 'header.php';

$can_cost = restaurantCanSeeCost();
$allowed  = canView('restaurant_pos') && (rcpCanCook() || $can_cost || isAdmin() || (function_exists('hasReportsAccess') && hasReportsAccess()));
if (!$allowed) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$module_on  = restaurantModuleOn($pdo) && rcpReady($pdo);
$warehouses = $module_on ? restaurantWarehousesForSelect($pdo) : [];

$isDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime((string)$d) !== false;
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$shopId = (int)($_GET['shop'] ?? 0);
$ids = array_map(fn($w) => (int)$w['warehouse_id'], $warehouses);
if ($shopId > 0) $ids = in_array($shopId, $ids, true) && userCan('warehouse', $shopId) ? [$shopId] : [];

$rep = $ids ? rcpReport($pdo, $ids, $from, $to) : ['rows' => [], 'totals' => []];
$rows = $rep['rows']; $T = $rep['totals'];
$fmt  = fn($n) => $n === null ? '—' : number_format((float)$n, abs((float)$n - round((float)$n)) < 0.005 ? 0 : 2);
$cur  = getSetting('currency', 'TZS');
$sellRate = !empty($T['planned']) ? round($T['sold'] / $T['planned'] * 100) : null;
?>

<div class="container-fluid mt-3 mt-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
        <h4 class="mb-0"><i class="bi bi-clipboard-data text-primary me-2"></i><?= t('Planned vs actual') ?></h4>
        <a href="<?= getUrl('restaurant/cooking') ?>" class="btn btn-outline-primary btn-sm"><i class="ri ri-cooking-pot me-1"></i><?= t('Cooking plan') ?></a>
    </div>

    <?php if (!$module_on || !$warehouses): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i><?= t('This report is available for restaurant shops in Smart POS.') ?></div>
    <?php else: ?>

    <form class="card border-0 shadow-sm mb-3" method="get">
        <div class="card-body py-3 row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1"><?= t('From') ?></label><input type="date" class="form-control form-control-sm" name="from" value="<?= htmlspecialchars($from) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1"><?= t('To') ?></label><input type="date" class="form-control form-control-sm" name="to" value="<?= htmlspecialchars($to) ?>"></div>
            <?php if (count($warehouses) > 1): ?>
            <div class="col-12 col-md-3"><label class="form-label small mb-1"><?= wLabel('Warehouse', 'Shop') ?></label>
                <select class="form-select form-select-sm" name="shop">
                    <option value="0"><?= t('All') ?></option>
                    <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['warehouse_id'] ?>" <?= $shopId === (int)$w['warehouse_id'] ? 'selected' : '' ?>><?= caseFormat($w['warehouse_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-auto"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i><?= t('Show') ?></button></div>
        </div>
    </form>

    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md"><div class="card cpr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary"><?= $fmt($T['planned'] ?? 0) ?></div><div class="small text-muted"><?= t('Plates planned') ?></div></div></div>
        <div class="col-6 col-md"><div class="card cpr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-success"><?= $fmt($T['sold'] ?? 0) ?><?= $sellRate !== null ? ' <small class="fs-6">(' . $sellRate . '%)</small>' : '' ?></div><div class="small text-muted"><?= t('Plates sold (till)') ?></div></div></div>
        <div class="col-6 col-md"><div class="card cpr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-warning"><?= $fmt($T['left'] ?? 0) ?></div><div class="small text-muted"><?= t('Plates left') ?></div></div></div>
        <div class="col-6 col-md"><div class="card cpr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-danger"><?= $fmt($T['unaccounted'] ?? 0) ?></div><div class="small text-muted"><?= t('Not accounted for') ?></div></div></div>
        <?php if ($can_cost): ?>
        <div class="col-12 col-md"><div class="card cpr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-danger"><?= htmlspecialchars($cur) ?> <?= $fmt($T['lost'] ?? 0) ?></div><div class="small text-muted"><?= t('Sales lost to plates not sold') ?></div></div></div>
        <?php endif; ?>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <table id="cprTable" class="table table-hover align-middle w-100">
            <thead class="cpr-thead"><tr>
                <th><?= t('S/No.') ?></th><th><?= t('Date') ?></th><th><?= t('Dish') ?></th><th class="text-end"><?= t('Planned') ?></th><th class="text-end"><?= t('Sold') ?></th>
                <th class="text-end"><?= t('Left') ?></th><th class="text-end"><?= t('Not accounted for') ?></th>
                <?php if ($can_cost): ?><th class="text-end"><?= t('Expected sales') ?></th><th class="text-end"><?= t('Real sales') ?></th><th class="text-end"><?= t('Cost') ?></th>
                <th class="text-end"><?= t('Expected gross profit') ?></th><th class="text-end"><?= t('Real gross profit') ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td data-order="<?= htmlspecialchars($r['date']) ?>"><?= date('d/m/Y', strtotime($r['date'])) ?><?= count($warehouses) > 1 ? '<div class="small text-muted">' . caseFormat((string)$r['shop']) . '</div>' : '' ?></td>
                    <td class="fw-semibold"><?= safe_output(caseFormat((string)$r['dish'])) ?><?= $r['closed'] ? '' : ' <span class="badge rounded-pill" style="background:#cfe2ff;color:#084298;">' . t('Cooking') . '</span>' ?></td>
                    <td class="text-end"><?= $fmt($r['planned']) ?></td>
                    <td class="text-end"><?= $fmt($r['sold']) ?></td>
                    <td class="text-end"><?= $fmt($r['left']) ?></td>
                    <td class="text-end <?= ($r['unaccounted'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>"><?= $fmt($r['unaccounted']) ?></td>
                    <?php if ($can_cost): ?>
                    <td class="text-end"><?= $fmt($r['expected_sales']) ?></td>
                    <td class="text-end"><?= $fmt($r['sales']) ?></td>
                    <td class="text-end"><?= $fmt($r['cost']) ?></td>
                    <td class="text-end"><?= $fmt($r['expected_gp']) ?></td>
                    <td class="text-end fw-semibold <?= $r['actual_gp'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= $fmt($r['actual_gp']) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot><tr class="fw-bold">
                <td></td><td></td><td><?= t('Total') ?></td><td class="text-end"><?= $fmt($T['planned']) ?></td><td class="text-end"><?= $fmt($T['sold']) ?></td>
                <td class="text-end"><?= $fmt($T['left']) ?></td><td class="text-end"><?= $fmt($T['unaccounted']) ?></td>
                <?php if ($can_cost): ?><td class="text-end"><?= $fmt($T['expected_sales']) ?></td><td class="text-end"><?= $fmt($T['sales']) ?></td><td class="text-end"><?= $fmt($T['cost']) ?></td>
                <td class="text-end"><?= $fmt($T['expected_gp']) ?></td><td class="text-end"><?= $fmt($T['actual_gp']) ?></td><?php endif; ?>
            </tr></tfoot>
            <?php endif; ?>
        </table>
        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i><?= t('Sold comes from the till for each dish and day. Not accounted for = planned − sold − left: eaten by staff, given away or spilled. Gross profit is before gas, staff and rent; your true profit is in the Profit Report.') ?></div>
    </div></div>
    <?php endif; ?>
</div>

<style>
.cpr-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
.cpr-thead th { background:#f4f8ff; color:#0b3d91; font-weight:700; white-space:nowrap; border-bottom:2px solid #cfe2ff; }
</style>
<script>
$(function () {
    if (!$('#cprTable').length) return;
    const dt = $('#cprTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[1, 'desc']], dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none' }], columnDefs: [{ targets: 0, orderable: false }],
        language: { emptyTable: <?= json_encode(t('Nothing was cooked on the Cooking plan in these dates.')) ?>, zeroRecords: <?= json_encode(t('No matching records.')) ?> } });
    dt.on('draw.dt', function () { const s = dt.page.info().start; dt.column(0, { search: 'applied', order: 'applied', page: 'current' }).nodes().each((c, i) => { c.innerHTML = s + i + 1; }); });
});
</script>

<?php
require_once 'footer.php';
ob_end_flush();
