<?php
/**
 * Payment Vouchers Management
 */
// scope-audit: skip — Phase G complete; AJAX shell (api/account/get_vouchers.php scoped); projects dropdown scoped inline below
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';
includeHeader();
autoEnforcePermission('payment_vouchers');

// Expense accounts — the real "category" a voucher is booked to (Dr expense /
// Cr paid-from on payment), matching petty cash and the expenses module.
$expense_accounts = [];
try { $expense_accounts = expenseAccounts($pdo); } catch (Exception $e) {}

// Check projects setting
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
?>

<div class="payment-vouchers-dashboard p-4 p-md-5" style="background: #ffffff; min-height: 100vh;">
    <!-- Print Header --><!-- Print Header -->
<div class="d-none d-print-block text-center mb-4" id="printHeader">

   
    <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">
        Payment Voucher Report
    </h2>

    <p style="color: #6c757d; margin: 0; font-size: 10pt;">
        Generated on: <?= date('F j, Y, g:i a') ?>
    </p>

    <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>

</div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Payment Vouchers</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1 text-primary"><i class="bi bi-file-earmark-text me-2"></i>Payment Vouchers</h2>
            <p class="text-muted mb-0">Create and manage payment vouchers for expenses</p>
        </div>
        <div>
            <button class="btn btn-primary btn-sm shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#voucherModal" onclick="resetVoucherForm()">
                <i class="bi bi-plus-circle me-1"></i> New Voucher
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat_paid">...</h4>
                        <small class="text-uppercase small fw-bold">Total Paid</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat_pending">...</h4>
                        <small class="text-uppercase small fw-bold">Pending Approval</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon"><i class="bi bi-files"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat_total">...</h4>
                        <small class="text-uppercase small fw-bold">Total Vouchers</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Card -->
    <div class="card mb-4 border-0 shadow-sm d-print-none">
        <div class="card-header bg-light py-3 border-bottom">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label small fw-bold text-muted text-uppercase">General Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0 ps-0" id="searchInput" placeholder="Search Payee, PV No, Description...">
                    </div>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4 w-100 fw-bold">
                        <i class="bi bi-filter me-1"></i> Apply Filter
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4 w-100 fw-bold text-dark" onclick="clearFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printVouchers()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" id="filter_limit" style="width: 60px; box-shadow: none; background: transparent;" onchange="loadVouchers(1)">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <!-- View toggle — desktop only -->
            <div class="btn-group shadow-sm bg-white d-none d-md-flex" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" id="btn-pv-table-view" class="btn btn-white fw-medium px-3 border-0" onclick="togglePVView('table')" style="background: #e9ecef; color: #000; font-weight:600;">
                    <i class="bi bi-list-task text-primary"></i> <span class="d-none d-xl-inline">List</span>
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" id="btn-pv-card-view" class="btn btn-white fw-medium px-3 border-0" onclick="togglePVView('card')" style="background: #fff; color: #444;">
                    <i class="bi bi-grid-3x3-gap text-primary"></i> <span class="d-none d-xl-inline">Card</span>
                </button>
            </div>
        </div>
        <div>
             <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill" id="total_records_badge">
                <i class="bi bi-check-circle-fill me-1"></i> 0 vouchers
            </span>
        </div>
    </div>

    <!-- Vouchers Table Card -->
    <div id="pvTableView" class="card border-0 shadow-sm rounded-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="vouchersTable">
                <thead class="bg-light text-uppercase small fw-bold">
                    <tr>
                        <th style="width:70px;" class="ps-4">S/NO</th>
                        <th class="ps-4">PV No</th>
                        <th>Date</th>
                        <?php if ($enable_projects): ?><th>Project</th><?php endif; ?>
                        <th>Pay To</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th class="text-end pe-4 d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody id="vouchersTableBody">
                    <tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white border-top-0 py-3 d-print-none">
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-end mb-0" id="pagination"></ul>
            </nav>
        </div>
    </div>

    <!-- Card View (populated by renderTable JS) -->
    <div id="pvCardView" style="display:none;">
        <div class="row g-3" id="pvCardGrid">
            <div class="col-12 text-center py-5 text-muted">Loading...</div>
        </div>
    </div>
</div>

<!-- Voucher Modal -->
<div class="modal fade" id="voucherModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="voucherModalTitle">
                    <i class="bi bi-plus-circle me-2"></i>Create Payment Voucher
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="voucherForm">
                <input type="hidden" name="id" id="voucher_id" value="0">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" id="voucher_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Payment Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0">TSh</span>
                                <input type="number" class="form-control border-start-0 fw-bold" name="amount" id="voucher_amount" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted text-uppercase">Pay To (Payee) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="payee_name" id="voucher_payee" placeholder="e.g. Supplier Name, Staff Name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted text-uppercase">Amount in Words</label>
                            <input type="text" class="form-control" name="amount_in_words" id="voucher_words" placeholder="e.g. Fifty Thousand Only">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Payment Method</label>
                            <select class="form-select" name="payment_method" id="voucher_method">
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Reference (Cheque No, etc)</label>
                            <input type="text" class="form-control" name="reference" id="voucher_ref" placeholder="Ref No.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Expense Account</label>
                            <select class="form-select select2-static" name="expense_account_id" id="voucher_expense_account">
                                <option value="">Select expense account</option>
                                <?php foreach ($expense_accounts as $ea): ?>
                                <option value="<?= (int)$ea['account_id'] ?>"><?= htmlspecialchars($ea['account_code'] . ' — ' . $ea['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">The cost is booked here (Profit &amp; Loss) when the voucher is paid.</small>
                        </div>
                        <?php if ($enable_projects): ?>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Project</label>
                            <select class="form-select select2-static" name="project_id" id="voucher_project">
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>"><?= htmlspecialchars($proj['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted text-uppercase">Description / Narration</label>
                            <textarea class="form-control" name="description" id="voucher_desc" rows="3" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light py-3">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="submitBtn">
                        <i class="bi bi-save me-1"></i> Save Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Details Modal -->
<!-- PAY VOUCHER MODAL — opens from "Change Status → Pay"; records the cash-out -->
<div class="modal fade" id="payVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Pay Voucher</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="payVoucherForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="id" id="pay_voucher_id">
                    <input type="hidden" name="status" value="paid">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                    <!-- Voucher summary -->
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
                        <label class="form-label fw-bold small text-muted text-uppercase">Paid From (Bank/Cash) <span class="text-danger">*</span></label>
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
                            <label class="form-label fw-bold small text-muted text-uppercase">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" id="pay_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Method</label>
                            <select class="form-select" name="payment_method" id="pay_method">
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted text-uppercase">Reference (Cheque/Txn No.)</label>
                            <input type="text" class="form-control" name="payment_reference" id="pay_reference" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted text-uppercase">Payment Proof (optional)</label>
                            <input type="file" class="form-control" name="attachment_file" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small text-muted">
                        <i class="bi bi-info-circle me-1"></i> Posts <strong>Dr Expense (or Accrued Expenses) / Cr Paid-From bank</strong> to the General Ledger.
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

<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
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
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Status & Method</label>
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
                        <div class="bg-primary bg-opacity-10 p-4 rounded-4 text-center h-100 d-flex flex-column justify-content-center border border-primary border-opacity-10" style="max-width: 100%; container-type: inline-size;">
                            <label class="small text-primary text-uppercase fw-bold d-block mb-2">Total Amount</label>
                            <h2 class="fw-bold text-primary mb-2" id="detail_amount" style="white-space: nowrap; line-height: 1.2; font-size: clamp(1rem, 8cqw, 2rem);" title="">...</h2>
                            <p id="detail_words" class="text-muted small italic mb-0 border-top pt-2 mt-2" style="word-break: break-word;"></p>
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
                    <p class="mb-0 fs-6 text-dark lh-base" id="detail_description" style="white-space: pre-wrap; font-style: italic;"></p>
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
            <div class="modal-footer border-0 bg-light py-3" style="border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
                <button type="button" class="btn btn-outline-secondary px-4 fw-semibold border-0" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" id="detail_print_btn">
                    <i class="bi bi-printer me-2"></i>Print Voucher
                </button>
            </div>
        </div>
    </div>
</div>


<script>
    let currentPage = 1;
    let searchQuery = '';
    const enableProjects = <?= $enable_projects ?>;

    function togglePVView(viewType) {
        const isMobile = window.innerWidth <= 767;
        if (isMobile) viewType = 'card';
        if (viewType === 'card') {
            document.getElementById('pvTableView').style.display = 'none';
            document.getElementById('pvCardView').style.display = '';
            document.getElementById('btn-pv-table-view').style.cssText = 'background:#fff;color:#444;font-weight:normal;';
            document.getElementById('btn-pv-card-view').style.cssText = 'background:#e9ecef;color:#000;font-weight:600;';
        } else {
            document.getElementById('pvCardView').style.display = 'none';
            document.getElementById('pvTableView').style.display = '';
            document.getElementById('btn-pv-table-view').style.cssText = 'background:#e9ecef;color:#000;font-weight:600;';
            document.getElementById('btn-pv-card-view').style.cssText = 'background:#fff;color:#444;font-weight:normal;';
        }
        if (!isMobile) localStorage.setItem('pvView', viewType);
    }

    document.addEventListener('DOMContentLoaded', function() {
        logReportAction('Viewed Payment Vouchers', 'User viewed the payment vouchers list');

        // Init view
        const savedPVView = window.innerWidth <= 767 ? 'card' : (localStorage.getItem('pvView') || 'table');
        togglePVView(savedPVView);
        window.addEventListener('resize', function() { if (window.innerWidth <= 767) togglePVView('card'); });

        // Select2 on modal selects
        document.getElementById('voucherModal').addEventListener('shown.bs.modal', function() {
            $('#voucher_expense_account, #voucher_project').each(function() {
                var $el = $(this);
                if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
                $el.select2({ theme: 'bootstrap-5', dropdownParent: $('#voucherModal'), width: '100%', allowClear: true, placeholder: $el.find('option[value=""]').text() || 'Select...' });
            });
        });

        loadVouchers(1);
        
        let timeout = null;
        document.getElementById('searchInput').addEventListener('keyup', function() {
            clearTimeout(timeout);
            searchQuery = this.value;
            timeout = setTimeout(() => {
                currentPage = 1;
                loadVouchers(1);
            }, 500);
        });

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('project')) {
            resetVoucherForm();
            const projectSelect = document.querySelector('select[name="project_id"]');
            if (projectSelect) {
                projectSelect.value = urlParams.get('project');
                new bootstrap.Modal(document.getElementById('voucherModal')).show();
            }
        }
    });

    function loadVouchers(page) {
        currentPage = page;
        const limit = document.getElementById('filter_limit').value;
        const tbody = document.getElementById('vouchersTableBody');
        if(page === 1) tbody.innerHTML = '<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>';

        fetch(`<?= getUrl('api/account/get_vouchers.php') ?>?page=${page}&limit=${limit}&search=${encodeURIComponent(searchQuery)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderTable(data.vouchers);
                    renderPagination(data.pagination);
                    updateStats(data.stats);
                    document.getElementById('total_records_badge').innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> ${data.pagination.total_records} vouchers`;
                } else {
                    tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">${data.message}</td></tr>`;
                }
            });
    }

    function renderTable(vouchers) {
        const tbody = document.getElementById('vouchersTableBody');
        const cardGrid = document.getElementById('pvCardGrid');
        tbody.innerHTML = '';
        cardGrid.innerHTML = '';
        if (vouchers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-5 text-muted">No vouchers found.</td></tr>';
            cardGrid.innerHTML = '<div class="col-12 text-center py-5 text-muted">No vouchers found.</div>';
            return;
        }

        vouchers.forEach((v, index) => {
            const limit = parseInt(document.getElementById('filter_limit').value);
            const sn = (currentPage - 1) * limit + index + 1;
            const amount = new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS' }).format(v.amount).replace('TZS', '').trim();
            const dateStr = new Date(v.vouch_date).toLocaleDateString('en-GB');
            const statusBadge = v.status === 'paid' ? 'success' : (v.status === 'approved' ? 'info' : 'secondary');
            const jsonV = JSON.stringify(v).replace(/'/g, "&apos;").replace(/"/g, "&quot;");

            // Card view
            cardGrid.innerHTML += `
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="card h-100 border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 px-3">
                            <div>
                                <div class="fw-bold" style="font-size:0.85rem;">${v.payee_name}</div>
                                <small class="text-muted">${v.voucher_number}</small>
                            </div>
                            <span class="badge bg-${statusBadge}">${v.status.toUpperCase()}</span>
                        </div>
                        <div class="card-body py-2 px-3" style="font-size:0.8rem;">
                            <div class="mb-1"><i class="bi bi-calendar text-muted me-1"></i>${dateStr}</div>
                            <div class="mb-1 fw-bold"><i class="bi bi-cash text-muted me-1"></i>${amount}</div>
                            <div><i class="bi bi-credit-card text-muted me-1"></i>${v.payment_method.replace('_', ' ')}</div>
                        </div>
                        <div class="card-footer bg-white" style="padding:6px 8px;">
                            <div style="display:flex; flex-wrap:nowrap; gap:4px;">
                                <button class="btn btn-sm btn-outline-info" onclick='viewVoucherDetails(${jsonV})' title="View" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-eye"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick='printVoucher(${v.id})' title="Print" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-printer"></i></button>
                                <button class="btn btn-sm btn-outline-primary" onclick='editVoucher(${jsonV})' title="Edit" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteVoucher(${v.id})" title="Delete" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>`;

            tbody.innerHTML += `
                <tr>
                    <td class="ps-4 text-center text-muted small fw-bold">${sn}</td>
                    <td class="ps-4 fw-bold text-primary"><span class="custom-code">${v.voucher_number}</span></td>
                    <td>${dateStr}</td>
                    ${enableProjects ? `<td><span class="badge bg-light text-dark border">${v.project_name || '-'}</span></td>` : ''}
                    <td>${v.payee_name}</td>
                    <td class="fw-bold text-end">${amount}</td>
                    <td><span class="text-muted small text-uppercase">${v.payment_method.replace('_', ' ')}</span></td>
                    <td><span class="badge rounded-pill bg-${statusBadge} bg-opacity-10 text-${statusBadge} px-3 py-2 fw-bold">${v.status.toUpperCase()}</span></td>
                    <td class="text-end pe-4 d-print-none">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li>
                                    <a class="dropdown-item py-2" href="#" onclick='viewVoucherDetails(${jsonV}); return false;'>
                                        <i class="bi bi-eye me-2 text-info"></i> View Details
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item py-2" href="#" onclick='printVoucher(${v.id}); return false;'>
                                        <i class="bi bi-printer me-2 text-secondary"></i> Print Voucher
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider opacity-50"></li>
                                <li>
                                    <a class="dropdown-item py-2" href="#" onclick='editVoucher(${jsonV}); return false;'>
                                        <i class="bi bi-pencil me-2 text-primary"></i> Edit Voucher
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item py-2" href="#" onclick='openStatusManager(${jsonV}); return false;'>
                                        <i class="bi bi-arrow-repeat me-2 text-info"></i> Change Status
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider opacity-50"></li>
                                <li>
                                    <a class="dropdown-item py-2 text-danger" href="#" onclick="deleteVoucher(${v.id}); return false;">
                                        <i class="bi bi-trash me-2"></i> Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            `;
        });
    }
    
    function renderPagination(pg) {
        const nav = document.getElementById('pagination');
        nav.innerHTML = '';
        if (pg.total_pages <= 1) return;
        
        if(pg.current_page > 1) 
            nav.innerHTML += `<li class="page-item"><a class="page-link" href="#" onclick="loadVouchers(${pg.current_page - 1}); return false;">Prev</a></li>`;
            
        nav.innerHTML += `<li class="page-item active"><span class="page-link">${pg.current_page} / ${pg.total_pages}</span></li>`;
            
        if(pg.current_page < pg.total_pages) 
            nav.innerHTML += `<li class="page-item"><a class="page-link" href="#" onclick="loadVouchers(${pg.current_page + 1}); return false;">Next</a></li>`;
    }

    function updateStats(stats) {
        const fmt = (amt) => new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS' }).format(amt);
        document.getElementById('stat_paid').innerText = fmt(stats.total_paid);
        document.getElementById('stat_pending').innerText = stats.pending_approval;
        document.getElementById('stat_total').innerText = stats.total_vouchers;
    }

    function resetVoucherForm() {
        document.getElementById('voucherForm').reset();
        document.getElementById('voucher_id').value = 0;
        document.getElementById('voucherModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Create Payment Voucher';
        document.getElementById('submitBtn').innerHTML = '<i class="bi bi-save me-1"></i> Save Voucher';
    }

    function editVoucher(data) {
        document.getElementById('voucher_id').value = data.id;
        document.getElementById('voucher_date').value = data.vouch_date;
        document.getElementById('voucher_amount').value = data.amount;
        document.getElementById('voucher_payee').value = data.payee_name;
        document.getElementById('voucher_words').value = data.amount_in_words || '';
        document.getElementById('voucher_method').value = data.payment_method;
        document.getElementById('voucher_ref').value = data.reference_number || '';
        $('#voucher_expense_account').val(data.expense_account_id || '').trigger('change.select2');
        if (enableProjects && document.getElementById('voucher_project')) {
            document.getElementById('voucher_project').value = data.project_id || '';
        }
        document.getElementById('voucher_desc').value = data.description || '';

        document.getElementById('voucherModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Voucher ' + data.voucher_number;
        document.getElementById('submitBtn').innerHTML = '<i class="bi bi-check-circle me-1"></i> Update Voucher';
        
        new bootstrap.Modal(document.getElementById('voucherModal')).show();
    }

    document.getElementById('voucherForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const btn = document.getElementById('submitBtn');
        const original = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = 'Saving...';

        fetch('<?= getUrl('api/account/save_voucher.php') ?>', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                Swal.fire({ icon: 'success', title: 'Saved!', timer: 1500, showConfirmButton: false }).then(() => {
                    loadVouchers(currentPage);
                    bootstrap.Modal.getInstance(document.getElementById('voucherModal')).hide();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
                btn.disabled = false; btn.innerHTML = original;
            }
        });
    });

    function deleteVoucher(id) {
        Swal.fire({
            title: 'Delete Voucher?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Yes, delete'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('<?= getUrl('api/account/delete_voucher.php') ?>', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'id=' + id
                }).then(r => r.json()).then(data => {
                    if(data.success) { 
                        Swal.fire('Deleted!', '', 'success'); 
                        loadVouchers(currentPage); 
                    }
                    else Swal.fire('Error', data.message, 'error');
                });
            }
        });
    }

    function viewVoucherDetails(data) {
        const amount = new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS' }).format(data.amount);
        const dateStr = new Date(data.vouch_date).toLocaleDateString('en-GB', {day: 'numeric', month: 'long', year: 'numeric'});
        const statusBadge = data.status === 'paid' ? 'success' : (data.status === 'approved' ? 'info' : 'secondary');
        
        document.getElementById('detail_voucher_no').innerText = data.voucher_number;
        document.getElementById('detail_date').innerText = dateStr;
        document.getElementById('detail_status_badge').innerHTML = `<span class="badge rounded-pill bg-${statusBadge} text-uppercase px-3">${data.status}</span>`;
        document.getElementById('detail_method_badge').innerHTML = `<span class="badge rounded-pill bg-light text-dark border text-uppercase px-3">${data.payment_method.replace('_', ' ')}</span>`;
        document.getElementById('detail_payee').innerText = data.payee_name;
        document.getElementById('detail_category').innerText = data.expense_account_name || data.category_name || 'Uncategorized';
        document.getElementById('detail_amount').innerText = amount;
        document.getElementById('detail_amount').title = amount;
        document.getElementById('detail_words').innerText = data.amount_in_words ? 'In Words: ' + data.amount_in_words : '';
        document.getElementById('detail_reference').innerText = data.reference_number || 'None';
        document.getElementById('detail_description').innerText = data.description || 'No description provided';
        document.getElementById('detail_user').innerText = data.username || 'System Admin';
        
        if (enableProjects && document.getElementById('detail_project')) {
            document.getElementById('detail_project').innerText = data.project_name || 'N/A';
        }
        
        document.getElementById('detail_print_btn').onclick = () => printVoucher(data.id);
        
        new bootstrap.Modal(document.getElementById('detailsModal')).show();
    }

    function printVoucher(id) {
        const url = `<?= getUrl('payment_voucher_print') ?>?id=${id}`;
        window.open(url, '_blank').focus();
    }

    const VC_CASH_ACCOUNTS = <?= json_encode(array_map(fn($a) => [
        'id' => (int)$a['account_id'],
        'text' => $a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')
    ], cashBankAccounts($pdo))) ?>;

    function submitVoucherStatus(id, status, paidFrom) {
        let body = `id=${id}&status=${status}`;
        if (paidFrom) body += `&paid_from_account_id=${encodeURIComponent(paidFrom)}`;
        fetch('<?= getUrl('api/account/update_voucher_status.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Updated!', timer: 1500, showConfirmButton: false });
                loadVouchers(currentPage);
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        });
    }

    // Takes the full voucher object (v) so the Pay form can be pre-filled.
    function openStatusManager(v) {
        // 'paid' is labelled "Pay" — choosing it opens the proper Pay form.
        let options = { 'draft': 'Draft', 'approved': 'Approved', 'paid': 'Pay', 'cancelled': 'Cancelled' };
        Swal.fire({
            title: 'Change Voucher Status',
            input: 'select',
            inputOptions: options,
            inputValue: v.status,
            showCancelButton: true,
            confirmButtonText: 'Continue',
            confirmButtonColor: '#0d6efd'
        }).then((result) => {
            if (!result.isConfirmed) return;
            if (result.value === 'paid') {
                openPayVoucher(v);          // open the real Pay form (bank, date, ref, proof)
                return;
            }
            submitVoucherStatus(v.id, result.value, null);
        });
    }

    // Open the Pay form modal, pre-filled from the voucher.
    function openPayVoucher(v) {
        const fmt = n => 'TZS ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        $('#pay_voucher_id').val(v.id);
        $('#pay_voucher_no').text(v.voucher_number || ('#' + v.id));
        $('#pay_payee').text(v.payee_name || '—');
        $('#pay_amount').text(fmt(v.amount));
        $('#pay_reference').val(v.reference_number || '');
        $('#pay_date').val(new Date().toISOString().split('T')[0]);
        if (v.payment_method) $('#pay_method').val(v.payment_method);
        $('#pay_paid_from').val(v.paid_from_account_id || '').trigger('change.select2');
        new bootstrap.Modal(document.getElementById('payVoucherModal')).show();
    }

    // Submit the Pay form (FormData → supports the optional attachment).
    $(document).on('submit', '#payVoucherForm', function (e) {
        e.preventDefault();
        const $form = $(this);
        const btn = $form.find('[type="submit"]'); const orig = btn.html();
        if (!$('#pay_paid_from').val()) { Swal.fire('Required', 'Choose the Paid From account.', 'warning'); return; }
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Paying…');
        fetch('<?= getUrl('api/account/update_voucher_status.php') ?>', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('payVoucherModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Voucher Paid', timer: 1600, showConfirmButton: false });
                    loadVouchers(currentPage);
                } else {
                    Swal.fire('Error', data.message || 'Could not pay the voucher.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Server error.', 'error'))
            .finally(() => btn.prop('disabled', false).html(orig));
    });

    // Init Select2 on the Pay modal's Paid From when shown.
    $('#payVoucherModal').on('shown.bs.modal', function () {
        const $s = $('#pay_paid_from');
        if (!$s.hasClass('select2-hidden-accessible')) {
            $s.select2({ theme: 'bootstrap-5', dropdownParent: $('#payVoucherModal'), placeholder: 'Select cash/bank account…', allowClear: true, width: '100%' });
        }
    });

    function clearFilters() {
        document.getElementById('searchInput').value = '';
        searchQuery = '';
        loadVouchers(1);
    }

    function copyTable() {
        const table = document.getElementById('vouchersTable');
        const range = document.createRange();
        range.selectNode(table);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        document.execCommand('copy');
        window.getSelection().removeAllRanges();
        Swal.fire({ icon: 'success', title: 'Copied!', timer: 1000, showConfirmButton: false });
    }

    function printVouchers() {
        window.print();
    }

    function exportExcel() {
        const table = document.getElementById('vouchersTable');
        const rows = Array.from(table.querySelectorAll('tr'));
        const csvContent = rows.map(row => {
            const cols = Array.from(row.querySelectorAll('th, td')).slice(0, -1);
            return cols.map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
        }).join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', 'PaymentVouchers.csv');
        link.click();
    }

    document.getElementById('filterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        searchQuery = document.getElementById('searchInput').value;
        loadVouchers(1);
    });
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, .custom-stat-card small, .custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.stats-icon {
    width: 45px; height: 45px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; margin-right: 1.25rem;
    background: rgba(15, 81, 50, 0.1);
    color: #0f5132 !important;
}
.bg-success-soft { background-color: rgba(25, 135, 84, 0.1) !important; }
.custom-code {
    color: #0f5132 !important; background-color: #d1e7dd !important;
    padding: 2px 6px; border-radius: 6px; font-weight: bold;
}
@media (max-width: 767px) {
    .navbar { position: sticky; top: 0; z-index: 1020; }
}
@media print {
    .d-print-none, .btn, .card-header, .form-control, .form-select, .input-group, .pagination, footer, nav, .modal, .dropdown-menu, .alert { display: none !important; }
    .d-print-block { display: block !important; }
    body { background: white !important; padding: 0 !important; padding-top: 0 !important; margin: 0 !important; }
    .payment-vouchers-dashboard { padding: 20px !important; }
    table { width: 100% !important; border-collapse: collapse !important; border: 1px solid #333; }
    th, td { border: 1px solid #333 !important; padding: 8px !important; }

    /* Force Stats Cards to stay on one row in print */
    .row { display: flex !important; flex-wrap: nowrap !important; gap: 10px !important; }
    .col-md-4 { flex: 1 !important; width: 33.33% !important; margin-bottom: 0 !important; }
    .custom-stat-card { padding: 10px !important; border: 1px solid #badbcc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* Print header styling */
    #printHeader h1 {
        color: #0d6efd !important;
        text-transform: uppercase;
        font-weight: 800;
        margin: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    #printHeader h2 {
        color: #495057 !important;
        text-transform: uppercase;
        font-weight: 600;
        margin: 5px 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<?php
includeFooter();
ob_end_flush();
?>
