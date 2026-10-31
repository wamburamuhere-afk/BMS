<?php
/**
 * app/bms/restaurant/sales_report.php — Restaurant Sales report (restaurant_plan.md Phase 6).
 *
 * Sales by order type (Dine in / Take away / Delivery) from the ONE ledger
 * (core/restaurant_dashboard.php::restaurantSalesByOrderType — posted revenue lines of
 * POS sales), plus quantities sold per dish/drink and group (counts only). By waiter
 * and profit already have their own reports (linked). Restaurant shops only.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_dashboard.php';

$page_title = 'Restaurant Sales';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$module_on  = restaurantModuleOn($pdo);
$warehouses = $module_on ? restaurantWarehousesForSelect($pdo) : [];

$isDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$shopId = (int)($_GET['shop'] ?? 0);
$ids = array_map(fn($w) => (int)$w['warehouse_id'], $warehouses);
if ($shopId > 0) $ids = in_array($shopId, $ids, true) && userCan('warehouse', $shopId) ? [$shopId] : [];

$byType = $ids ? restaurantSalesByOrderType($pdo, $from, $to, $ids) : [];
$items  = $ids ? restaurantItemsSold($pdo, $from, $to, $ids) : [];
$total  = array_sum(array_column($byType, 'sales'));
$orders = array_sum(array_column($byType, 'orders'));
$typeLabels = ['dine_in' => t('Dine in'), 'take_away' => t('Take away'), 'delivery' => t('Delivery'), 'walk_in' => t('Counter sale'), 'customer' => t('Customer sale'), 'online' => t('Online')];
$cur = getSetting('currency', 'TZS');
?>

<div class="container-fluid mt-3 mt-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
        <h4 class="mb-0"><i class="bi bi-bar-chart text-primary me-2"></i><?= t('Restaurant Sales') ?></h4>
        <div class="d-flex gap-2">
            <?php if (canView('pos_user_sales_report')): ?>
            <a href="<?= getUrl('pos_user_sales_report') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-badge me-1"></i><?= t('By waiter') ?></a>
            <?php endif; ?>
            <?php if (canView('pos_profit_report')): ?>
            <a href="<?= getUrl('pos_profit_report') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-graph-up-arrow me-1"></i><?= t('Profit') ?></a>
            <?php endif; ?>
        </div>
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
                    <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int)$w['warehouse_id'] ?>" <?= $shopId === (int)$w['warehouse_id'] ? 'selected' : '' ?>><?= caseFormat($w['warehouse_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-auto"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i><?= t('Show') ?></button></div>
        </div>
    </form>

    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md-4"><div class="card rr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary text-truncate"><?= number_format($total, 0) ?></div><div class="small text-muted"><?= sprintf(t('Sales (%s)'), htmlspecialchars($cur)) ?></div></div></div>
        <div class="col-6 col-md-4"><div class="card rr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary"><?= (int)$orders ?></div><div class="small text-muted"><?= t('Orders') ?></div></div></div>
        <div class="col-12 col-md-4"><div class="card rr-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary"><?= $orders ? number_format($total / $orders, 0) : 0 ?></div><div class="small text-muted"><?= t('Average order') ?></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><?= t('By order type') ?></h6></div>
        <div class="card-body">
            <table id="rrTypeTable" class="table table-hover align-middle w-100">
                <thead class="table-dark"><tr><th><?= t('Order type') ?></th><th class="text-end"><?= t('Orders') ?></th><th class="text-end"><?= t('Sales') ?></th><th class="text-end"><?= t('Share') ?></th></tr></thead>
                <tbody>
                <?php foreach ($byType as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= safe_output($typeLabels[$r['sale_type']] ?? $r['sale_type']) ?></td>
                        <td class="text-end"><?= (int)$r['orders'] ?></td>
                        <td class="text-end" data-order="<?= $r['sales'] ?>"><?= number_format($r['sales'], 0) ?></td>
                        <td class="text-end"><?= $total > 0 ? round($r['sales'] / $total * 100) : 0 ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i><?= t('From the posted accounts (same figures as the Income Statement). Returns are shown in the profit report.') ?></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><?= t('Dishes and drinks sold') ?></h6></div>
        <div class="card-body">
            <table id="rrItemsTable" class="table table-hover align-middle w-100">
                <thead class="table-dark"><tr><th><?= t('Item') ?></th><th><?= t('Group') ?></th><th><?= t('Type') ?></th><th class="text-end"><?= t('Quantity') ?></th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="fw-semibold"><?= safe_output($it['product_name']) ?></td>
                        <td><?= safe_output($it['group_name'] ?: t('Other')) ?></td>
                        <td><?= (int)$it['is_service'] ? t('Dish') : t('Stock item') ?></td>
                        <td class="text-end"><?= rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>.rr-stat { background:#e7f0ff; border:1px solid #b6ccfe; }</style>
<script>
$(function () {
    const lang = { emptyTable: <?= json_encode(t('No records found.')) ?>, zeroRecords: <?= json_encode(t('No matching records.')) ?> };
    ['#rrTypeTable', '#rrItemsTable'].forEach(function (id) {
        if ($(id).length && !$.fn.DataTable.isDataTable(id)) {
            $(id).DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [], dom: 'rtipB', language: lang,
                buttons: [{ extend: 'excelHtml5', className: 'd-none' }] });
        }
    });
});
</script>

<?php
require_once 'footer.php';
ob_end_flush();
