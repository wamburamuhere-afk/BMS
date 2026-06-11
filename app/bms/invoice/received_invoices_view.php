<?php
$page_title = 'Invoice Details';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('received_invoices');
logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[Received Invoice Detail] Page viewed');
includeHeader();

$can_edit   = canEdit('received_invoices');
$can_delete = canDelete('received_invoices');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Invalid invoice ID.</div></div>";
    includeFooter();
    exit;
}

// Phase C — block viewing received invoices on projects not in user scope (HTML-safe)
assertScopeForRecordHtml('supplier_invoices', 'id', $id);

try {
    $stmt = $pdo->prepare("
        SELECT si.*,
               COALESCE(s.supplier_name, sc.supplier_name)  AS party_name,
               po.order_number                               AS po_number,
               p.project_name,
               CONCAT(u.first_name, ' ', u.last_name)        AS recorded_by_name,
               CONCAT(pu.first_name, ' ', pu.last_name)       AS payment_recorded_by_name
        FROM supplier_invoices si
        LEFT JOIN suppliers s        ON si.invoice_type = 'supplier'       AND s.supplier_id  = si.supplier_id
        LEFT JOIN sub_contractors sc ON si.invoice_type = 'sub_contractor' AND sc.supplier_id = si.supplier_id
        LEFT JOIN purchase_orders po ON si.po_id        = po.purchase_order_id
        LEFT JOIN projects p         ON si.project_id   = p.project_id
        LEFT JOIN users u            ON si.recorded_by  = u.user_id
        LEFT JOIN users pu           ON si.payment_recorded_by = pu.user_id
        WHERE si.id = ? AND si.status != 'deleted'
    ");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // payment columns not yet added (migration pending) — fallback without payment join
    $stmt = $pdo->prepare("
        SELECT si.*,
               COALESCE(s.supplier_name, sc.supplier_name)  AS party_name,
               po.order_number                               AS po_number,
               p.project_name,
               CONCAT(u.first_name, ' ', u.last_name)        AS recorded_by_name
        FROM supplier_invoices si
        LEFT JOIN suppliers s        ON si.invoice_type = 'supplier'       AND s.supplier_id  = si.supplier_id
        LEFT JOIN sub_contractors sc ON si.invoice_type = 'sub_contractor' AND sc.supplier_id = si.supplier_id
        LEFT JOIN purchase_orders po ON si.po_id        = po.purchase_order_id
        LEFT JOIN projects p         ON si.project_id   = p.project_id
        LEFT JOIN users u            ON si.recorded_by  = u.user_id
        WHERE si.id = ? AND si.status != 'deleted'
    ");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$inv) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Invoice not found or has been deleted.</div></div>";
    includeFooter();
    exit;
}

// Line items (supplier invoices). Empty for old records / sub-contractors —
// the view falls back to the stored amount.
$inv_items = [];
try {
    $iStmt = $pdo->prepare("SELECT item_name, quantity, unit, unit_price, tax_rate, tax_amount, line_total
                              FROM supplier_invoice_items WHERE invoice_id = ? ORDER BY item_id");
    $iStmt->execute([$id]);
    $inv_items = $iStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $inv_items = []; }

// Workflow signatures (Created / Reviewed / Approved By) for the print page —
// same canonical block as invoice_view.php / print_delivery_note.php.
require_once ROOT_DIR . '/core/workflow.php';
$wf_sigs = getWorkflowSignatures($pdo, 'supplier_invoice', (int)$id);
$wf = [
    '__include_css'      => true,
    'created_by_name'    => $wf_sigs['created']['user_name']   ?: ($inv['recorded_by_name'] ?? ''),
    'created_by_role'    => $wf_sigs['created']['user_role']   ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']    ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']   ?? null,
    'reviewed_by_name'   => $wf_sigs['reviewed']['user_name']  ?? '',
    'reviewed_by_role'   => $wf_sigs['reviewed']['user_role']  ?? '',
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']   ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at']  ?? null,
    'approved_by_name'   => $wf_sigs['approved']['user_name']  ?? '',
    'approved_by_role'   => $wf_sigs['approved']['user_role']  ?? '',
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']   ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at']  ?? null,
];

// Status badge map
$statusMap = [
    'draft'     => ['bg' => '#e9ecef', 'color' => '#495057',  'label' => 'Draft'],
    'submitted' => ['bg' => '#cfe2ff', 'color' => '#084298',  'label' => 'Submitted'],
    'approved'  => ['bg' => '#0d6efd', 'color' => '#fff',     'label' => 'Approved'],
    'partial'   => ['bg' => '#fd7e14', 'color' => '#fff',     'label' => 'Partial'],
    'paid'      => ['bg' => '#052c65', 'color' => '#fff',     'label' => 'Paid'],
];
$s = $statusMap[$inv['status']] ?? ['bg' => '#e2e3e5', 'color' => '#41464b', 'label' => ucfirst($inv['status'])];

// Payment history for this invoice
$inv_payments = [];
try {
    $pmtStmt = $pdo->prepare("
        SELECT sip.*, a.account_name,
               CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
        FROM supplier_invoice_payments sip
        LEFT JOIN accounts a ON sip.payment_account_id = a.account_id
        LEFT JOIN users u    ON sip.recorded_by = u.user_id
        WHERE sip.invoice_id = ?
        ORDER BY sip.payment_date ASC, sip.id ASC
    ");
    $pmtStmt->execute([$id]);
    $inv_payments = $pmtStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $inv_payments = []; }

$amount_paid  = (float)($inv['amount_paid'] ?? 0);
$inv_total    = (float)$inv['amount'];
$amount_due   = max(0, $inv_total - $amount_paid);
$paid_pct     = $inv_total > 0 ? min(100, round($amount_paid / $inv_total * 100)) : 0;
?>

<style>
.riv-header   { background: #fff; border-bottom: 3px solid #0d6efd; padding: 24px 28px 18px; }
.riv-label    { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
.riv-value    { font-size: 0.97rem; font-weight: 600; color: #212529; }
.riv-section  { background: #fff; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,.07); padding: 22px 24px; margin-bottom: 20px; }
.riv-section-title { font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #0d6efd; margin-bottom: 14px; border-bottom: 1px solid #e9ecef; padding-bottom: 7px; }
.riv-amount   { font-size: 1.6rem; font-weight: 700; color: #0d6efd; }
.status-pill  { display: inline-block; padding: 4px 14px; border-radius: 30px; font-size: 0.8rem; font-weight: 600; }
.type-pill    { display: inline-block; padding: 4px 12px; border-radius: 30px; font-size: 0.78rem; font-weight: 600; }
.type-supplier { background: #cfe2ff; color: #084298; }
.type-sc       { background: #cfe2ff; color: #084298; }
@media (max-width: 767px) {
    .page-sticky-header { position: sticky; top: 0; z-index: 1020; background: #fff; }
    .riv-header { padding: 16px; }
    .riv-section { padding: 14px; }
    .riv-amount { font-size: 1.3rem; }
}
</style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>

<div class="container-fluid mt-3 mb-5 px-3 px-md-4">

    <!-- Sticky page header -->
    <div class="page-sticky-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 py-2 d-print-none">
        <div class="d-flex align-items-center gap-2">
            <a href="<?= getUrl('received_invoices') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <h5 class="mb-0 fw-bold"><i class="bi bi-receipt me-2 text-primary"></i>Invoice Details</h5>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($can_edit): ?>
            <a href="<?= getUrl('received_invoices') ?>?edit=<?= $id ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <?php endif; ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Top card: ref + status + type + amount -->
    <div class="riv-section riv-header mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <div class="riv-label">Invoice Reference</div>
                <div class="fw-bold" style="font-size:1.35rem;color:#0d6efd;"><?= safe_output($inv['invoice_ref']) ?></div>
                <div class="d-flex gap-2 mt-2 flex-wrap">
                    <span class="status-pill" style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>">
                        <?= $s['label'] ?>
                    </span>
                    <?php if ($inv['invoice_type'] === 'supplier'): ?>
                    <span class="type-pill type-supplier"><i class="bi bi-building me-1"></i>Supplier</span>
                    <?php else: ?>
                    <span class="type-pill type-sc"><i class="bi bi-people me-1"></i>Sub-Contractor</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <div class="riv-label">Amount</div>
                <div class="riv-amount">TZS <?= number_format($inv_total, 2) ?></div>
                <?php if (in_array($inv['status'], ['partial', 'paid'])): ?>
                <div class="mt-2" style="min-width:160px">
                    <div class="progress" style="height:6px;border-radius:4px;">
                        <div class="progress-bar <?= $inv['status'] === 'paid' ? 'bg-success' : 'bg-warning' ?>"
                             style="width:<?= $paid_pct ?>%"></div>
                    </div>
                    <div class="small mt-1 text-muted">
                        Paid: <strong>TZS <?= number_format($amount_paid, 2) ?></strong>
                        <?php if ($amount_due > 0): ?>
                        &nbsp;&bull;&nbsp; Due: <strong class="text-danger">TZS <?= number_format($amount_due, 2) ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- Left column -->
        <div class="col-md-7">

            <!-- Core details -->
            <div class="riv-section">
                <div class="riv-section-title"><i class="bi bi-info-circle me-1"></i>Invoice Information</div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="riv-label">From</div>
                        <div class="riv-value"><?= safe_output($inv['party_name'] ?? 'N/A') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="riv-label">Date Raised</div>
                        <div class="riv-value"><?= safe_output($inv['date_raised']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="riv-label">Date Recorded</div>
                        <div class="riv-value"><?= safe_output($inv['date_recorded']) ?></div>
                    </div>
                    <?php if ($inv['invoice_type'] === 'supplier' && !empty($inv['po_number'])): ?>
                    <div class="col-sm-6">
                        <div class="riv-label">PO Reference</div>
                        <div class="riv-value"><?= safe_output($inv['po_number']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($inv['project_name'])): ?>
                    <div class="col-sm-6">
                        <div class="riv-label">Project</div>
                        <div class="riv-value"><?= safe_output($inv['project_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($inv['invoice_type'] === 'sub_contractor' && (!empty($inv['sc_invoice_basis']) || !empty($inv['sc_basis_ref']))): ?>
                    <div class="col-sm-6">
                        <div class="riv-label">Invoice Basis</div>
                        <div class="riv-value"><?= safe_output($inv['sc_invoice_basis'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="riv-label">Basis Reference</div>
                        <div class="riv-value"><?= safe_output($inv['sc_basis_ref'] ?? '—') ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <div class="riv-label">Recorded By</div>
                        <div class="riv-value"><?= safe_output($inv['recorded_by_name'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="riv-label">Created At</div>
                        <div class="riv-value"><?= safe_output($inv['created_at']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Line items (supplier invoices with items) -->
            <?php if (!empty($inv_items)): ?>
            <div class="riv-section">
                <div class="riv-section-title"><i class="bi bi-list-ul me-1"></i>Items</div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product/Item</th>
                                <th class="text-end">Quantity</th>
                                <th>Unit</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Tax</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $riv_sub = 0; $riv_vat = 0; foreach ($inv_items as $it):
                                $lt = (float)$it['quantity'] * (float)$it['unit_price'];
                                $riv_sub += $lt; $riv_vat += (float)$it['tax_amount']; ?>
                            <tr>
                                <td><?= safe_output($it['item_name']) ?></td>
                                <td class="text-end"><?= number_format((float)$it['quantity'], 2) ?></td>
                                <td><?= safe_output($it['unit'] ?? '') ?></td>
                                <td class="text-end"><?= number_format((float)$it['unit_price'], 2) ?></td>
                                <td class="text-end"><?= number_format((float)$it['tax_rate'], 0) ?>%</td>
                                <td class="text-end"><?= number_format($lt, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="5" class="text-end fw-semibold">Subtotal</td><td class="text-end fw-semibold"><?= number_format($riv_sub, 2) ?></td></tr>
                            <tr><td colspan="5" class="text-end">VAT</td><td class="text-end"><?= number_format($riv_vat, 2) ?></td></tr>
                            <tr class="table-primary"><td colspan="5" class="text-end fw-bold">Grand Total</td><td class="text-end fw-bold"><?= number_format($riv_sub + $riv_vat, 2) ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notes -->
            <?php if (!empty($inv['notes'])): ?>
            <div class="riv-section">
                <div class="riv-section-title"><i class="bi bi-chat-left-text me-1"></i>Notes</div>
                <p class="mb-0" style="white-space:pre-wrap;font-size:0.95rem;"><?= safe_output($inv['notes']) ?></p>
            </div>
            <?php endif; ?>

            <!-- Payment history -->
            <?php if (!empty($inv_payments) || in_array($inv['status'], ['partial', 'paid'])): ?>
            <div class="riv-section">
                <div class="riv-section-title"><i class="bi bi-cash-coin me-1"></i>Payment History</div>

                <?php if (!empty($inv_payments)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-2">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th class="text-end">Amount</th>
                                <th>Method</th>
                                <th>Account</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inv_payments as $i => $pmt): ?>
                            <tr>
                                <td class="text-muted small"><?= $i + 1 ?></td>
                                <td><?= safe_output($pmt['payment_date']) ?></td>
                                <td class="text-end fw-semibold">TZS <?= number_format((float)$pmt['amount'], 2) ?></td>
                                <td><?= safe_output($pmt['payment_method']) ?></td>
                                <td class="small"><?= safe_output($pmt['account_name'] ?? '—') ?></td>
                                <td class="small text-muted"><?= safe_output($pmt['reference'] ?? '—') ?></td>
                                <td class="small"><?= safe_output($pmt['recorded_by_name'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-primary fw-bold">
                                <td colspan="2" class="text-end">Total Paid</td>
                                <td class="text-end">TZS <?= number_format($amount_paid, 2) ?></td>
                                <td colspan="4"></td>
                            </tr>
                            <?php if ($amount_due > 0): ?>
                            <tr class="table-warning">
                                <td colspan="2" class="text-end">Remaining</td>
                                <td class="text-end text-danger fw-bold">TZS <?= number_format($amount_due, 2) ?></td>
                                <td colspan="4"></td>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
                <?php elseif ($inv['status'] === 'paid' && !empty($inv['payment_date'])): ?>
                <!-- Legacy single-payment record (pre-partial-payments feature) -->
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="riv-label">Payment Date</div>
                        <div class="riv-value"><?= safe_output($inv['payment_date']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="riv-label">Payment Method</div>
                        <div class="riv-value"><?= safe_output($inv['payment_method'] ?? '—') ?></div>
                    </div>
                    <?php if (!empty($inv['payment_ref'])): ?>
                    <div class="col-sm-6">
                        <div class="riv-label">Payment Reference</div>
                        <div class="riv-value"><?= safe_output($inv['payment_ref']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($inv['payment_recorded_by_name'])): ?>
                    <div class="col-sm-6">
                        <div class="riv-label">Recorded By</div>
                        <div class="riv-value"><?= safe_output($inv['payment_recorded_by_name']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-0">No payments recorded yet.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Workflow signatures — only on print (Created / Reviewed / Approved By) -->
            <div class="d-none d-print-block">
                <?php require ROOT_DIR . '/includes/workflow_signature_row.php'; ?>
            </div>

        </div>

        <!-- Right column -->
        <div class="col-md-5 d-print-none">

            <!-- Attachment -->
            <div class="riv-section">
                <div class="riv-section-title"><i class="bi bi-paperclip me-1"></i>Attachment</div>
                <?php if (!empty($inv['attachment'])): ?>
                    <div class="d-flex gap-2">
                        <a href="<?= getUrl($inv['attachment']) ?>" target="_blank"
                           class="btn btn-outline-primary btn-sm flex-fill">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Open
                        </a>
                        <a href="<?= getUrl($inv['attachment']) ?>" download
                           class="btn btn-outline-secondary btn-sm flex-fill">
                            <i class="bi bi-download me-1"></i> Download
                        </a>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-file-earmark-x fs-2 d-block mb-1"></i>
                        <small>No attachment</small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick actions -->
            <div class="riv-section">
                <div class="riv-section-title"><i class="bi bi-lightning me-1"></i>Actions</div>
                <div class="d-grid gap-2">
                    <?php if ($can_edit): ?>
                    <a href="<?= getUrl('received_invoices') ?>?edit=<?= $id ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i> Edit Invoice
                    </a>
                    <?php endif; ?>
                    <a href="<?= getUrl('received_invoices') ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-list-ul me-1"></i> All Invoices
                    </a>
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
<?php includeFooter(); ?>
