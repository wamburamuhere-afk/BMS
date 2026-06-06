<?php
// Include roots configuration
require_once dirname(__DIR__, 3) . '/roots.php';
require_once dirname(__DIR__, 3) . '/core/payment_source.php';   // cashBankAccounts()

// Enforce permission BEFORE any output
autoEnforcePermission('payroll');

includeHeader();

$can_edit = isAdmin() || canEdit('payroll');

// Activity log — viewing the statutory schedule (sensitive financial obligations).
logActivity($pdo, $_SESSION['user_id'] ?? 0, "Viewed Statutory Remittances schedule");

// Pull the schedule (newest period first, then PAYE/NSSF/SDL).
$rows = $pdo->query("
    SELECT r.*, a.account_name AS paid_from_name
      FROM statutory_remittances r
      LEFT JOIN accounts a ON a.account_id = r.paid_from_account_id
     WHERE r.status <> 'cancelled'
  ORDER BY r.period DESC, FIELD(r.tax_type,'paye','nssf','sdl')
")->fetchAll(PDO::FETCH_ASSOC);

$today = new DateTime('today');
$sum_pending = 0.0; $sum_overdue = 0.0; $count_overdue = 0; $sum_paid = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'paid') { $sum_paid += (float)$r['amount']; continue; }
    $sum_pending += (float)$r['amount'];
    if (!empty($r['due_date']) && new DateTime($r['due_date']) < $today && (float)$r['amount'] > 0) {
        $sum_overdue += (float)$r['amount']; $count_overdue++;
    }
}
$labels = ['paye' => 'PAYE (income tax)', 'nssf' => 'NSSF (pension)', 'sdl' => 'SDL (skills levy)'];
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="bi bi-receipt text-primary"></i> Statutory Remittances</h2>
            <p class="text-muted mb-0">PAYE, NSSF &amp; SDL owed to the authorities — due 7 days after month-end</p>
        </div>
        <a href="<?= getUrl('payroll') ?>" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i> Back to Payroll</a>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="fs-5 fw-bold text-primary">TSh <?= number_format($sum_pending, 0) ?></div>
                <div class="small text-muted">Pending total</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-danger">TSh <?= number_format($sum_overdue, 0) ?></div>
                <div class="small text-muted">Overdue (<?= $count_overdue ?>)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-dark"><?= count($rows) ?></div>
                <div class="small text-muted">Obligations</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold" style="color:#052c65;">TSh <?= number_format($sum_paid, 0) ?></div>
                <div class="small text-muted">Remitted to date</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom"><h5 class="mb-0 fw-bold">Remittance Schedule</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="remitTable" class="table table-hover align-middle" style="width:100%">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Tax</th>
                            <th class="text-end">Amount</th>
                            <th>Due date</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $amount = (float)$r['amount'];
                        $isPaid = $r['status'] === 'paid';
                        $due = !empty($r['due_date']) ? new DateTime($r['due_date']) : null;
                        $overdue = !$isPaid && $due && $due < $today && $amount > 0;
                        $daysLeft = $due ? (int)$today->diff($due)->format('%r%a') : null;

                        if ($isPaid)      { $badge = 'background:#052c65;color:#fff;'; $statusTxt = 'Remitted'; }
                        elseif ($overdue) { $badge = 'background:#dc3545;color:#fff;'; $statusTxt = 'Overdue'; }
                        else              { $badge = 'background:#e9ecef;color:#495057;'; $statusTxt = 'Pending'; }
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= safe_output(date('M Y', strtotime($r['period'] . '-01'))) ?></td>
                            <td><?= safe_output($labels[$r['tax_type']] ?? strtoupper($r['tax_type'])) ?></td>
                            <td class="text-end fw-bold">TSh <?= number_format($amount, 0) ?></td>
                            <td>
                                <?= $due ? safe_output($due->format('d M Y')) : '—' ?>
                                <?php if (!$isPaid && $due): ?>
                                    <div class="small <?= $overdue ? 'text-danger' : 'text-muted' ?>">
                                        <?= $overdue ? abs($daysLeft) . ' day(s) overdue' : ($daysLeft . ' day(s) left') ?>
                                    </div>
                                <?php elseif ($isPaid && !empty($r['paid_date'])): ?>
                                    <div class="small text-muted">paid <?= safe_output(date('d M Y', strtotime($r['paid_date']))) ?><?= $r['paid_from_name'] ? ' · ' . safe_output($r['paid_from_name']) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><span class="badge" style="<?= $badge ?>padding:6px 12px;border-radius:20px;"><?= $statusTxt ?></span></td>
                            <td class="text-end">
                                <?php if (!$isPaid && $amount > 0 && $can_edit): ?>
                                    <button class="btn btn-sm btn-primary" onclick="remit(<?= (int)$r['remittance_id'] ?>, '<?= safe_output($labels[$r['tax_type']] ?? $r['tax_type']) ?>', <?= $amount ?>)">
                                        <i class="bi bi-cash-coin me-1"></i> Remit
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small"><?= $isPaid ? 'Done' : ($amount <= 0 ? 'Nil' : '—') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
// Cash/bank accounts as the "Paid From" source for remitting tax.
window.PR_CASH_ACCOUNTS = <?= json_encode(array_map(fn($a) => [
    'id'   => (int)$a['account_id'],
    'text' => $a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')
], cashBankAccounts($pdo))) ?>;

$(function () {
    if (!$.fn.DataTable.isDataTable('#remitTable')) {
        $('#remitTable').DataTable({
            responsive: false, scrollX: true, pageLength: 25, order: [],
            language: { emptyTable: 'No statutory obligations yet — process a payroll first.' }
        });
    }
});

function remit(id, label, amount) {
    const opts = {};
    (window.PR_CASH_ACCOUNTS || []).forEach(a => opts[a.id] = a.text);
    Swal.fire({
        title: 'Remit ' + label,
        html: 'Amount: <b>TSh ' + Number(amount).toLocaleString() + '</b>',
        input: 'select',
        inputOptions: opts,
        inputPlaceholder: 'Paid From account…',
        text: 'Choose the cash/bank account the tax is paid from.',
        showCancelButton: true,
        confirmButtonText: 'Remit',
        confirmButtonColor: '#0d6efd',
        inputValidator: v => (!v ? 'Please choose the Paid From account.' : undefined)
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(APP_URL + '/api/remit_statutory', { remittance_id: id, paid_from_account_id: r.value }, res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Done!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }));
    });
}
</script>

<?php require_once dirname(__DIR__, 3) . '/footer.php'; ?>
