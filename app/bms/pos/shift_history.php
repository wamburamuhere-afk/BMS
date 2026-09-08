<?php
// scope-audit: skip — cash_register_shifts has no project/warehouse dimension;
// visibility is restricted by cashier ownership instead (see the WHERE clause
// below), same reasoning as zreport.php's own skip marker.
/**
 * Shift History — Phase 9 (pos_upgrade_plan.md §7)
 * Lists past (and active) cash-register shifts with their reconciliation
 * totals, linking through to the printable Z-Report for each.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('pos');

$page_title = 'Shift History';
require_once 'header.php';

$can_view_all = canEdit('pos');   // supervisors/admins see every cashier's shifts
$user_id      = $_SESSION['user_id'];

$where  = $can_view_all ? "1=1" : "sh.user_id = :uid";
$params = $can_view_all ? [] : ['uid' => $user_id];

$stmt = $pdo->prepare("
    SELECT sh.*, u.username AS cashier_name, r.register_name, r.register_code
      FROM cash_register_shifts sh
      LEFT JOIN users u ON sh.user_id = u.user_id
      LEFT JOIN pos_registers r ON sh.register_id = r.register_id
     WHERE $where
     ORDER BY sh.start_time DESC
     LIMIT 200
");
$stmt->execute($params);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currency = getSetting('currency', 'TZS');

$stat_total   = count($shifts);
$stat_active  = 0;
$stat_sales   = 0.0;
$stat_discrep = 0;
foreach ($shifts as $s) {
    if ($s['status'] === 'active') $stat_active++;
    $stat_sales += (float)$s['total_sales'];
    if ($s['status'] === 'closed' && abs((float)$s['cash_difference']) > 0.5) $stat_discrep++;
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Shift History</h4>
        <a href="<?= getUrl('pos') ?>" class="btn btn-primary btn-sm"><i class="bi bi-bag-plus me-1"></i> Open POS</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= $stat_total ?></div>
                <div class="small text-muted">Shifts Shown</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success"><?= $stat_active ?></div>
                <div class="small text-muted">Active Now</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-warning"><?= number_format($stat_sales, 0) ?></div>
                <div class="small text-muted">Total Sales (<?= $currency ?>)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-danger"><?= $stat_discrep ?></div>
                <div class="small text-muted">Cash Discrepancies</div>
            </div>
        </div>
    </div>

    <div id="tableView">
        <table class="table table-hover align-middle w-100">
            <thead class="table-dark">
                <tr>
                    <th>Shift</th><th>Register</th><?php if ($can_view_all): ?><th>Cashier</th><?php endif; ?>
                    <th>Opened</th><th>Closed</th><th class="text-end">Total Sales</th><th class="text-end">Difference</th><th>Status</th><th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shifts as $s): $diff = (float)$s['cash_difference']; ?>
                <tr>
                    <td><?= safe_output($s['shift_code']) ?></td>
                    <td><?= safe_output($s['register_name'], '—') ?></td>
                    <?php if ($can_view_all): ?><td><?= safe_output($s['cashier_name']) ?></td><?php endif; ?>
                    <td><?= date('d/m/Y H:i', strtotime($s['start_time'])) ?></td>
                    <td><?= $s['end_time'] ? date('d/m/Y H:i', strtotime($s['end_time'])) : '—' ?></td>
                    <td class="text-end"><?= number_format((float)$s['total_sales'], 2) ?></td>
                    <td class="text-end <?= $s['status'] === 'closed' ? (abs($diff) < 0.01 ? 'text-success' : 'text-danger fw-bold') : 'text-muted' ?>">
                        <?= $s['status'] === 'closed' ? number_format($diff, 2) : '—' ?>
                    </td>
                    <td><span class="badge bg-<?= $s['status'] === 'active' ? 'success' : 'secondary' ?>"><?= safe_output(ucfirst($s['status'])) ?></span></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= getUrl('pos/zreport') ?>?shift_id=<?= (int)$s['shift_id'] ?>" target="_blank">
                            <i class="bi bi-file-earmark-text"></i> Z-Report
                        </a>
                        <?php if ($can_view_all && $s['status'] === 'active' && (int)$s['user_id'] !== (int)$user_id): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger force-close-btn"
                                data-shift-id="<?= (int)$s['shift_id'] ?>"
                                data-shift-code="<?= htmlspecialchars($s['shift_code'], ENT_QUOTES) ?>"
                                data-cashier="<?= htmlspecialchars($s['cashier_name'] ?? 'Unknown', ENT_QUOTES) ?>"
                                data-register="<?= htmlspecialchars($s['register_name'] ?? '—', ENT_QUOTES) ?>">
                            <i class="bi bi-lock"></i> Force Close
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$shifts): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No shifts found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="cardView" class="row g-2 d-none"></div>
</div>

<script>
// Per-page local convention (this codebase has no global JS output-escaping
// helper from header.php — each page that needs one defines its own copy,
// e.g. app/bms/customer/customers.php). Used below before writing the
// cashier/register name into a Swal.fire html string.
function safeOutput(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderShiftCards(rows) {
    const cardView = document.getElementById('cardView');
    if (!rows.length) { cardView.innerHTML = '<div class="col-12 text-center py-5 text-muted">No shifts found</div>'; return; }
    let html = '';
    rows.forEach(r => {
        html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
            <div class="d-flex justify-content-between"><span class="fw-bold">${r.code}</span><span class="badge bg-${r.status === 'active' ? 'success' : 'secondary'}">${r.status}</span></div>
            <small class="text-muted">${r.register || '—'} · ${r.opened}</small>
            <div class="mt-2 small">Total: ${r.total} ${r.diff ? '· Diff: ' + r.diff : ''}</div>
            <a href="${r.url}" target="_blank" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-file-earmark-text"></i> Z-Report</a>
        </div></div></div>`;
    });
    cardView.innerHTML = html;
}
function applyShiftView() {
    if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
    else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
}
$(document).ready(function () {
    applyShiftView();
    $(window).on('resize', applyShiftView);
    const zreportBaseUrl = '<?= getUrl('pos/zreport') ?>';
    const rows = <?= json_encode(array_map(function ($s) use ($currency) {
        return [
            'code' => $s['shift_code'], 'register' => $s['register_name'], 'status' => $s['status'],
            'opened' => date('d/m/Y H:i', strtotime($s['start_time'])),
            'total' => $currency . ' ' . number_format((float)$s['total_sales'], 2),
            'diff' => $s['status'] === 'closed' ? number_format((float)$s['cash_difference'], 2) : '',
            'shift_id' => (int)$s['shift_id'],
        ];
    }, $shifts)) ?>;
    rows.forEach(r => { r.url = zreportBaseUrl + '?shift_id=' + r.shift_id; });
    renderShiftCards(rows);
});

// Force-close a shift left open by another cashier (crashed browser, forgot to
// log out). Only rendered for canEdit('pos') users, on shifts that aren't the
// viewer's own — see the PHP condition above the button.
$(document).on('click', '.force-close-btn', function () {
    const $btn = $(this);
    const shiftId   = $btn.data('shift-id');
    const shiftCode = String($btn.data('shift-code'));
    const cashier   = String($btn.data('cashier'));
    const register  = String($btn.data('register'));

    Swal.fire({
        title: 'Force-close this shift?',
        html: 'This closes <b>' + safeOutput(shiftCode) + '</b> on <b>' + safeOutput(register) +
              '</b>, opened by <b>' + safeOutput(cashier) + '</b>, using the cash amount you enter ' +
              'below as the counted total. Only do this if that cashier genuinely cannot close it ' +
              'themselves — this action is recorded in the audit log.',
        icon: 'warning',
        input: 'number',
        inputLabel: 'Actual cash counted at this till',
        inputAttributes: { min: 0, step: '0.01' },
        inputValue: 0,
        showCancelButton: true,
        confirmButtonText: 'Force Close',
        confirmButtonColor: '#dc3545'
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $btn.prop('disabled', true);
        $.post('<?= buildUrl('api/pos/close_shift.php') ?>', {
            shift_id: shiftId,
            ending_cash: result.value || 0,
            notes: 'Force-closed by <?= htmlspecialchars($_SESSION['username'] ?? 'admin', ENT_QUOTES) ?>'
        }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Shift Closed', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                $btn.prop('disabled', false);
            }
        }, 'json').fail(function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Force close failed. Please try again.' });
            $btn.prop('disabled', false);
        });
    });
});
</script>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
