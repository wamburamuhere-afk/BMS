<?php
/**
 * app/bms/pos/pos_credit_customers.php — "Who Owes Me"
 * pos_credit_receivables_plan.md Phase 2a.
 *
 * Simple POS only — the dedicated receivables-aging page: every customer
 * with an open (not fully paid, not voided) credit sale, how much they owe,
 * and how their due date stands (upcoming / overdue by how many days).
 * Reads api/pos/get_credit_aging.php (core/pos_credit_aging.php), which is
 * the same source app/bms/customer/customer_details.php's "Madeni" tab
 * (Phase 2b) and dashboard.php's Credit card (Phase 3) also read — one
 * shared calculation, never three different numbers for the same customer.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/pos_nav.php';
autoEnforcePermission('pos');

if (!posSimpleModeEnabled()) {
    header('Location: ' . getUrl('pos_dashboard'));
    exit();
}

$page_title = 'Who Owes Me';
require_once 'header.php';

$can_edit   = canEdit('pos');
$can_delete = canDelete('pos');
?>

<div class="container-fluid mt-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>"><?= t('Dashboard') ?></a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('pos_dashboard') ?>"><?= t('POS Workspace') ?></a></li>
            <li class="breadcrumb-item active"><?= t('Who Owes Me') ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 text-primary"><i class="bi bi-cash-coin me-2"></i><?= t('Who Owes Me') ?></h4>
            <p class="text-muted mb-0 small"><?= t('Customers with an open credit sale — due dates, repayments, overdue.') ?></p>
        </div>
    </div>

    <style>
        /* Same convention used across the app's other stat-card rows
           (e.g. products.php) — light green instead of plain white. */
        .custom-stat-card {
            background-color: #d1e7dd !important;
            border-color: #badbcc !important;
            transition: transform 0.2s;
            border-radius: 12px;
        }
        .custom-stat-card:hover { transform: translateY(-3px); }
        .custom-stat-card h3,
        .custom-stat-card h4,
        .custom-stat-card p,
        .custom-stat-card i,
        .custom-stat-card .small {
            color: #0f5132 !important;
        }
    </style>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card custom-stat-card shadow-sm border-0 text-center p-3">
                <h4 class="mb-0 fw-bold" id="stat-total-owed">—</h4>
                <p class="small mb-0"><?= t('Total Owed') ?></p>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card custom-stat-card shadow-sm border-0 text-center p-3">
                <h4 class="mb-0 fw-bold" id="stat-overdue-count">—</h4>
                <p class="small mb-0"><?= t('Overdue') ?></p>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card custom-stat-card shadow-sm border-0 text-center p-3">
                <h4 class="mb-0 fw-bold" id="stat-open-count">—</h4>
                <p class="small mb-0"><?= t('Open Credit Sales') ?></p>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div id="creditAgingLoading" class="text-center py-5">
                <div class="spinner-border text-primary"></div>
            </div>
            <div class="table-responsive d-none" id="creditAgingTableWrap">
                <table class="table table-hover align-middle mb-0 w-100" id="creditAgingTable">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th style="width:50px;"><?= t('S/NO') ?></th>
                            <th><?= t('Customer') ?></th>
                            <th><?= t('Phone') ?></th>
                            <th class="text-end"><?= t('Owed') ?></th>
                            <th><?= t('Sale Date') ?></th>
                            <th><?= t('Due Date') ?></th>
                            <th><?= t('Status') ?></th>
                            <th class="text-center"><?= t('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody id="creditAgingBody"></tbody>
                </table>
            </div>
            <!-- Mobile card view (populated by pos-credit-aging.js's drawCallback) -->
            <div id="creditAgingCards" class="px-2 d-none"></div>
            <div class="text-center py-5 d-none" id="creditAgingEmpty">
                <i class="bi bi-emoji-smile" style="font-size:3rem;color:#ccc;"></i>
                <h5 class="mt-3 text-muted"><?= t('Nobody owes you anything right now') ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- View Details modal -->
<div class="modal fade" id="creditViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-eye me-1"></i> <?= t('Credit Sale Details') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="creditViewBody">
                <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Repay modal -->
<?php if ($can_edit): ?>
<div class="modal fade" id="creditRepayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="creditRepayForm">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-cash me-1"></i> <?= t('Record Repayment') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="sale_id" id="repay_sale_id">
                    <p class="mb-2"><?= t('Customer:') ?> <strong id="repay_customer_name"></strong></p>
                    <p class="mb-3"><?= t('Balance Due:') ?> <strong class="text-danger" id="repay_balance_due"></strong></p>
                    <div class="mb-3">
                        <label class="form-label small fw-bold"><?= t('Amount') ?> <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" id="repay_amount" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold"><?= t('Payment Method') ?></label>
                        <select class="form-select" name="payment_method" id="repay_method">
                            <option value="cash"><?= t('Cash') ?></option>
                            <option value="mobile_money"><?= t('Mobile Money') ?></option>
                            <option value="bank_transfer"><?= t('Bank Transfer') ?></option>
                            <option value="card"><?= t('Card') ?></option>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small fw-bold"><?= t('Reference') ?> <span class="text-muted">(<?= t('Optional') ?>)</span></label>
                        <input type="text" class="form-control" name="reference" id="repay_reference">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> <?= t('Record Payment') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit due date modal -->
<div class="modal fade" id="creditEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="creditEditForm">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> <?= t('Edit Due Date') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="sale_id" id="edit_sale_id">
                    <div class="mb-3">
                        <label class="form-label small fw-bold"><?= t('Due Date') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="due_date" id="edit_due_date" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small fw-bold"><?= t('Notes') ?></label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle"></i> <?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?= getUrl('assets/js/pos-credit-aging.js') ?>?v=<?= @filemtime(ROOT_DIR . '/assets/js/pos-credit-aging.js') ?>"></script>
<script>
$(function () {
    PosCreditAging.init({
        tableSel: '#creditAgingTable',
        listContainer: '#creditAgingTableWrap',
        cardContainer: '#creditAgingCards',
        loadingEl: '#creditAgingLoading',
        emptyEl: '#creditAgingEmpty',
        customerId: null,
        canEdit: <?= json_encode($can_edit) ?>,
        canDelete: <?= json_encode($can_delete) ?>,
        // The 3 summary cards above the table (#stat-total-owed etc.) had ids
        // reserved for them since this page was first built, but nothing
        // ever actually populated them — they always just showed the "—"
        // placeholder. Wire them to the exact same {totalOutstanding, count,
        // overdueCount} figures the table itself is built from, so they can
        // never disagree with what's listed below.
        onStats: function (s) {
            $('#stat-total-owed').text(parseFloat(s.totalOutstanding || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }));
            $('#stat-overdue-count').text(s.overdueCount);
            $('#stat-open-count').text(s.count);
        },
        urls: {
            list:   <?= json_encode(buildUrl('api/pos/get_credit_aging.php')) ?>,
            detail: <?= json_encode(buildUrl('api/pos/get_credit_sale_detail.php')) ?>,
            repay:  <?= json_encode(buildUrl('api/pos/receive_payment.php')) ?>,
            edit:   <?= json_encode(buildUrl('api/pos/update_credit_due_date.php')) ?>,
            void:   <?= json_encode(buildUrl('api/pos/void_sale.php')) ?>,
        },
        i18n: {
            overdueBy: <?= json_encode(t('Overdue by %d day(s)')) ?>,
            dueInDays: <?= json_encode(t('Due in %d day(s)')) ?>,
            dueToday: <?= json_encode(t('Due today')) ?>,
            noDueDate: <?= json_encode(t('No due date')) ?>,
            partial: <?= json_encode(t('Partial')) ?>,
            unpaid: <?= json_encode(t('Unpaid')) ?>,
            paidInFull: <?= json_encode(t('Paid in full')) ?>,
            confirmVoidTitle: <?= json_encode(t('Void this credit sale?')) ?>,
            confirmVoidText: <?= json_encode(t('This reverses the stock and cash. Cannot be undone.')) ?>,
            voidReasonPlaceholder: <?= json_encode(t('Reason for voiding (required)')) ?>,
            yesVoid: <?= json_encode(t('Yes, void it')) ?>,
            cancel: <?= json_encode(t('Cancel')) ?>,
            success: <?= json_encode(t('Success!')) ?>,
            error: <?= json_encode(t('Error')) ?>,
            view: <?= json_encode(t('View')) ?>,
            repay: <?= json_encode(t('Repay')) ?>,
            edit: <?= json_encode(t('Edit')) ?>,
            delete: <?= json_encode(t('Delete')) ?>,
            paymentHistory: <?= json_encode(t('Payment History')) ?>,
            noPaymentsYet: <?= json_encode(t('No payments recorded yet.')) ?>,
            balanceDue: <?= json_encode(t('Balance Due:')) ?>,
            saleAmount: <?= json_encode(t('Sale Amount:')) ?>,
            saleDate: <?= json_encode(t('Sale Date:')) ?>,
            dueDate: <?= json_encode(t('Due Date:')) ?>,
            amountHeader: <?= json_encode(t('Amount')) ?>,
            methodHeader: <?= json_encode(t('Method')) ?>,
            byHeader: <?= json_encode(t('By')) ?>,
        }
    });
});
</script>

<style>
/* ── Mobile custom card view — mirrors expenses.php's .expense-mobile-card ── */
@media (max-width: 768px) {
    .credit-aging-mobile-card {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 8px 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
}
</style>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
