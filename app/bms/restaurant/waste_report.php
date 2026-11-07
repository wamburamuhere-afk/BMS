<?php
/**
 * app/bms/restaurant/waste_report.php — Waste & cancellations (restaurant_order_flow_plan.md).
 *
 * Every kitchen line that was cancelled: before the kitchen started (no cost — returned by the cashier,
 * removed while waiting) or after (waste — the reason, who approved it, and its cost: posted to the
 * ledger for recipe dishes / stock items, an estimate for a dish without a recipe).
 * Reads restaurant_order_cancellations (the operational record). The money figure shown as "posted" is
 * the same amount the waste journal entry carries (entity_type 'kitchen_waste').
 * Gate: Restaurant POS + (confirm/approve right, reports access, or admin). Restaurant shops only, shop scope.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_order_flow.php';

$page_title = 'Waste & cancellations';
require_once 'header.php';

$allowed = canView('restaurant_pos') && (isAdmin() || rofCanConfirm($pdo) || (function_exists('hasReportsAccess') && hasReportsAccess()));
if (!$allowed) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$module_on  = restaurantModuleOn($pdo) && rofReady($pdo);
$warehouses = $module_on ? restaurantWarehousesForSelect($pdo) : [];

$isDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$shopId = (int)($_GET['shop'] ?? 0);
$ids = array_map(fn($w) => (int)$w['warehouse_id'], $warehouses);
if ($shopId > 0) $ids = in_array($shopId, $ids, true) && userCan('warehouse', $shopId) ? [$shopId] : [];

$rows = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT c.id, c.created_at, c.quantity, c.stage, c.source, c.reason, c.est_cost, c.posted_cost, c.journal_entry_id,
                                p.product_name, w.warehouse_name, rt.table_number, kt.order_label,
                                TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS by_name, u.username,
                                TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))) AS approved_name
                           FROM restaurant_order_cancellations c
                      LEFT JOIN products p ON p.product_id = c.product_id
                      LEFT JOIN warehouses w ON w.warehouse_id = c.warehouse_id
                      LEFT JOIN restaurant_tables rt ON rt.table_id = c.table_id
                      LEFT JOIN kitchen_tickets kt ON kt.ticket_id = c.ticket_id
                      LEFT JOIN users u ON u.user_id = c.cancelled_by
                      LEFT JOIN users a ON a.user_id = c.approved_by
                          WHERE c.warehouse_id IN ($ph) AND DATE(c.created_at) BETWEEN ? AND ?
                       ORDER BY c.created_at DESC, c.id DESC");
    $st->execute(array_merge($ids, [$from, $to]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
$waste   = array_values(array_filter($rows, fn($r) => $r['stage'] === 'after_kitchen'));
$posted  = array_sum(array_map(fn($r) => (float)$r['posted_cost'], $rows));
$estOnly = array_sum(array_map(fn($r) => (float)$r['posted_cost'] > 0 ? 0 : (float)$r['est_cost'], $waste));
$cur = getSetting('currency', 'TZS');
$sources = [
    'returned' => t('Returned by cashier'), 'removed' => t('Removed from the order'), 'cancel' => t('Cancelled line'),
    'order_cancel' => t('Customer left'), 'bill' => t('Left off the bill'), 'void' => t('Sale voided'), 'pickup' => t('Not collected'),
];
$fmt = fn($n) => number_format((float)$n, abs((float)$n - round((float)$n)) < 0.005 ? 0 : 2);
$fmtQ = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
?>

<div class="container-fluid mt-3 mt-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap" style="position:sticky;top:0;z-index:1020;background:#fff;">
        <h4 class="mb-0"><i class="bi bi-trash3 text-primary me-2"></i><?= t('Waste & cancellations') ?></h4>
        <a href="<?= getUrl('restaurant/sales-report') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-bar-chart me-1"></i><?= t('Restaurant Sales') ?></a>
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
                <select class="form-select form-select-sm select2-static" name="shop">
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
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-4 fw-bold text-primary" id="stCancelled"><?= count($rows) ?></div><div class="small text-muted"><?= t('Cancelled lines') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-4 fw-bold text-primary" id="stWaste"><?= count($waste) ?></div><div class="small text-muted"><?= t('Cooked, then cancelled (waste)') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-4 fw-bold text-danger" id="stPosted"><?= htmlspecialchars($cur) ?> <?= $fmt($posted) ?></div><div class="small text-muted"><?= t('Waste cost in the books') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-4 fw-bold text-warning" id="stEst"><?= htmlspecialchars($cur) ?> <?= $fmt($estOnly) ?></div><div class="small text-muted"><?= t('Estimated (dishes with no recipe)') ?></div></div></div>
    </div>

    <div id="tableView" class="card border-0 shadow-sm"><div class="card-body">
        <table id="wasteTable" class="table table-hover align-middle w-100">
            <thead class="table-dark"><tr>
                <th><?= t('S/No.') ?></th><th><?= t('Date') ?></th><th><?= t('Order') ?></th><th><?= t('Item') ?></th><th class="text-end"><?= t('Qty') ?></th>
                <th><?= t('Stage') ?></th><th><?= t('Why') ?></th><th><?= t('Reason') ?></th><th class="text-end"><?= t('Cost') ?></th><th><?= t('By') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                $after = $r['stage'] === 'after_kitchen';
                $order = $r['table_number'] !== null ? t('Table') . ' ' . $r['table_number'] : (string)($r['order_label'] ?? '');
                $cost = (float)$r['posted_cost'] > 0 ? (float)$r['posted_cost'] : (float)$r['est_cost'];
                $by = trim((string)$r['by_name']) ?: (string)$r['username'];
                if (trim((string)$r['approved_name']) !== '' && trim((string)$r['approved_name']) !== $by) $by .= ' · ' . t('approved by') . ' ' . trim((string)$r['approved_name']); ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td data-order="<?= htmlspecialchars($r['created_at']) ?>"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                    <td><?= safe_output($order, '—') ?><?= count($warehouses) > 1 ? '<div class="small text-muted">' . caseFormat($r['warehouse_name']) . '</div>' : '' ?></td>
                    <td><?= safe_output(caseFormat((string)$r['product_name'])) ?></td>
                    <td class="text-end"><?= $fmtQ($r['quantity']) ?></td>
                    <td><span class="badge" style="background:<?= $after ? '#dc3545' : '#e9ecef' ?>;color:<?= $after ? '#fff' : '#495057' ?>;"><?= $after ? t('Waste') : t('Not cooked') ?></span></td>
                    <td><?= safe_output($sources[$r['source']] ?? $r['source']) ?></td>
                    <td><?= safe_output($r['reason'], '—') ?></td>
                    <td class="text-end"><?= $cost > 0 ? $fmt($cost) . ((float)$r['posted_cost'] > 0 ? '' : ' <span class="small text-muted">(' . t('est.') . ')</span>') : '—' ?></td>
                    <td><?= safe_output($by, '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <div id="cardView" class="row g-2 d-none"></div>
    <?php endif; ?>
</div>

<script>
$(function () {
    $('.select2-static').select2({ theme: 'bootstrap-5', width: '100%' });
    if (!$('#wasteTable').length) return;
    const table = $('#wasteTable').DataTable({
        responsive: false, scrollX: true, pageLength: 25, order: [[1, 'desc']], dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none' }],
        columnDefs: [{ targets: 0, orderable: false }],
        language: { emptyTable: <?= json_encode(t('Nothing was cancelled in these dates.')) ?>, zeroRecords: <?= json_encode(t('No matching records.')) ?> },
        drawCallback: function () { renderCards(this.api().rows({ page: 'current' }).nodes().toArray()); }
    });
    function applyView() {
        const m = window.innerWidth < 768;
        $('#tableView').toggleClass('d-none', m); $('#cardView').toggleClass('d-none', !m);
    }
    applyView(); $(window).on('resize', applyView);
    function renderCards(nodes) {
        if (!nodes.length) { $('#cardView').html(`<div class="col-12 text-center py-5 text-muted">${<?= json_encode(t('Nothing was cancelled in these dates.')) ?>}</div>`); return; }
        let html = '';
        nodes.forEach(tr => {
            const c = tr.children;
            html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between gap-2"><div class="fw-bold">${c[4].innerHTML} × ${c[3].innerHTML}</div><div>${c[5].innerHTML}</div></div>
                <div class="small text-muted">${c[1].innerHTML} · ${c[2].innerHTML}</div>
                <div class="small mt-1">${c[6].innerHTML} — ${c[7].innerHTML}</div>
                <div class="d-flex justify-content-between small mt-1"><span>${c[9].innerHTML}</span><span class="fw-semibold">${c[8].innerHTML}</span></div>
            </div></div></div>`;
        });
        $('#cardView').html(html);
    }
});
</script>

<?php
require_once 'footer.php';
ob_end_flush();
