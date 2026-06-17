<?php
/**
 * Payment Vouchers Management
 * Standards: .claude/ui-constants.md (§UI-1 colours, §UI-2 DataTable, §UI-3 Select2,
 *            §UI-4 SweetAlert2, §UI-5 gear dropdown, §UI-7 mobile cards).
 */
// scope-audit: skip — Phase G complete; AJAX shell (api/account/get_vouchers.php scoped); projects dropdown scoped inline below
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';
autoEnforcePermission('payment_vouchers');
includeHeader();
global $pdo;

$expense_accounts = [];
try { $expense_accounts = expenseAccounts($pdo); } catch (Exception $e) {}

$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

$projects = [];
if ($enable_projects) {
    try {
        if (isAdmin()) {
            $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $_pv_assigned = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
            if (!empty($_pv_assigned)) {
                $_pv_ph = implode(',', array_fill(0, count($_pv_assigned), '?'));
                $_pv_stmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_pv_ph) ORDER BY project_name");
                $_pv_stmt->execute($_pv_assigned);
                $projects = $_pv_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $e) {}
}

$currency = get_setting('currency', 'TZS');
?>

<div class="container-fluid mt-4" style="background:#fff;">

    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">
        <h2 style="color:#495057;font-weight:600;text-transform:uppercase;font-size:16pt;letter-spacing:2px;">Payment Voucher Report</h2>
        <p style="color:#6c757d;margin:0;font-size:10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin-top:10px;margin-bottom:20px;"></div>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Payment Vouchers</li>
        </ol>
    </nav>

    <!-- Page header §UI-7 sticky -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 d-print-none"
         style="position:sticky;top:0;z-index:1020;background:#fff;padding:8px 0;">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text text-primary me-2"></i>Payment Vouchers</h4>
            <p class="text-muted small mb-0">Create and manage payment vouchers for expenses.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <!-- View toggle §UI-7 -->
            <div class="btn-group d-none d-md-flex" style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
                <button type="button" id="btn-pv-table-view" class="btn btn-white px-3 border-0 fw-semibold" onclick="togglePVView('table')" style="background:#e9ecef;color:#000;">
                    <i class="bi bi-list-task text-primary"></i>
                </button>
                <div style="width:1px;background:#eee;height:24px;margin-top:6px;"></div>
                <button type="button" id="btn-pv-card-view" class="btn btn-white px-3 border-0" onclick="togglePVView('card')" style="background:#fff;color:#444;">
                    <i class="bi bi-grid-3x3-gap text-primary"></i>
                </button>
            </div>
            <button class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#voucherModal" onclick="resetVoucherForm()">
                <i class="bi bi-plus-circle me-1"></i> New Voucher
            </button>
        </div>
    </div>

    <!-- Stats cards §UI-1: #e7f0ff / #b6ccfe -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="d-flex align-items-center">
                    <div class="me-3 p-2 rounded" style="background:rgba(13,110,253,.12);"><i class="bi bi-check-circle text-primary fs-5"></i></div>
                    <div>
                        <div class="fw-bold fs-6 text-primary" id="stat_paid">...</div>
                        <div class="small text-muted text-uppercase fw-semibold">Total Paid</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="d-flex align-items-center">
                    <div class="me-3 p-2 rounded" style="background:rgba(13,110,253,.12);"><i class="bi bi-clock-history text-primary fs-5"></i></div>
                    <div>
                        <div class="fw-bold fs-6 text-primary" id="stat_pending">...</div>
                        <div class="small text-muted text-uppercase fw-semibold">Pending Approval</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="d-flex align-items-center">
                    <div class="me-3 p-2 rounded" style="background:rgba(13,110,253,.12);"><i class="bi bi-files text-primary fs-5"></i></div>
                    <div>
                        <div class="fw-bold fs-6 text-primary" id="stat_total">...</div>
                        <div class="small text-muted text-uppercase fw-semibold">Total Vouchers</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search card §UI-2 -->
    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-9">
                    <label class="form-label small fw-bold text-muted">Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0 ps-0" id="pvSearch" placeholder="Search Payee, PV No, Description...">
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-outline-secondary w-100" onclick="$('#pvSearch').val('');table.search('').draw();">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop table §UI-2 -->
    <div id="pvTableView" class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table id="vouchersTable" class="table table-hover align-middle mb-0 w-100">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:50px;">S/No</th>
                        <th>PV No</th>
                        <th>Date</th>
                        <?php if ($enable_projects): ?><th>Project</th><?php endif; ?>
                        <th>Pay To</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-3 d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Mobile card view §UI-7 -->
    <div id="pvCardView" class="row g-2 d-none"></div>
</div>

<!-- ── Voucher Modal ─────────────────────────────────────── -->
<div class="modal fade" id="voucherModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <!-- §UI-1 modal header always bg-primary text-white -->
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="voucherModalTitle">
                    <i class="bi bi-plus-circle me-2"></i>Create Payment Voucher
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="voucherForm" autocomplete="off">
                <input type="hidden" name="id" id="voucher_id" value="0">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" id="voucher_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Payment Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><?= htmlspecialchars($currency) ?></span>
                                <input type="number" class="form-control border-start-0 fw-bold" name="amount" id="voucher_amount" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Pay To (Payee) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="payee_name" id="voucher_payee" placeholder="e.g. Supplier Name, Staff Name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Amount in Words</label>
                            <input type="text" class="form-control" name="amount_in_words" id="voucher_words" placeholder="e.g. Fifty Thousand Only">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Payment Method</label>
                            <select class="form-select" name="payment_method" id="voucher_method">
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Reference (Cheque No, etc)</label>
                            <input type="text" class="form-control" name="reference" id="voucher_ref" placeholder="Ref No.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Expense Account</label>
                            <!-- §UI-3 DB-backed select → Select2 -->
                            <select class="form-select select2-static" name="expense_account_id" id="voucher_expense_account">
                                <option value="">Select expense account</option>
                                <?php foreach ($expense_accounts as $ea): ?>
                                <option value="<?= (int)$ea['account_id'] ?>"><?= htmlspecialchars($ea['account_code'] . ' — ' . $ea['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">The cost is booked here (P&amp;L) when the voucher is paid.</small>
                        </div>
                        <?php if ($enable_projects): ?>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Project</label>
                            <select class="form-select select2-static" name="project_id" id="voucher_project">
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"><?= htmlspecialchars($proj['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Description / Narration <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" id="voucher_desc" rows="3" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold" id="submitBtn">
                        <i class="bi bi-save me-1"></i> Save Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Pay Voucher Modal ─────────────────────────────────── -->
<div class="modal fade" id="payVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Pay Voucher</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="payVoucherForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="id" id="pay_voucher_id">
                    <input type="hidden" name="status" value="paid">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                    <div class="rounded p-3 mb-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Voucher</span><strong id="pay_voucher_no">—</strong>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Payee</span><strong id="pay_payee">—</strong>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <span class="text-muted">Amount</span>
                            <strong class="text-primary fs-5" id="pay_amount">—</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Paid From (Bank/Cash) <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="paid_from_account_id" id="pay_paid_from" required>
                            <option value="">Select cash/bank account…</option>
                            <?php foreach (cashBankAccounts($pdo) as $cb): ?>
                                <option value="<?= (int)$cb['account_id'] ?>"><?= htmlspecialchars(($cb['account_code'] ? $cb['account_code'] . ' — ' : '') . $cb['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">The cash/bank account the money is paid from (Cr on the ledger).</small>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" id="pay_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">Method</label>
                            <select class="form-select" name="payment_method" id="pay_method">
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Reference (Cheque/Txn No.)</label>
                            <input type="text" class="form-control" name="payment_reference" id="pay_reference" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Payment Proof (optional)</label>
                            <input type="file" class="form-control" name="attachment_file" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small text-muted">
                        <i class="bi bi-info-circle me-1"></i> Posts <strong>Dr Expense (or Accrued Expenses) / Cr Paid-From bank</strong> to the GL.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Pay Voucher</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Details Modal ─────────────────────────────────────── -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <div class="modal-header border-0 pb-0 pe-4 pt-4">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle me-3">
                        <i class="bi bi-file-earmark-text text-primary fs-4"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Payment Voucher Details</h5>
                        <p class="text-muted small mb-0" id="detail_voucher_no">#PV-00000</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-2">
                <div class="row g-4 mb-4">
                    <div class="col-md-7">
                        <div class="bg-light p-4 rounded-4 h-100">
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Status &amp; Method</label>
                            <div class="d-flex gap-2 mb-3">
                                <div id="detail_status_badge"></div>
                                <div id="detail_method_badge"></div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <label class="small text-muted text-uppercase fw-bold d-block mb-1">Voucher Date</label>
                                    <p class="fw-bold fs-6 mb-3" id="detail_date"></p>
                                </div>
                                <div class="col-6">
                                    <label class="small text-muted text-uppercase fw-bold d-block mb-1">Reference No.</label>
                                    <p class="fw-bold mb-3" id="detail_reference"></p>
                                </div>
                            </div>
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Payee (Pay To)</label>
                            <p class="fw-bold mb-3 fs-5 text-dark" id="detail_payee"></p>
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Expense Category</label>
                            <p class="fw-bold mb-0" id="detail_category"></p>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="p-4 rounded-4 text-center h-100 d-flex flex-column justify-content-center" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                            <label class="small text-primary text-uppercase fw-bold d-block mb-2">Total Amount</label>
                            <h2 class="fw-bold text-primary mb-2" id="detail_amount" style="white-space:nowrap;line-height:1.2;">...</h2>
                            <p id="detail_words" class="text-muted small mb-0 border-top pt-2 mt-2" style="word-break:break-word;"></p>
                            <?php if ($enable_projects): ?>
                            <div class="mt-3 text-start">
                                <label class="small text-muted text-uppercase fw-bold d-block mb-1">Project</label>
                                <p class="badge bg-white text-dark border w-100 py-2 mb-0" id="detail_project"></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-light rounded-4">
                    <label class="small text-muted text-uppercase fw-bold d-block mb-2">Description / Narration</label>
                    <p class="mb-0 fs-6 text-dark lh-base" id="detail_description" style="white-space:pre-wrap;font-style:italic;"></p>
                </div>

                <div class="mt-4 px-2">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="py-2 border-bottom mb-2"><small class="text-muted">Prepared By</small></div>
                            <p class="fw-bold small mb-0" id="detail_user">System</p>
                        </div>
                        <div class="col-4">
                            <div class="py-2 border-bottom mb-2"><small class="text-muted">Checked By</small></div>
                            <p class="small text-muted mb-0">________________</p>
                        </div>
                        <div class="col-4">
                            <div class="py-2 border-bottom mb-2"><small class="text-muted">Authorized By</small></div>
                            <p class="small text-muted mb-0">________________</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light py-3" style="border-bottom-left-radius:20px;border-bottom-right-radius:20px;">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary px-4 fw-bold" id="detail_print_btn">
                    <i class="bi bi-printer me-2"></i>Print Voucher
                </button>
            </div>
        </div>
    </div>
</div>

<!-- DataTable assets §UI-2 -->
<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">
<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>

<script>
const CURRENCY     = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
const enableProjects = <?= $enable_projects ? 'true' : 'false' ?>;

function money(v) {
    return CURRENCY + ' ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

// §UI-1 — blue-scale status badge
function pvBadge(s) {
    const map = {
        paid:      ['#052c65', '#fff'],
        approved:  ['#0d6efd', '#fff'],
        draft:     ['#e9ecef', '#495057'],
        cancelled: ['#6c757d', '#fff'],
    };
    const [bg, fg] = map[s] || ['#cfe2ff', '#084298'];
    return `<span style="background:${bg};color:${fg};font-size:.68rem;padding:.35em .6em;border-radius:6px;">${esc(s ? s.toUpperCase() : '')}</span>`;
}

// §UI-5 — gear-fill dropdown
function pvActions(r) {
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-gear-fill me-1"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
            <li><a class="dropdown-item py-2 rounded pv-act" href="#" data-action="view"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
            <li><a class="dropdown-item py-2 rounded pv-act" href="#" data-action="print"><i class="bi bi-printer text-primary me-2"></i>Print Voucher</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item py-2 rounded pv-act" href="#" data-action="edit"><i class="bi bi-pencil text-primary me-2"></i>Edit Voucher</a></li>
            <li><a class="dropdown-item py-2 rounded pv-act" href="#" data-action="status"><i class="bi bi-arrow-repeat text-primary me-2"></i>Change Status</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item py-2 rounded text-danger pv-act" href="#" data-action="delete"><i class="bi bi-trash text-danger me-2"></i>Delete</a></li>
        </ul>
    </div>`;
}

// §UI-2 — DataTable
const colDefs = [
    { data: null,              className: 'ps-3',       orderable: false,  render: (d,t,r,m) => m.row + 1 },
    { data: 'voucher_number',                           render: d => `<span class="custom-code">${esc(d)}</span>` },
    { data: 'vouch_date' },
    <?php if ($enable_projects): ?>
    { data: 'project_name',                             render: d => `<span class="badge bg-light text-dark border">${esc(d || '—')}</span>` },
    <?php endif; ?>
    { data: 'payee_name',                               render: d => esc(d) },
    { data: 'amount',          className: 'text-end fw-bold', render: d => money(d) },
    { data: 'payment_method',                           render: d => `<small class="text-muted text-uppercase">${esc((d||'').replace(/_/g,' '))}</small>` },
    { data: 'status',          className: 'text-center', render: d => pvBadge(d) },
    { data: null,              className: 'text-end pe-3 d-print-none', orderable: false, render: (d,t,r) => pvActions(r) },
];

const table = $('#vouchersTable').DataTable({
    responsive: false,
    scrollX:    false,
    pageLength: 25,
    order:      [[2, 'desc']],
    dom:        'rtipB',
    columns:    colDefs,
    buttons:    [{ extend: 'excelHtml5', className: 'd-none', title: 'Payment Vouchers', exportOptions: { columns: ':not(:last-child)' } }],
    language:   { emptyTable: 'No vouchers found.', zeroRecords: 'No matching vouchers.' },
    drawCallback: function () {
        renderCards(this.api().rows({ page: 'current' }).data().toArray());
    }
});

// §UI-7 — view toggle
function applyView() { /* honour saved/default choice on all screen sizes */ }
function togglePVView(v) {
    if (v === 'card') {
        $('#pvTableView').addClass('d-none');
        $('#pvCardView').removeClass('d-none');
        $('#btn-pv-card-view').css({ background: '#e9ecef', color: '#000', fontWeight: '600' });
        $('#btn-pv-table-view').css({ background: '#fff', color: '#444', fontWeight: 'normal' });
    } else {
        $('#pvCardView').addClass('d-none');
        $('#pvTableView').removeClass('d-none');
        $('#btn-pv-table-view').css({ background: '#e9ecef', color: '#000', fontWeight: '600' });
        $('#btn-pv-card-view').css({ background: '#fff', color: '#444', fontWeight: 'normal' });
    }
    localStorage.setItem('pvView', v);
}
$(window).on('resize', applyView);
applyView();

// §UI-7 — mobile card renderer
function renderCards(rows) {
    const $grid = $('#pvCardView');
    if (!rows.length) {
        $grid.html('<div class="col-12 text-center py-5 text-muted">No vouchers found.</div>');
        return;
    }
    let html = '';
    rows.forEach(r => {
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <span class="fw-bold small">${esc(r.payee_name)}</span>
                    ${pvBadge(r.status)}
                </div>
                <div class="small text-muted">${esc(r.voucher_number)} &middot; ${esc(r.vouch_date)}</div>
                <div class="fw-bold text-primary mt-1">${money(r.amount)}</div>
                <div class="small text-muted">${esc((r.payment_method||'').replace(/_/g,' '))}</div>
            </div>
            <div class="card-footer bg-white border-top p-0">
                <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                    <button class="btn btn-sm btn-outline-primary pv-act" data-action="view" data-id="${r.id}" style="flex:1;padding:3px 4px;font-size:.72rem;"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-sm btn-outline-secondary pv-act" data-action="print" data-id="${r.id}" style="flex:1;padding:3px 4px;font-size:.72rem;"><i class="bi bi-printer"></i></button>
                    <button class="btn btn-sm btn-outline-primary pv-act" data-action="edit" data-id="${r.id}" style="flex:1;padding:3px 4px;font-size:.72rem;"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-primary pv-act" data-action="status" data-id="${r.id}" style="flex:1;padding:3px 4px;font-size:.72rem;"><i class="bi bi-arrow-repeat"></i></button>
                    <button class="btn btn-sm btn-outline-danger pv-act" data-action="delete" data-id="${r.id}" style="flex:1;padding:3px 4px;font-size:.72rem;"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        </div></div>`;
    });
    $grid.html(html);
}

// Event delegation — table (DataTable manages tbody, so this handles re-drawn rows)
$('#vouchersTable').on('click', '.pv-act', function (e) {
    e.preventDefault();
    const row    = table.row($(this).closest('tr')).data();
    const action = $(this).data('action');
    if (row) pvDispatch(action, row);
});

// Event delegation — card grid
$('#pvCardView').on('click', '.pv-act', function (e) {
    e.preventDefault();
    const id     = $(this).data('id');
    const action = $(this).data('action');
    const row    = table.rows().data().toArray().find(r => r.id == id);
    if (row) pvDispatch(action, row);
});

function pvDispatch(action, row) {
    if (action === 'view')   viewVoucherDetails(row);
    if (action === 'edit')   editVoucher(row);
    if (action === 'status') openStatusManager(row);
    if (action === 'delete') deleteVoucher(row.id);
    if (action === 'print')  printVoucher(row.id);
}

// §UI-2 — AJAX renderer: clear + redraw DataTable rows, never innerHTML
function renderTable(vouchers) {
    table.clear().rows.add(vouchers || []).draw();
}

// Load all vouchers into DataTable
function loadVouchers() {
    // §UI-4 loading spinner
    Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.getJSON('<?= buildUrl('api/account/get_vouchers.php') ?>', { page: 1, limit: 9999 }, function (res) {
        Swal.close();
        if (res.success) {
            renderTable(res.vouchers);
            if (res.stats) updateStats(res.stats);
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load vouchers.' });
        }
    }).fail(function () {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
    });
}

// Search wired to DataTable
let pvSearchTimer;
$('#pvSearch').on('keyup', function () {
    clearTimeout(pvSearchTimer);
    const val = this.value;
    pvSearchTimer = setTimeout(() => table.search(val).draw(), 400);
});

function updateStats(s) {
    $('#stat_paid').text(money(s.total_paid));
    $('#stat_pending').text(s.pending_approval);
    $('#stat_total').text(s.total_vouchers);
}

function resetVoucherForm() {
    document.getElementById('voucherForm').reset();
    document.getElementById('voucher_id').value = 0;
    document.getElementById('voucher_date').value = new Date().toISOString().split('T')[0];
    $('#voucherModalTitle').html('<i class="bi bi-plus-circle me-2"></i>Create Payment Voucher');
    $('#submitBtn').html('<i class="bi bi-save me-1"></i> Save Voucher');
}

function editVoucher(data) {
    $('#voucher_id').val(data.id);
    $('#voucher_date').val(data.vouch_date);
    $('#voucher_amount').val(data.amount);
    $('#voucher_payee').val(data.payee_name);
    $('#voucher_words').val(data.amount_in_words || '');
    $('#voucher_method').val(data.payment_method);
    $('#voucher_ref').val(data.reference_number || '');
    $('#voucher_expense_account').val(data.expense_account_id || '').trigger('change.select2');
    if (enableProjects && $('#voucher_project').length) $('#voucher_project').val(data.project_id || '').trigger('change.select2');
    $('#voucher_desc').val(data.description || '');
    $('#voucherModalTitle').html('<i class="bi bi-pencil me-2"></i>Edit Voucher ' + esc(data.voucher_number));
    $('#submitBtn').html('<i class="bi bi-check-circle me-1"></i> Update Voucher');
    new bootstrap.Modal(document.getElementById('voucherModal')).show();
}

// §UI-4 — form submit
$('#voucherForm').on('submit', function (e) {
    e.preventDefault();
    const btn  = $(this).find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    $.ajax({
        url: '<?= buildUrl('api/account/save_voucher.php') ?>',
        type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
        success: function (data) {
            if (data.success) {
                // §UI-4: hide modal + reload BEFORE Swal
                bootstrap.Modal.getInstance(document.getElementById('voucherModal')).hide();
                loadVouchers();
                Swal.fire({ icon: 'success', title: 'Saved!', text: data.message, timer: 1800, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                btn.prop('disabled', false).html(orig);
            }
        },
        error: function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' });
            btn.prop('disabled', false).html(orig);
        }
    });
});

function deleteVoucher(id) {
    // §UI-4 delete confirmation
    Swal.fire({
        title: 'Delete Voucher?', text: 'This cannot be undone.', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/account/delete_voucher.php') ?>', { id }, function (data) {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', timer: 1500, showConfirmButton: false });
                loadVouchers();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        }, 'json');
    });
}

function viewVoucherDetails(data) {
    const dateStr = new Date(data.vouch_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    $('#detail_voucher_no').text(data.voucher_number);
    $('#detail_date').text(dateStr);
    $('#detail_status_badge').html(pvBadge(data.status));
    $('#detail_method_badge').html(`<span class="badge bg-light text-dark border text-uppercase px-3">${esc((data.payment_method||'').replace(/_/g,' '))}</span>`);
    $('#detail_payee').text(data.payee_name);
    $('#detail_category').text(data.expense_account_name || data.category_name || 'Uncategorized');
    $('#detail_amount').text(money(data.amount));
    $('#detail_words').text(data.amount_in_words ? 'In Words: ' + data.amount_in_words : '');
    $('#detail_reference').text(data.reference_number || 'None');
    $('#detail_description').text(data.description || 'No description provided');
    $('#detail_user').text(data.prepared_by_name || data.username || 'System Admin');
    if (enableProjects && $('#detail_project').length) $('#detail_project').text(data.project_name || 'N/A');
    $('#detail_print_btn').off('click').on('click', () => printVoucher(data.id));
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
}

function printVoucher(id) {
    window.open(`<?= getUrl('payment_voucher_print') ?>?id=${id}`, '_blank').focus();
}

function submitVoucherStatus(id, status) {
    $.post('<?= buildUrl('api/account/update_voucher_status.php') ?>',
        { id, status, _csrf: CSRF_TOKEN },
        function (data) {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Updated!', timer: 1500, showConfirmButton: false });
                loadVouchers();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        }, 'json');
}

function openStatusManager(v) {
    Swal.fire({
        title: 'Change Voucher Status',
        input: 'select',
        inputOptions: { draft: 'Draft', approved: 'Approved', paid: 'Pay', cancelled: 'Cancelled' },
        inputValue: v.status,
        showCancelButton: true,
        confirmButtonText: 'Continue',
        confirmButtonColor: '#0d6efd'
    }).then(result => {
        if (!result.isConfirmed) return;
        if (result.value === 'paid') { openPayVoucher(v); return; }
        submitVoucherStatus(v.id, result.value);
    });
}

function openPayVoucher(v) {
    $('#pay_voucher_id').val(v.id);
    $('#pay_voucher_no').text(v.voucher_number || ('#' + v.id));
    $('#pay_payee').text(v.payee_name || '—');
    $('#pay_amount').text(money(v.amount));
    $('#pay_reference').val(v.reference_number || '');
    $('#pay_date').val(new Date().toISOString().split('T')[0]);
    if (v.payment_method) $('#pay_method').val(v.payment_method);
    $('#pay_paid_from').val(v.paid_from_account_id || '').trigger('change.select2');
    new bootstrap.Modal(document.getElementById('payVoucherModal')).show();
}

// §UI-4 — Pay form submit
$('#payVoucherForm').on('submit', function (e) {
    e.preventDefault();
    if (!$('#pay_paid_from').val()) { Swal.fire({ icon: 'warning', title: 'Required', text: 'Choose the Paid From account.' }); return; }
    const btn  = $(this).find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Paying…');
    $.ajax({
        url: '<?= buildUrl('api/account/update_voucher_status.php') ?>',
        type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
        success: function (data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('payVoucherModal')).hide();
                loadVouchers();
                Swal.fire({ icon: 'success', title: 'Voucher Paid', timer: 1600, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Could not pay the voucher.' });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
});

// §UI-3 — Select2 in voucher modal
$('#voucherModal').on('shown.bs.modal', function () {
    $(this).find('.select2-static').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#voucherModal'), allowClear: true, width: '100%',
                placeholder: $(this).find('option[value=""]').text() || 'Select...' });
        }
    });
});

// §UI-3 — Select2 in pay modal
$('#payVoucherModal').on('shown.bs.modal', function () {
    const $s = $('#pay_paid_from');
    if (!$s.hasClass('select2-hidden-accessible')) {
        $s.select2({ theme: 'bootstrap-5', dropdownParent: $('#payVoucherModal'), placeholder: 'Select cash/bank account…', allowClear: true, width: '100%' });
    }
});

$(document).ready(function () {
    if (typeof logReportAction === 'function') logReportAction('Viewed Payment Vouchers', 'User viewed the payment vouchers list');

    const saved = localStorage.getItem('pvView') || 'table';
    togglePVView(saved);

    loadVouchers();

    // Restore URL param project shortcut
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('project')) {
        resetVoucherForm();
        const ps = document.querySelector('select[name="project_id"]');
        if (ps) { ps.value = urlParams.get('project'); new bootstrap.Modal(document.getElementById('voucherModal')).show(); }
    }
});
</script>

<style>
#vouchersTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
.custom-code { color:#084298; background:#e7f0ff; padding:2px 6px; border-radius:6px; font-weight:700; font-size:.8rem; }
@media print {
    .d-print-none { display:none !important; }
    #vouchersTable { width:100% !important; }
}
</style>

<?php includeFooter(); ob_end_flush(); ?>
